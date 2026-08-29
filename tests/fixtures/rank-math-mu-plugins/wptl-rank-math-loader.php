<?php
/**
 * Load the mounted official Rank Math build before WP Title Layer.
 *
 * @package WPTitleLayer
 */

defined( 'ABSPATH' ) || exit;

require_once WP_PLUGIN_DIR . '/seo-by-rank-math/rank-math.php';
require_once WP_PLUGIN_DIR . '/wp-title-layer/wp-title-layer.php';
