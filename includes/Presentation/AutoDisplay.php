<?php
/**
 * Opt-in automatic front-end placement for the resolved title layer.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Presentation;

defined( 'ABSPATH' ) || exit;

/**
 * Prepends a title layer to the singular main-loop body only when the site
 * owner has explicitly enabled the mode and confirmed the theme title is off.
 */
final class AutoDisplay {
	public const MODE_MANUAL       = 'manual';
	public const MODE_AUTO_PREPEND = 'auto-prepend';

	/** @var Renderer */
	private $renderer;

	/** @var array<int,bool> */
	private $injected_posts = array();

	/** @var bool */
	private $rendering = false;

	/** @var array<int,bool> */
	private static $manual_posts = array();

	/** @var array<string,bool> */
	private static $full_layer_posts = array();

	public function __construct( ?Renderer $renderer = null ) {
		$this->renderer = $renderer ?: new Renderer();
	}

	/**
	 * Register the content filter. Runtime eligibility remains checked on every
	 * call so switching back to manual mode takes effect immediately.
	 */
	public function register(): void {
		// Core expands blocks at 9 and shortcodes at 11. Running afterwards lets
		// their callbacks mark explicit placement, including synced/reusable blocks.
		add_filter( 'the_content', array( $this, 'prependToContent' ), 20 );
	}

	/**
	 * Prepend the resolved title layer once to eligible singular content.
	 *
	 * @param mixed $content Filtered post content.
	 * @return string
	 */
	public function prependToContent( $content ): string {
		$content = is_string( $content ) ? $content : (string) $content;
		$post_id = (int) get_the_ID();

		if (
			$this->rendering
			|| false !== strpos( $content, 'wptl-auto-prepend' )
			|| self::containsManualPlacement( $content )
			|| isset( self::$manual_posts[ $post_id ] )
			|| isset( $this->injected_posts[ $post_id ] )
			|| ! $this->shouldInject( $post_id )
		) {
			return $content;
		}

		$settings        = SettingsPage::presentationSettings();
		$this->rendering = true;
		try {
			$title_layer = $this->renderer->render(
				$post_id,
				array(
					'heading_tag' => (string) $settings['auto_heading_tag'],
					'class_name'  => 'wptl-auto-prepend',
				)
			);
		} finally {
			$this->rendering = false;
		}

		// A post-level `disabled` override resolves to an empty string and remains
		// authoritative even when the site-wide automatic mode is enabled.
		if ( '' === trim( $title_layer ) ) {
			return $content;
		}

		$this->injected_posts[ $post_id ] = true;

		return $title_layer . $content;
	}

	/**
	 * Determine whether the current request is a safe automatic-placement target.
	 *
	 * This method is public to give integration tests and advanced themes one
	 * stable, read-only eligibility check; it does not perform rendering.
	 *
	 * @param int $post_id Candidate post ID.
	 * @return bool
	 */
	public function shouldInject( int $post_id ): bool {
		$settings = SettingsPage::presentationSettings();

		if ( ! self::isAutoConfigured( $settings ) || $post_id < 1 ) {
			return false;
		}

		if (
			is_admin()
			|| is_feed()
			|| ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() )
			|| ( function_exists( 'is_embed' ) && is_embed() )
			|| doing_filter( 'get_the_excerpt' )
			|| doing_filter( 'the_excerpt' )
			|| ! is_singular()
			|| ! in_the_loop()
			|| ! is_main_query()
		) {
			return false;
		}

		$queried_id = (int) get_queried_object_id();
		if ( $queried_id !== $post_id ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof \WP_Post ) || 'publish' !== $post->post_status || ! is_post_publicly_viewable( $post ) ) {
			return false;
		}

		$post_type = $post->post_type;
		if ( ! is_string( $post_type ) || ! in_array( $post_type, $settings['auto_post_types'], true ) ) {
			return false;
		}

		if ( post_password_required( $post_id ) ) {
			return false;
		}

		/**
		 * Allow a theme or site integration to veto otherwise safe auto placement.
		 * The filter runs only after all hard request-safety guards have passed.
		 *
		 * @param bool                 $inject   Whether to inject.
		 * @param int                  $post_id  Post ID.
		 * @param array<string,mixed>  $settings Normalized presentation settings.
		 */
		return (bool) apply_filters( 'wptl_auto_prepend_should_inject', true, $post_id, $settings );
	}

	/**
	 * Check the two explicit activation settings without consulting request state.
	 *
	 * @param array<string,mixed> $settings Normalized or raw settings.
	 * @return bool
	 */
	public static function isAutoConfigured( array $settings ): bool {
		return self::MODE_AUTO_PREPEND === ( $settings['display_mode'] ?? self::MODE_MANUAL )
			&& ! empty( $settings['auto_theme_title_disabled'] )
			&& ! empty( $settings['auto_post_types'] );
	}

	/**
	 * Detect an explicitly placed full Title Layer before WordPress expands
	 * blocks and shortcodes. Automatic mode yields to the author's placement.
	 *
	 * @param string $content Raw or partly filtered post content.
	 * @return bool
	 */
	public static function containsManualPlacement( string $content ): bool {
		$has_dynamic_block = function_exists( 'has_block' ) && has_block( DynamicBlock::NAME, $content );
		$has_shortcode     = false;
		if ( function_exists( 'has_shortcode' ) ) {
			foreach ( array( 'wp_title_layer', 'wptl_subtitle', 'secondary_title' ) as $tag ) {
				if ( has_shortcode( $content, $tag ) ) {
					$has_shortcode = true;
					break;
				}
			}
		}

		return $has_dynamic_block || $has_shortcode;
	}

	/**
	 * Record explicit placement for this request. Block and shortcode renderers
	 * call this even when a post override resolves to disabled, so automatic
	 * placement never second-guesses an author's deliberate placement choice.
	 */
	public static function markManualPlacement( int $post_id = 0 ): void {
		$post_id = $post_id > 0 ? $post_id : (int) get_the_ID();
		if ( $post_id > 0 ) {
			self::$manual_posts[ $post_id ] = true;
		}
	}

	/** Record that a complete, non-empty manual layer actually rendered. */
	public static function markFullLayerRendered( int $post_id ): void {
		if ( $post_id > 0 ) {
			self::$full_layer_posts[ self::requestKey( $post_id ) ] = true;
		}
	}

	/** Whether a complete manual Title Layer has rendered in this request. */
	public static function hasFullLayerPlacement( int $post_id ): bool {
		return isset( self::$full_layer_posts[ self::requestKey( $post_id ) ] );
	}

	private static function requestKey( int $post_id ): string {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		return $blog_id . ':' . max( 0, $post_id );
	}
}
