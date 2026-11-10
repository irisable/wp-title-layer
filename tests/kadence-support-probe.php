<?php
/** Isolated support probe: run each version in a fresh PHP process. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'KADENCE_VERSION', $argv[1] );
function wp_is_block_theme() { return false; }
function get_template() { return 'kadence'; }
function get_stylesheet() { return isset( $GLOBALS['argv'][3] ) ? 'child' : 'kadence'; }
function get_template_directory() { return $GLOBALS['argv'][2]; }
function get_stylesheet_directory() { return $GLOBALS['argv'][3]; }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function __( $value, $domain = '' ) { return $value; }
function apply_filters( $name, $value ) { return $value; }
function sanitize_text_field( $value ) { return $value; }
function sanitize_key( $value ) { return $value; }
require dirname( __DIR__ ) . '/includes/Presentation/ThemeTitleIntegration.php';
echo json_encode( \WPTitleLayer\Presentation\ThemeTitleIntegration::support() );
