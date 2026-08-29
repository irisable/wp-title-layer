<?php
/**
 * Optional after-content navigation integration.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Reader;

use WPTitleLayer\Core\Series;

defined( 'ABSPATH' ) || exit;

final class ContentAppender {
	/** @var Renderer */
	private $renderer;

	/** @var bool */
	private $rendering = false;

	/** @var array<int,bool> */
	private $appendedPosts = array();

	public function __construct( ?Renderer $renderer = null ) {
		$this->renderer = $renderer ?: new Renderer();
	}

	public function register(): void {
		add_filter( 'the_content', array( $this, 'append' ), 25 );
	}

	public function append( string $content ): string {
		if (
			'' === Series::claimed_taxonomy()
			|| $this->rendering
			|| is_admin()
			|| is_feed()
			|| ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() )
			|| ( function_exists( 'is_embed' ) && is_embed() )
			|| doing_filter( 'get_the_excerpt' )
			|| doing_filter( 'the_excerpt' )
			|| ! is_singular()
			|| ! is_main_query()
			|| ! in_the_loop()
		) {
			return $content;
		}

		$post_id = (int) get_the_ID();
		if (
			1 > $post_id
			|| (int) get_queried_object_id() !== $post_id
			|| isset( $this->appendedPosts[ $post_id ] )
			|| false !== strpos( $content, 'wptl-series-navigation' )
			|| post_password_required( $post_id )
			|| ! Settings::afterContentEnabled( $post_id )
		) {
			return $content;
		}

		$this->rendering = true;
		try {
			$navigation = $this->renderer->render( $post_id );
		} finally {
			$this->rendering = false;
		}

		if ( '' === $navigation ) {
			return $content;
		}
		$this->appendedPosts[ $post_id ] = true;

		/**
		 * @param string $navigation Rendered navigation HTML.
		 * @param int    $post_id    Current post ID.
		 */
		$navigation = (string) apply_filters( 'wptl_reader_after_content_html', $navigation, $post_id );

		return $content . wp_kses( $navigation, Renderer::allowedHtml() );
	}
}
