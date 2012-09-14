<?php

WP_CLI::add_command('svn', 'SVN_Command');
require_once WP_CLI_ROOT . 'commands/internals/plugin.php';
require 'class-wp-upgrader-tolga.php';

/**
 * Implement Wordpress upgrader with respect to .svn folders
 *
 * @package wp-cli
 * @subpackage commands/community
 * @maintainer Tolga Paksoy (https://github.com/tolgap)
 */
class SVN_Command extends Plugin_Command
{

	protected $item_type = 'plugin';
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

				$upgrader = new WP_Upgrader_Tolga();
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
		$upgrader = WP_CLI::get_upgrader( $this->upgrader );
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
}