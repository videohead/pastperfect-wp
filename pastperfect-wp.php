<?php
/**
 * Plugin Name: Past Perfect Archive
 * Version: 0.1-alpha
 * Description: Manage and serve assets exported from PastPerfect.
 * Author: Boone Gorges | MatthewGalvin
 * Author URI: https://boone.gorg.es
 * Plugin URI: https://brooklynhistory.org
 * Text Domain: pastperfect-wp
 * Domain Path: /languages
 * @package pastperfect-wp
 */

define( 'ppwp_version', '0.3-alpha' );
define( 'ppwp_plugin_url', plugin_dir_url( __FILE__ ) );
define( 'ppwp_plugin_dir', plugin_dir_path( __FILE__ ) );

/**
 * Bootstraps the plugin.
 *
 * Performs a PHP version check, and then registers the autoloader and loads the application.
 *
 * @since 1.0.0
 */
function ppwp_bootstrap() {
	if ( version_compare( PHP_VERSION, '8.0', '<' ) && current_user_can( 'install_plugins' ) ) {
		add_action( 'admin_notices', 'ppwp_php_admin_notice' );
		return;
	}

	require ppwp_plugin_dir . 'autoload.php';
	require ppwp_plugin_dir . 'load.php';
}
add_action( 'plugins_loaded', 'ppwp_bootstrap' );

/**
 * Activation callback.
 */
function ppwp_activate() {
	if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
		return;
	}

	require_once ppwp_plugin_dir . 'autoload.php';
	\PastPerfect\Archive\MediaIndex::activate();
	\PastPerfect\Archive\SyncCoordinator::activate();
}
register_activation_hook( __FILE__, 'ppwp_activate' );

/**
 * Deactivation callback.
 */
function ppwp_deactivate() {
	if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
		return;
	}

	require_once ppwp_plugin_dir . 'autoload.php';
	\PastPerfect\Archive\SyncCoordinator::deactivate();
}
register_deactivation_hook( __FILE__, 'ppwp_deactivate' );

/**
 * Render a PHP compatibility notice.
 *
 * Meant to fire at 'admin_notices'.
 *
 * PHP 5.2 compatible.
 *
 * @since 1.0.0
 */
function ppwp_php_admin_notice() {
	?>
	<div class="notice notice-error is-dismissable">
		<p><?php esc_html_e( 'Past Perfect Archive requires PHP 8.0 or higher. Please contact your webhost.', 'pastperfect-wp' ); ?></p>
	</div>
	<?php
}
