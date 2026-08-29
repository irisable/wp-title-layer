<?php
/**
 * Native and legacy shortcode compatibility.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Presentation;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {
	/** @var Renderer */
	private $renderer;

	public function __construct( ?Renderer $renderer = null ) {
		$this->renderer = $renderer ?: new Renderer();
	}

	public function register(): void {
		add_shortcode( 'wp_title_layer', array( $this, 'titleLayer' ) );
		add_shortcode( 'wptl_subtitle', array( $this, 'subtitle' ) );

		// Preserve content written for Secondary Title after that plugin is retired.
		// Do not replace its implementation while it remains active.
		if ( ! shortcode_exists( 'secondary_title' ) ) {
			add_shortcode( 'secondary_title', array( $this, 'legacySecondaryTitle' ) );
		}
	}

	/**
	 * [wp_title_layer post_id="" preset="" heading_tag="h1" class=""]
	 *
	 * @param array<string,mixed>|string $attributes Attributes.
	 * @return string
	 */
	public function titleLayer( $attributes ): string {
		$attributes = shortcode_atts(
			array(
				'post_id'     => 0,
				'preset'      => '',
				'heading_tag' => 'h1',
				'class'       => '',
			),
			is_array( $attributes ) ? $attributes : array(),
			'wp_title_layer'
		);
		$post_id = absint( $attributes['post_id'] );
		$post_id = $post_id > 0 ? $post_id : (int) get_the_ID();
		AutoDisplay::markManualPlacement( $post_id );
		if ( ThemeTitleIntegration::wasReplaced( $post_id ) || ThemeTitleIntegration::ownsFullLayerPlacement( $post_id ) ) {
			return '';
		}

		$html = $this->renderer->render(
			$post_id,
			array(
				'preset'      => sanitize_key( (string) $attributes['preset'] ),
				'heading_tag' => sanitize_key( (string) $attributes['heading_tag'] ),
				'class_name'  => (string) $attributes['class'],
			)
		);
		if ( '' !== trim( $html ) ) {
			AutoDisplay::markFullLayerRendered( $post_id );
		}

		return $html;
	}

	/**
	 * [wptl_subtitle post_id=""]
	 *
	 * @param array<string,mixed>|string $attributes Attributes.
	 * @return string
	 */
	public function subtitle( $attributes ): string {
		$attributes = shortcode_atts(
			array( 'post_id' => 0 ),
			is_array( $attributes ) ? $attributes : array(),
			'wptl_subtitle'
		);
		AutoDisplay::markManualPlacement( absint( $attributes['post_id'] ) );

		return esc_html( $this->renderer->value( 'subtitle', absint( $attributes['post_id'] ) ) );
	}

	/**
	 * Compatibility implementation for [secondary_title].
	 *
	 * Unlike the old plugin, source metadata cannot inject arbitrary HTML. The
	 * `allow_html=true` attribute retains harmless inline markup only when an
	 * extension supplies it through `wptl_legacy_secondary_title_value`.
	 *
	 * @param array<string,mixed>|string $attributes Attributes.
	 * @return string
	 */
	public function legacySecondaryTitle( $attributes ): string {
		$attributes = shortcode_atts(
			array(
				'post_id'   => 0,
				'allow_html'=> 'false',
			),
			is_array( $attributes ) ? $attributes : array(),
			'secondary_title'
		);
		$post_id = absint( $attributes['post_id'] );
		AutoDisplay::markManualPlacement( $post_id );
		$value   = $this->renderer->value( 'subtitle', $post_id );

		/**
		 * Filter legacy shortcode value before escaping.
		 *
		 * @param string $value   Subtitle value.
		 * @param int    $post_id Post ID, or zero for current post.
		 */
		$value = (string) apply_filters( 'wptl_legacy_secondary_title_value', $value, $post_id );
		$value = (string) apply_filters( 'secondary_title_shortcode', $value );

		if ( 'true' === strtolower( (string) $attributes['allow_html'] ) ) {
			return wp_kses(
				$value,
				array(
					'span'   => array( 'class' => true ),
					'small'  => array( 'class' => true ),
					'strong' => array( 'class' => true ),
					'em'     => array( 'class' => true ),
					'br'     => array(),
				)
			);
		}

		return esc_html( $value );
	}
}
