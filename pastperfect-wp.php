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
    wp_die( 'PastPerfect requires PHP 8.0 or higher. Please upgrade your PHP version.', 'PastPerfect PHP Requirement', array( 'response' => 500 ) );
	}

if ( !file_exists( ppwp_plugin_dir . 'autoload.php' ) ) {
    wp_die( 'Missing required file: autoload.php', 'PastPerfect Error', array( 'response' => 500 ) );
}
require ppwp_plugin_dir . 'autoload.php';

if ( !file_exists( ppwp_plugin_dir . 'load.php' ) ) {
    wp_die( 'Missing required file: load.php', 'PastPerfect Error', array( 'response' => 500 ) );
}
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

/**
 * Add quick links to the plugin row on the Plugins screen.
 *
 * @param array<int,string> $links Existing plugin action links.
 * @return array<int,string>
 */
function ppwp_plugin_action_links( array $links ): array {
	$import_url = add_query_arg(
		array(
			'post_type' => 'ppwp_record',
			'page' => 'pastperfect-import-records',
		),
		admin_url( 'edit.php' )
	);

	$setup_url = add_query_arg(
		array(
			'post_type' => 'ppwp_record',
			'page' => 'pastperfect-setup',
		),
		admin_url( 'edit.php' )
	);

	array_unshift(
		$links,
		sprintf( '<a href="%s">%s</a>', esc_url( $import_url ), esc_html__( 'Import', 'pastperfect-wp' ) ),
		sprintf( '<a href="%s">%s</a>', esc_url( $setup_url ), esc_html__( 'Setup', 'pastperfect-wp' ) )
	);

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ppwp_plugin_action_links' );
