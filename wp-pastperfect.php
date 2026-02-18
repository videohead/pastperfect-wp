<?php
/**
 * Plugin Name: WordPress PastPerfect - based on BHS Storehouse
 * Version: 0.2.7
 * Description: Manage and serve assets exported from PastPerfect.
 * Author: Matthew Galvin - original Author Boone Gorges
 * Author URI: https://matthewgalvin.com
 * Plugin URI: https://brooklynhistory.org
 * Text Domain: wp-pastperfect
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * @package wp-pastperfect
 */

define( 'WPPP_VERSION', '0.2.9' );
define( 'WPPP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bootstraps the plugin.
 *
 * Performs a PHP version check, and then registers the autoloader and loads the application.
 *
 * @since 1.0.0
 */
function wppp_bootstrap() {
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		add_action( 'admin_notices', 'wppp_php_admin_notice' );
		return;
	}

	require __DIR__ . '/autoload.php';
	require __DIR__ . '/load.php';
}
add_action( 'plugins_loaded', 'wppp_bootstrap' );

/**
 * Render a PHP compatibility notice.
 *
 * Meant to fire at 'admin_notices'.
 *
 * @since 1.0.0
 */
function wppp_php_admin_notice() {
	?>
	<div class="notice notice-error is-dismissible">
		<p><?php esc_html_e( 'WordPress PastPerfect requires PHP 7.4 or higher. Please contact your webhost to upgrade PHP.', 'wp-pastperfect' ); ?></p>
	</div>
	<?php
}
