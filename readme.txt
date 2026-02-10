=== BHS Storehouse (PastPerfect WP) ===
Contributors: boonebgorges 
Donate link: http://brooklynhistory.org
Tags: pastperfect, archive, museum, collection management
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: wp-pastperfect
Domain Path: /languages

Manage and serve assets exported from PastPerfect collection management system.

== Description ==

BHS Storehouse allows you to import and manage PastPerfect records in WordPress, providing a web-based interface for your museum or archive collections. The plugin supports Dublin Core metadata fields and provides REST API endpoints for accessing record data.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/wp-pastperfect` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Use the PastPerfect Records -> Import screen to upload your XML export files from PastPerfect.

== Changelog ==

= 0.2.0 =
* Updated for WordPress 6.9 compatibility
* Changed text domain from 'bhs-storehouse' to 'wp-pastperfect'
* Added REST API support with proper permission callbacks
* Modernized post type and taxonomy registration
* Added Gutenberg/Block Editor support
* Updated PHP minimum requirement to 7.4
* Fixed deprecated date() function usage
* Improved security with proper sanitization and nonce verification
* Enhanced input validation across all endpoints
* Fixed coding standards compliance

= 0.1-alpha =
* Initial release
