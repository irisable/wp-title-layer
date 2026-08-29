<?php
/**
 * Class-based compatibility API for integration by the main plugin.
 *
 * No global functions are declared here. Bootstrap code may define legacy
 * wrappers only when the old plugin is inactive and then delegate to this API.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Presentation;

defined( 'ABSPATH' ) || exit;

final class Compatibility {
	/** @var Renderer|null */
	private static $renderer;

	public static function getSecondaryTitle( $post_id = 0, string $prefix = '', string $suffix = '', bool $use_settings = false ): string {
		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			$post_id = (int) get_the_ID();
		}
		$value   = self::renderer()->value( 'subtitle', $post_id );

		// Secondary Title returned an empty string before applying affixes when no
		// value existed. Retain that behavior so wrappers never create phantom text.
		if ( '' === $value ) {
			return '';
		}

		if ( $use_settings && ! self::passesLegacyDisplayRules( $post_id ) ) {
			return '';
		}

		$value = $prefix . $value . $suffix;

		/**
		 * Mirror the old filter signature for themes that used it.
		 *
		 * @param string $value   Secondary title.
		 * @param int    $post_id Post ID.
		 * @param string $prefix  Prefix.
		 * @param string $suffix  Suffix.
		 */
		return (string) apply_filters( 'get_secondary_title', $value, $post_id, $prefix, $suffix );
	}

	public static function hasSecondaryTitle( int $post_id = 0 ): bool {
		return '' !== self::getSecondaryTitle( $post_id );
	}

	public static function render( int $post_id = 0, array $args = array() ): string {
		return self::renderer()->render( $post_id, $args );
	}

	/**
	 * Preserve Secondary Title's optional post type/category/exclusion rules.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function passesLegacyDisplayRules( int $post_id ): bool {
		$post_types = get_option( 'secondary_title_post_types', array() );
		$categories = get_option( 'secondary_title_categories', array() );
		$excluded   = get_option( 'secondary_title_post_ids', array() );
		$post_types = is_array( $post_types ) ? $post_types : array();
		$categories = is_array( $categories ) ? $categories : array();
		$excluded   = is_array( $excluded ) ? $excluded : array();

		if ( $post_types && ! in_array( get_post_type( $post_id ), $post_types, false ) ) { // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
			return false;
		}

		if ( $categories && ! array_intersect( array_map( 'intval', $categories ), wp_get_post_categories( $post_id ) ) ) {
			return false;
		}

		return ! in_array( $post_id, array_map( 'intval', $excluded ), true );
	}

	private static function renderer(): Renderer {
		if ( ! self::$renderer instanceof Renderer ) {
			self::$renderer = new Renderer();
		}

		return self::$renderer;
	}
}
