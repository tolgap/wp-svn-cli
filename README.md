# WP SVN CLI
This WP_CLI plugin allows you to update Wordpress with respect to .svn folders. This was needed, because the current version of WP_CLI does not check for .svn folders, but just removes them with the old plugin folder.

## Installation
Move the `svn.php` file to

    /src/php/wp-cli/commands/community

If you can't see the `svn` command show up when you run

    wp svn

I have added a `wp.sh` script, that you'll have to manually edit to match your wp-cli installation directory.
There is a line in the bash script, at line 36, where the script will point to. Change that directory to match yours.
The `wp.sh` file should be at this directory:

    /usr/bin/wp

## Usage

**You will need PHP>=5.2**

The `svn` command extends the `plugin` command, so it has the same subcommands e.g:

    wp svn [update|activate|deactivate|toggle|path|uninstall|delete|status|install]

But the only difference is in the `wp svn update` command. This is purely just for ease. It uses a different `WP_Upgrader` that implements the `RecursiveDirectoryIterator` object that checks for SVN folders.