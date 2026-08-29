<?php
/**
 * Optional integrations with tools that remain the canonical owner of their
 * own metadata.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Integrations;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	/** @var bool */
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		RankMath::register();
	}
}
