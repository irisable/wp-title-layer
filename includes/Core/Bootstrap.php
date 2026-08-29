<?php
/**
 * Core bootstrap with an explicit migration-only boundary.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Core;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	private static $content_hooked = false;
	private static $runtime_hooked = false;

	/**
	 * Register Core.
	 *
	 * Passing false is migration-only mode: schema and taxonomy remain
	 * available for scanners, but constraints, REST validation, and archive
	 * ordering are not attached.
	 *
	 * @param bool|array<string,mixed> $editor_enabled Boolean, or an options
	 *        array containing editor_enabled/runtime_enabled.
	 */
	public static function register( $editor_enabled = true ): void {
		$runtime_enabled = is_array( $editor_enabled )
			? (bool) ( $editor_enabled['runtime_enabled'] ?? $editor_enabled['editor_enabled'] ?? true )
			: (bool) $editor_enabled;

		if ( ! self::$content_hooked ) {
			add_action( 'init', [ self::class, 'register_content_model' ], 5 );
			self::$content_hooked = true;
		}

		if ( $runtime_enabled ) {
			self::register_runtime_hooks();
		}
	}

	/**
	 * Register only taxonomy and metadata. Safe to call repeatedly and directly
	 * from activation/deactivation lifecycle code before flushing rewrites.
	 */
	public static function register_content_model(): void {
		Series::register_taxonomy();
		Meta::register();
	}

	public static function register_runtime_hooks(): void {
		if ( self::$runtime_hooked ) {
			return;
		}
		Series::register_runtime_hooks();
		SequenceRuntime::register();
		self::$runtime_hooked = true;
	}
}
