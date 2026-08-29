<?php
/**
 * Optional plugin-owned Series archive template.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Reader;

use WPTitleLayer\Core\Series;

defined( 'ABSPATH' ) || exit;

final class TemplateLoader {
	public static function register(): void {
		add_filter( 'template_include', array( __CLASS__, 'filterTemplate' ), 99 );
	}

	public static function filterTemplate( string $theme_template ): string {
		if ( ! self::usesPluginArchiveTemplate() ) {
			return $theme_template;
		}

		$default = self::defaultTemplate();
		/**
		 * @param string $default        Plugin archive template path.
		 * @param string $theme_template Theme-selected template path.
		 */
		$template = (string) apply_filters( 'wptl_reader_archive_template', $default, $theme_template );

		$template = TemplatePaths::resolve( $template, $default );

		return '' !== $template ? $template : $theme_template;
	}

	public static function defaultTemplate(): string {
		return trailingslashit( dirname( __DIR__, 2 ) ) . 'templates/series-archive.php';
	}

	public static function usesPluginArchiveTemplate( ?\WP_Query $query = null ): bool {
		$taxonomy = Series::claimed_taxonomy();
		if ( '' === $taxonomy ) {
			return false;
		}

		$is_series_archive = $query
			? $query->is_tax( $taxonomy )
			: is_tax( $taxonomy );
		if ( ! $is_series_archive ) {
			return false;
		}

		$term = $query ? $query->get_queried_object() : get_queried_object();
		if ( ! $term instanceof \WP_Term || $taxonomy !== $term->taxonomy || ! Settings::archiveTemplateEnabled( $term ) ) {
			return false;
		}
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return false;
		}

		return ! self::hasDedicatedClassicTemplate( $term );
	}

	private static function hasDedicatedClassicTemplate( $term = null ): bool {
		$taxonomy = Series::claimed_taxonomy();
		$term = $term ?: get_queried_object();
		if ( '' === $taxonomy || ! $term instanceof \WP_Term || $taxonomy !== $term->taxonomy || ! function_exists( 'locate_template' ) ) {
			return false;
		}

		$candidates = array(
			'taxonomy-' . $taxonomy . '-' . $term->slug . '.php',
			'taxonomy-' . $taxonomy . '.php',
		);

		return '' !== (string) locate_template( $candidates, false, false );
	}
}
