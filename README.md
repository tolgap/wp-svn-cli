# WP SVN CLI
This WP_CLI tool allows you to update plugins without losing the .svn folders, because the current version of WP_CLI does not check for .svn folders, but just removes them with the old plugin folder.

## Installation
Move both files to

    /src/php/wp-cli/commands/community

## Usage
The `svn` command extends the `plugin` command, so it has the same subcommands e.g:

    wp svn [update|activate|deactivate|toggle|path|uninstall|delete|status|install]

But the only difference is in the `wp svn update` command. It uses a different `WP_Upgrader` that implements the `RecursiveDirectoryIterator` object that checks for SVN folders.