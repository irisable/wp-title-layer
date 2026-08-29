<?php
/**
 * Force the mounted Kadence fixture to load before the plugin smoke test.
 *
 * @package WPTitleLayer
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_option_template',
	static function (): string {
		return 'kadence';
	}
);
add_filter(
	'pre_option_stylesheet',
	static function (): string {
		return 'kadence';
	}
);

require_once WP_PLUGIN_DIR . '/wp-title-layer/wp-title-layer.php';
