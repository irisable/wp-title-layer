<?php
/**
 * Selected Secondary Title compatibility functions.
 *
 * @package WPTitleLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'get_secondary_title' ) ) {
	/**
	 * Return the WP Title Layer subtitle with legacy-compatible affixes.
	 *
	 * @param int    $post_id      Post ID. Defaults to the current post.
	 * @param string $prefix       Optional prefix.
	 * @param string $suffix       Optional suffix.
	 * @param bool   $use_settings Retained for call compatibility.
	 * @return string
	 */
	function get_secondary_title( $post_id = 0, $prefix = '', $suffix = '', $use_settings = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return \WPTitleLayer\Presentation\Compatibility::getSecondaryTitle(
			$post_id,
			(string) $prefix,
			(string) $suffix,
			(bool) $use_settings
		);
	}
}

if ( ! function_exists( 'the_secondary_title' ) ) {
	/**
	 * Echo the compatibility subtitle.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $prefix       Optional prefix.
	 * @param string $suffix       Optional suffix.
	 * @param bool   $use_settings Retained for compatibility.
	 * @return void
	 */
	function the_secondary_title( $post_id = 0, $prefix = '', $suffix = '', $use_settings = false ) {
		$value = get_secondary_title( $post_id, $prefix, $suffix, $use_settings );
		echo wp_kses_post( apply_filters( 'the_secondary_title', $value, $post_id, $prefix, $suffix ) );
	}
}

if ( ! function_exists( 'has_secondary_title' ) ) {
	/**
	 * Check whether a post has a subtitle in either canonical or legacy data.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	function has_secondary_title( $post_id = 0 ) {
		return \WPTitleLayer\Presentation\Compatibility::hasSecondaryTitle( absint( $post_id ) );
	}
}
