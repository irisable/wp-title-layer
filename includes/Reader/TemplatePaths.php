<?php
/**
 * Fixed-root validation for Reader PHP templates.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Reader;

defined( 'ABSPATH' ) || exit;

final class TemplatePaths {
	public static function resolve( string $candidate, string $fallback = '' ): string {
		$validated = self::validate( $candidate );
		if ( '' !== $validated ) {
			return $validated;
		}

		return '' !== $fallback ? self::validate( $fallback ) : '';
	}

	public static function validate( string $candidate ): string {
		if ( '' === $candidate || 'php' !== strtolower( (string) pathinfo( $candidate, PATHINFO_EXTENSION ) ) ) {
			return '';
		}

		$file = realpath( $candidate );
		if ( false === $file || ! is_file( $file ) || ! is_readable( $file ) ) {
			return '';
		}

		foreach ( self::allowedRoots() as $root ) {
			if ( 0 === strpos( $file, $root . DIRECTORY_SEPARATOR ) ) {
				return $file;
			}
		}

		return '';
	}

	/** @return string[] */
	private static function allowedRoots(): array {
		$roots = array( dirname( __DIR__, 2 ) . '/templates' );
		if ( function_exists( 'get_stylesheet_directory' ) ) {
			$roots[] = get_stylesheet_directory();
		}
		if ( function_exists( 'get_template_directory' ) ) {
			$roots[] = get_template_directory();
		}

		$valid = array();
		foreach ( $roots as $root ) {
			$real = realpath( $root );
			if ( false !== $real && is_dir( $real ) ) {
				$valid[] = rtrim( $real, DIRECTORY_SEPARATOR );
			}
		}

		return array_values( array_unique( $valid ) );
	}
}
