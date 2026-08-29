<?php
/**
 * Reader front-end stylesheet.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Reader;

defined( 'ABSPATH' ) || exit;

final class Assets {
	public const HANDLE = 'wptl-reader';

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'registerStyle' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueueArchiveStyle' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueueKnownSingularStyle' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'printLateStyle' ), 999 );
	}

	public static function registerStyle(): void {
		$version = defined( 'WPTL_VERSION' ) ? (string) WPTL_VERSION : '1.0.0-rc.1';
		wp_register_style( self::HANDLE, self::assetUrl(), array(), $version );
	}

	public static function enqueueArchiveStyle(): void {
		if ( TemplateLoader::usesPluginArchiveTemplate() || ThemeArchiveIntegration::isActiveForRequest() ) {
			self::enqueue();
		}
	}

	/**
	 * Enqueue before wp_head for the two detectable singular integrations.
	 * The footer fallback below covers a shortcode generated programmatically.
	 */
	public static function enqueueKnownSingularStyle(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		$post     = 0 < $post_id ? get_post( $post_id ) : null;
		$enqueue  = 0 < $post_id && Settings::afterContentEnabled( $post_id );

		if ( ! $enqueue && Settings::shortcodeEnabled() && $post instanceof \WP_Post ) {
			$enqueue = has_shortcode( (string) $post->post_content, 'wptl_series_navigation' );
		}

		/**
		 * @param bool          $enqueue Whether to enqueue the Reader stylesheet.
		 * @param int           $post_id Current singular post ID.
		 * @param \WP_Post|null $post     Current singular post.
		 */
		if ( (bool) apply_filters( 'wptl_reader_enqueue_singular_style', $enqueue, $post_id, $post ) ) {
			self::enqueue();
		}
	}

	/**
	 * A shortcode may be produced by a widget or template after wp_head. Print
	 * only this handle in the footer when that late render enqueued it.
	 */
	public static function printLateStyle(): void {
		if ( wp_style_is( self::HANDLE, 'enqueued' ) && ! wp_style_is( self::HANDLE, 'done' ) ) {
			wp_print_styles( array( self::HANDLE ) );
		}
	}

	public static function enqueue(): void {
		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			self::registerStyle();
		}
		wp_enqueue_style( self::HANDLE );
	}

	public static function assetUrl(): string {
		if ( defined( 'WPTL_URL' ) ) {
			return trailingslashit( (string) WPTL_URL ) . 'assets/reader.css';
		}

		// includes/Reader -> plugin root.
		return plugin_dir_url( dirname( __DIR__, 2 ) . '/wp-title-layer.php' ) . 'assets/reader.css';
	}
}
