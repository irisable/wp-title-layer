<?php
/**
 * Activation and deactivation lifecycle.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer;

use WPTitleLayer\Core\Bootstrap as Core_Bootstrap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles reversible plugin lifecycle operations.
 */
final class Lifecycle {
	/**
	 * Register rewrites and record the installed schema version.
	 *
	 * @return void
	 */
	public static function activate() {
		Core_Bootstrap::register_content_model();
		update_option( 'wptl_version', WPTL_VERSION, false );
		flush_rewrite_rules();
	}

	/**
	 * Flush rewrites. Content and settings are deliberately preserved.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
