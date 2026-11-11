<?php
/**
 * Plugin Name:       Irisable Title Layer
 * Plugin URI:        https://github.com/irisable/wp-title-layer
 * Description:       Structured subtitles, series, and context-aware title templates for WordPress.
 * Version:           1.0.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Irisable
 * Author URI:        https://irisable.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       irisable-title-layer
 * Domain Path:       /languages
 *
 * @package WPTitleLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Directory installs have a new folder, but must never run beside the legacy copy.
$wptl_legacy_basename = 'wp-title-layer/wp-title-layer.php';
$wptl_active_plugins = (array) get_option( 'active_plugins', array() );
$wptl_network_plugins = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
if ( defined( 'WPTL_FILE' ) || (
	plugin_basename( __FILE__ ) !== $wptl_legacy_basename
	&& ( in_array( $wptl_legacy_basename, $wptl_active_plugins, true ) || isset( $wptl_network_plugins[ $wptl_legacy_basename ] ) )
) ) {
	add_action( 'admin_notices', static function () {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Deactivate the existing WP Title Layer copy before activating Irisable Title Layer. Your titles, Series and settings are preserved.', 'irisable-title-layer' ) . '</p></div>';
	} );
	register_activation_hook( __FILE__, static function () {
		wp_die( esc_html__( 'Deactivate the existing WP Title Layer copy before activating Irisable Title Layer. Your titles, Series and settings are preserved.', 'irisable-title-layer' ), '', array( 'back_link' => true ) );
	} );
	return;
}
unset( $wptl_legacy_basename, $wptl_active_plugins, $wptl_network_plugins );

define( 'WPTL_VERSION', '1.0.1' );
define( 'WPTL_FILE', __FILE__ );
define( 'WPTL_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPTL_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load plugin classes without requiring Composer in production.
 *
 * @param string $class Fully qualified class name.
 * @return void
 */
spl_autoload_register( static function ( $class ) {
	$prefix = 'WPTitleLayer\\';

	if ( 0 !== strpos( $class, $prefix ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $relative ) ) {
		return;
	}

	$base = realpath( WPTL_PATH . 'includes' );
	$file = realpath( WPTL_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php' );

	if ( $base && $file && 0 === strpos( $file, $base . DIRECTORY_SEPARATOR ) && is_readable( $file ) ) {
		require_once $file;
	}
} );

register_activation_hook( __FILE__, array( 'WPTitleLayer\\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPTitleLayer\\Lifecycle', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'WPTitleLayer\\Plugin', 'register' ), 20 );
