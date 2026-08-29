<?php
/**
 * Dynamic title-layer block.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Presentation;

defined( 'ABSPATH' ) || exit;

final class DynamicBlock {
	public const NAME = 'wp-title-layer/title-layer';

	/** @var Renderer */
	private $renderer;

	public function __construct( ?Renderer $renderer = null ) {
		$this->renderer = $renderer ?: new Renderer();
	}

	public function register(): void {
		register_block_type(
			self::NAME,
			array(
				'api_version'     => 2,
				'editor_script'   => 'wptl-editor',
				'editor_style'    => 'wptl-editor',
				'style'           => 'wptl-frontend',
				'render_callback' => array( $this, 'render' ),
				'uses_context'    => array( 'postId', 'postType' ),
				'attributes'      => array(
					'postId' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'preset' => array(
						'type'    => 'string',
						'default' => '',
					),
					'headingTag' => array(
						'type'    => 'string',
						'default' => 'h1',
					),
				),
				'supports' => array(
					'html'       => false,
					'align'      => array( 'wide', 'full' ),
					'className'  => true,
					'anchor'     => true,
				),
			)
		);
	}

	/**
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content    Saved content.
	 * @param \WP_Block|null       $block      Block instance.
	 * @return string
	 */
	public function render( array $attributes, string $content = '', $block = null ): string {
		$post_id = absint( $attributes['postId'] ?? 0 );
		if ( $post_id < 1 && $block instanceof \WP_Block && isset( $block->context['postId'] ) ) {
			$post_id = absint( $block->context['postId'] );
		}
		$post_id = $post_id > 0 ? $post_id : (int) get_the_ID();
		AutoDisplay::markManualPlacement( $post_id );
		if ( ThemeTitleIntegration::wasReplaced( $post_id ) || ThemeTitleIntegration::ownsFullLayerPlacement( $post_id ) ) {
			return '';
		}

		// Renderer owns the root element and applies a strict class sanitizer.
		$class_name = 'wp-block-wp-title-layer-title-layer';
		if ( isset( $attributes['className'] ) ) {
			$class_name .= ' ' . (string) $attributes['className'];
		}
		if ( isset( $attributes['align'] ) && in_array( $attributes['align'], array( 'wide', 'full' ), true ) ) {
			$class_name .= ' align' . $attributes['align'];
		}

		$html = $this->renderer->render(
			$post_id,
			array(
				'preset'      => sanitize_key( (string) ( $attributes['preset'] ?? '' ) ),
				'heading_tag' => sanitize_key( (string) ( $attributes['headingTag'] ?? 'h1' ) ),
				'class_name'  => $class_name,
			)
		);
		if ( '' !== trim( $html ) ) {
			AutoDisplay::markFullLayerRendered( $post_id );
		}

		return $html;
	}
}
