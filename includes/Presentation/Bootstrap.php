<?php
/**
 * Presentation module bootstrap.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Presentation;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	/** @var bool */
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		SettingsPage::register();

		add_action( 'init', array( __CLASS__, 'registerRuntime' ), 20 );
		add_action( 'init', array( EditorAssets::class, 'register' ), 15 );
		add_action( 'enqueue_block_editor_assets', array( EditorAssets::class, 'enqueueBlockEditorAssets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueueFrontendStyle' ) );
	}

	public static function registerRuntime(): void {
		$renderer = new Renderer();
		( new Shortcodes( $renderer ) )->register();
		( new DynamicBlock( $renderer ) )->register();
		( new AutoDisplay( $renderer ) )->register();
		( new ThemeTitleIntegration( $renderer ) )->register();
	}

	public static function enqueueFrontendStyle(): void {
		// The same renderer may be used by blocks, shortcodes, theme functions,
		// archives, or singular templates. The stylesheet is intentionally tiny.
		wp_enqueue_style( EditorAssets::FRONT_HANDLE );
	}
}
