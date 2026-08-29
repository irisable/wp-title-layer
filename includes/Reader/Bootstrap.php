<?php
/**
 * Reader module bootstrap.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Reader;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	/** @var bool */
	private static $registered = false;

	/**
	 * Register Reader hooks. The caller should invoke this only in normal mode,
	 * after Core has registered the Series content model.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		AdminSettings::register();
		Assets::register();
		TemplateLoader::register();
		ThemeArchiveIntegration::register();
		( new Archive() )->register();

		add_action( 'init', array( __CLASS__, 'registerRuntime' ), 25 );
	}

	public static function registerRuntime(): void {
		( new Shortcodes() )->register();
		( new ContentAppender() )->register();
	}
}
