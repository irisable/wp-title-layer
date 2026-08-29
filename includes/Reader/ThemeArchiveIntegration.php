<?php
/**
 * Opt-in archive-card Subtitle adapters for verified classic themes.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Reader;

use WPTitleLayer\Core\Schema;
use WPTitleLayer\Core\Series;
use WPTitleLayer\Presentation\ThemeTitleIntegration;

defined( 'ABSPATH' ) || exit;

final class ThemeArchiveIntegration {
	public static function register(): void {
		// Verified Kadence 1.5.x places its loop title at 20 and metadata at 30.
		add_action( 'kadence_loop_entry_header', array( __CLASS__, 'renderKadenceSubtitle' ), 25 );
	}

	/** @return array{supported:bool,adapter:string,label:string} */
	public static function support(): array {
		$title_support = class_exists( ThemeTitleIntegration::class ) ? ThemeTitleIntegration::support() : array();
		$supported = ! empty( $title_support['supported'] )
			&& 'kadence' === ( $title_support['adapter'] ?? '' )
			&& self::hasVerifiedKadenceArchiveTemplates();

		return array(
			'supported' => $supported,
			'adapter'   => $supported ? 'kadence' : 'manual',
			'label'     => $supported
				? __( 'Kadence archive-card Subtitle adapter', 'wp-title-layer' )
				: __( 'No verified archive-card adapter for the active theme', 'wp-title-layer' ),
		);
	}

	public static function isActiveForRequest(): bool {
		$taxonomy = Series::claimed_taxonomy();
		if (
			'' === $taxonomy
			|| empty( self::support()['supported'] )
			|| is_admin()
			|| is_feed()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ! is_tax( $taxonomy )
			|| TemplateLoader::usesPluginArchiveTemplate()
		) {
			return false;
		}

		$query = isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query ? $GLOBALS['wp_query'] : null;
		$term  = get_queried_object();
		return $query instanceof \WP_Query
			&& $query->is_main_query()
			&& $term instanceof \WP_Term
			&& $taxonomy === $term->taxonomy
			&& Settings::themeArchiveSubtitlesEnabled( $term );
	}

	public static function renderKadenceSubtitle(): void {
		if ( ! self::isActiveForRequest() || ! in_the_loop() ) {
			return;
		}

		$post_id = (int) get_the_ID();
		$post    = 0 < $post_id ? get_post( $post_id ) : null;
		$term    = get_queried_object();
		if (
			! $post instanceof \WP_Post
			|| ! $term instanceof \WP_Term
			|| 'publish' !== $post->post_status
			|| '' !== (string) $post->post_password
			|| ! is_post_publicly_viewable( $post )
			|| ! has_term( (int) $term->term_id, $term->taxonomy, $post_id )
		) {
			return;
		}

		$subtitle = self::subtitle( $post_id );
		/**
		 * Filter the plain-text Subtitle shown in a verified theme archive card.
		 * Returning an empty value suppresses this one card.
		 *
		 * @param string   $subtitle Subtitle text.
		 * @param int      $post_id Post ID.
		 * @param \WP_Term $term    Current Series archive term.
		 */
		$filtered = apply_filters( 'wptl_theme_archive_card_subtitle', $subtitle, $post_id, $term );
		$subtitle = is_scalar( $filtered ) ? sanitize_text_field( (string) $filtered ) : '';
		if ( '' === trim( $subtitle ) ) {
			return;
		}

		echo '<p class="wptl-theme-archive-subtitle wptl-theme-archive-subtitle--kadence">' . esc_html( $subtitle ) . '</p>';
	}

	private static function subtitle( int $post_id ): string {
		if ( metadata_exists( 'post', $post_id, Schema::META_SUBTITLE ) ) {
			$value = get_post_meta( $post_id, Schema::META_SUBTITLE, true );
			return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}

		$value  = get_post_meta( $post_id, '_secondary_title', true );
		$legacy = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		return 1 === preg_match( '/^field_[A-Za-z0-9_-]+$/', $legacy ) ? '' : $legacy;
	}

	private static function hasVerifiedKadenceArchiveTemplates(): bool {
		if ( ! function_exists( 'get_template' ) || 'kadence' !== get_template() ) {
			return false;
		}
		if ( function_exists( 'get_stylesheet' ) && get_stylesheet() !== get_template() ) {
			foreach ( array( 'template-parts/content/entry_loop_header.php', 'template-parts/content/entry_loop_title.php' ) as $relative ) {
				if ( is_readable( trailingslashit( get_stylesheet_directory() ) . $relative ) ) {
					return false;
				}
			}
		}

		$base        = function_exists( 'get_template_directory' ) ? trailingslashit( get_template_directory() ) : '';
		$header_file = $base . 'template-parts/content/entry_loop_header.php';
		$title_file  = $base . 'template-parts/content/entry_loop_title.php';
		if ( '' === $base || ! is_readable( $header_file ) || ! is_readable( $title_file ) ) {
			return false;
		}

		$header_source = file_get_contents( $header_file );
		$title_source  = file_get_contents( $title_file );
		return is_string( $header_source )
			&& is_string( $title_source )
			&& false !== strpos( $header_source, "do_action( 'kadence_loop_entry_header' );" )
			&& false !== strpos( $title_source, 'class="entry-title"' )
			&& 20 === has_action( 'kadence_loop_entry_header', 'Kadence\\loop_entry_title' )
			&& 30 === has_action( 'kadence_loop_entry_header', 'Kadence\\loop_entry_meta' );
	}
}
