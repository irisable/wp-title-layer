<?php
/**
 * Reader shortcodes.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Reader;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {
	/** @var Renderer */
	private $renderer;

	public function __construct( ?Renderer $renderer = null ) {
		$this->renderer = $renderer ?: new Renderer();
	}

	public function register(): void {
		if ( Settings::shortcodeEnabled() ) {
			add_shortcode( 'wptl_series_navigation', array( $this, 'navigation' ) );
		}
	}

	/**
	 * [wptl_series_navigation post_id=""]
	 *
	 * Explicit IDs remain subject to Navigation's public-read check.
	 *
	 * @param array<string,mixed>|string $attributes Shortcode attributes.
	 */
	public function navigation( $attributes ): string {
		$attributes = shortcode_atts(
			array( 'post_id' => 0 ),
			is_array( $attributes ) ? $attributes : array(),
			'wptl_series_navigation'
		);

		return $this->renderer->render( absint( $attributes['post_id'] ) );
	}
}
