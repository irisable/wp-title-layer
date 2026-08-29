<?php
/**
 * Plugin Name:       WP Title Layer
 * Plugin URI:        https://github.com/irisable/wp-title-layer
 * Description:       Structured subtitles, series, and context-aware title templates for WordPress.
 * Version:           1.0.0-rc.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Irisable
 * Author URI:        https://irisable.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       wp-title-layer
 * Domain Path:       /languages
 * Update URI:        false
 *
 * @package WPTitleLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPTL_VERSION', '1.0.0-rc.1' );
define( 'WPTL_FILE', __FILE__ );
define( 'WPTL_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPTL_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load plugin classes without requiring Composer in production.
 *
 * @param string $class Fully qualified class name.
 * @return void
 */
function wptl_autoload( $class ) {
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
}

spl_autoload_register( 'wptl_autoload' );

register_activation_hook( __FILE__, array( 'WPTitleLayer\\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPTitleLayer\\Lifecycle', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'WPTitleLayer\\Plugin', 'register' ), 20 );
