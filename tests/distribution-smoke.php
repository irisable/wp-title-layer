<?php
/** Verify the new directory identity without changing the content contract. */
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$candidate = WP_PLUGIN_DIR . '/irisable-title-layer/wp-title-layer.php';
$checks = 0;
$assert = static function ( $value, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $value ) { throw new RuntimeException( $message ); }
};

update_option( 'active_plugins', array( 'wp-title-layer/wp-title-layer.php' ) );
include $candidate;
$assert( ! defined( 'WPTL_FILE' ), 'Candidate loaded beside an active legacy copy.' );
$assert( has_action( 'activate_irisable-title-layer/wp-title-layer.php' ), 'Missing activation guard.' );
ob_start(); do_action( 'admin_notices' ); $notice = ob_get_clean();
$assert( false !== strpos( $notice, 'Deactivate the existing WP Title Layer' ), 'Missing coexistence notice.' );

update_option( 'active_plugins', array() );
remove_all_actions( 'admin_notices' );
remove_all_actions( 'activate_irisable-title-layer/wp-title-layer.php' );
$post_id = wp_insert_post( array( 'post_title' => 'Preserved title', 'post_status' => 'draft' ) );
update_post_meta( $post_id, 'wptl_subtitle', 'Preserved subtitle' );
update_option( 'wptl_settings', array( 'default_template' => 'stacked' ) );
include $candidate;
$assert( defined( 'WPTL_FILE' ) && WPTL_FILE === $candidate, 'Candidate did not load from new directory.' );
include $candidate;
$assert( WPTL_FILE === $candidate, 'Repeated load changed ownership.' );
$assert( get_post_meta( $post_id, 'wptl_subtitle', true ) === 'Preserved subtitle', 'Existing subtitle changed.' );
$assert( get_option( 'wptl_settings' ) === array( 'default_template' => 'stacked' ), 'Existing option changed.' );
$assert( class_exists( 'WPTitleLayer\\Core\\Schema' ), 'Namespace autoload changed.' );
$assert( WPTitleLayer\Presentation\DynamicBlock::NAME === 'wp-title-layer/title-layer', 'Existing block identity changed.' );
echo "Distribution checks passed: {$checks}\n";
