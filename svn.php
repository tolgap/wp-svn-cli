<?php

WP_CLI::add_command('svn', 'Svn_Command');

require_once WP_CLI_ROOT . 'commands/internals/plugin.php';

/**
 * Implement Wordpress upgrader with respect to .svn folders
 *
 * @package wp-cli
 * @subpackage commands/community
 * @maintainer Tolga Paksoy (https://github.com/tolgap)
 */
class Svn_Command extends Plugin_Command
{

    protected $item_type = 'plugin';
    protected $upgrader = 'WP_Upgrader_SVN';
    protected $upgrade_refresh = 'wp_update_plugins';
    protected $upgrade_transient = 'update_plugins';

    /**
     * Update a plugin (to the latest dev version)
     *
     * @param array $args
     * @param array $assoc_args
     */
    function update( $args, $assoc_args )
    {
        if ( isset( $assoc_args['version'] ) && 'dev' == $assoc_args['version'] ) {
            $this->delete( $args, array(), false );
            $this->install( $args, $assoc_args );
        } else {
            call_user_func( $this->upgrade_refresh );

            if ( !empty( $args ) && !isset( $assoc_args['all'] ) ) {
                list( $file, $name ) = $this->parse_name( $args, __FUNCTION__ );

                $upgrader = SVN_Command::get_upgrader($this->upgrader);
                $upgrader->upgrade( $file );
            } else {
                $this->update_multiple( $args, $assoc_args );
            }
        }
    }

    /**
     * @see WP_CLI_Command_With_Upgrade::update_multiple()
     */
    private function update_multiple( $args, $assoc_args ) {
        // Grab all items that need updates
        // If we have no sub-arguments, add them to the output list.
        $item_list = "Available {$this->item_type} updates:";
        $items_to_update = array();
        foreach ( $this->get_item_list() as $file ) {
            if ( $this->get_update_status( $file ) ) {
                $items_to_update[] = $file;

                if ( empty( $assoc_args ) ) {
                    if ( false === strpos( $file, '/' ) )
                        $name = str_replace('.php', '', basename($file));
                    else
                        $name = dirname($file);

                    $item_list .= "\n\t%y$name%n";
                }
            }
        }

        if ( empty( $items_to_update ) ) {
            WP_CLI::line( "No {$this->item_type} updates available." );
            return;
        }

        // If --all, UPDATE ALL THE THINGS
        if ( isset( $assoc_args['all'] ) ) {
            $upgrader = SVN_Command::get_upgrader( $this->upgrader );
            $result = $upgrader->bulk_upgrade( $items_to_update );

            // Let the user know the results.
            $num_to_update = count( $items_to_update );
            $num_updated = count( array_filter( $result ) );

            $line = "Updated $num_updated/$num_to_update {$this->item_type}s.";
            if ( $num_to_update == $num_updated ) {
                WP_CLI::success( $line );
            } else if ( $num_updated > 0 ) {
                WP_CLI::warning( $line );
            } else {
                WP_CLI::error( $line );
            }

        // Else list items that require updates
        } else {
            WP_CLI::line( $item_list );
        }
    }

    static function get_upgrader($class) {
        require WP_CLI_ROOT . '/class-cli-upgrader-skin.php';

        return new $class( new CLI_Upgrader_Skin );
    }
}

if(!class_exists(Plugin_Upgrader)) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    class WP_Upgrader_SVN extends Plugin_Upgrader
    {

        var $strings = array();
        var $skin = null;
        var $result = array();

        function __construct($skin = null) {
            if ( null == $skin )
                $this->skin = new Bulk_Upgrader_Skin();
            else
                $this->skin = $skin;
        }

        function install_package($args = array()) {
            global $wp_filesystem;
            $defaults = array( 'source' => '', 'destination' => '', //Please always pass these
                            'clear_destination' => false, 'clear_working' => false,
                            'hook_extra' => array());

            $args = wp_parse_args($args, $defaults);
            extract($args);

            @set_time_limit( 300 );

            if ( empty($source) || empty($destination) )
                return new WP_Error('bad_request', $this->strings['bad_request']);

            $this->skin->feedback('installing_package');

            $res = apply_filters('upgrader_pre_install', true, $hook_extra);
            if ( is_wp_error($res) )
                return $res;

            //Retain the Original source and destinations
            $remote_source = $source;
            $local_destination = $destination;

            $source_files = array_keys( $wp_filesystem->dirlist($remote_source) );
            $remote_destination = $wp_filesystem->find_folder($local_destination);

            //Locate which directory to copy to the new folder, This is based on the actual folder holding the files.
            if ( 1 == count($source_files) && $wp_filesystem->is_dir( trailingslashit($source) . $source_files[0] . '/') ) //Only one folder? Then we want its contents.
                $source = trailingslashit($source) . trailingslashit($source_files[0]);
            elseif ( count($source_files) == 0 )
                return new WP_Error( 'incompatible_archive', $this->strings['incompatible_archive'], __( 'The plugin contains no files.' ) ); //There are no files?
            else //Its only a single file, The upgrader will use the foldername of this file as the destination folder. foldername is based on zip filename.
                $source = trailingslashit($source);

            //Hook ability to change the source file location..
            $source = apply_filters('upgrader_source_selection', $source, $remote_source, $this);
            if ( is_wp_error($source) )
                return $source;

            //Has the source location changed? If so, we need a new source_files list.
            if ( $source !== $remote_source )
                $source_files = array_keys( $wp_filesystem->dirlist($source) );

            //Protection against deleting files in any important base directories.
            if ( in_array( $destination, array(ABSPATH, WP_CONTENT_DIR, WP_PLUGIN_DIR, WP_CONTENT_DIR . '/themes') ) ) {
                $remote_destination = trailingslashit($remote_destination) . trailingslashit(basename($source));
                $destination = trailingslashit($destination) . trailingslashit(basename($source));
            }

            //needed for SVN folders
            $newdir_array=null;
            $newrecursive_array=null;
            $olddir_array=null;
            if ( $clear_destination ) {
                //We're going to clear the destination if there's something there
                $this->skin->feedback('remove_old');
                $removed = true;
                if ( $wp_filesystem->exists($remote_destination) )

                    //Check if .svn folder exists in plugin folder
                    if($wp_filesystem->exists($remote_destination . "/.svn")) {
                        echo 'Found .svn folder. Checking for recursion...' . PHP_EOL;
                        $iterator = new RecursiveDirectoryIterator($remote_destination);
                        $tmp_directory = 'pluginold/';
                        $newdir_array = array();
                        $newrecursive_array = array();
                        $olddir_array = array();
                        foreach(new RecursiveIteratorIterator($iterator) as $file) {
                            $str = substr($file, -5);
                            if($str == "svn/.") {
                                $dir = getcwd().'/wp-content/plugins/'.$tmp_directory;
                                array_push($newdir_array, ($dir . (substr($file, strlen($remote_destination), -2))));
                                array_push($newrecursive_array, ($dir . (substr($file, strlen($remote_destination), -7))));
                                array_push($olddir_array, substr($file, 0, -1));
                            }
                        }

                        //sort the arrays by string length so pluginold/ folder is created first
                        usort($newrecursive_array, function($a, $b) {
                            return strlen($a) - strlen($b);
                        });
                        usort($newdir_array, function($a, $b) {
                            return strlen($a) - strlen($b);
                        });

                        //create folders and move .svn folders into them.
                        for($i=0; $i<sizeof($newdir_array); $i++) {
                            $wp_filesystem->mkdir($newrecursive_array[$i], FS_CHMOD_DIR);
                            $command2 = 'mv '.$olddir_array[$i].' '.$newdir_array[$i];
                            WP_CLI::launch($command2, false);
                        }
                    }
                    $removed = $wp_filesystem->delete($remote_destination, true);
                $removed = apply_filters('upgrader_clear_destination', $removed, $local_destination, $remote_destination, $hook_extra);

                if ( is_wp_error($removed) )
                    return $removed;
                else if ( ! $removed )
                    return new WP_Error('remove_old_failed', $this->strings['remove_old_failed']);
            } elseif ( $wp_filesystem->exists($remote_destination) ) {
                //If we're not clearing the destination folder and something exists there already, Bail.
                //But first check to see if there are actually any files in the folder.
                $_files = $wp_filesystem->dirlist($remote_destination);
                if ( ! empty($_files) ) {
                    $wp_filesystem->delete($remote_source, true); //Clear out the source files.
                    return new WP_Error('folder_exists', $this->strings['folder_exists'], $remote_destination );
                }
            }

            //Create destination if needed
            if ( !$wp_filesystem->exists($remote_destination) )
                if ( !$wp_filesystem->mkdir($remote_destination, FS_CHMOD_DIR) )
                    return new WP_Error('mkdir_failed', $this->strings['mkdir_failed'], $remote_destination);

            // Copy new version of item into place.
            $result = copy_dir($source, $remote_destination);
            if ( is_wp_error($result) ) {
                if ( $clear_working )
                    $wp_filesystem->delete($remote_source, true);
                return $result;
            }

            /*PUT SVN FOLDERS BACK */
            for($i=0; $i<sizeof($newdir_array); $i++) {
                $command3 = 'mv '.$newdir_array[$i].' '.$olddir_array[$i];
                WP_CLI::launch($command3, false);
            }

            //Clear the Working folder?
            if ( $clear_working )
                $wp_filesystem->delete($remote_source, true);

            $destination_name = basename( str_replace($local_destination, '', $destination) );
            if ( '.' == $destination_name )
                $destination_name = '';

            $this->result = compact('local_source', 'source', 'source_name', 'source_files', 'destination', 'destination_name', 'local_destination', 'remote_destination', 'clear_destination', 'delete_source_dir');

            $res = apply_filters('upgrader_post_install', true, $hook_extra, $this->result);
            if ( is_wp_error($res) ) {
                $this->result = $res;
                return $res;
            }

            //REMOVE pluginold FOLDER
            $wp_filesystem->delete(getcwd().'/wp-content/plugins/pluginold/', true);

            //Bombard the calling function will all the info which we've just used.
            return $this->result;
        }
    }
}