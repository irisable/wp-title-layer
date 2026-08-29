<?php
/**
 * Optional Subtitle visibility in WordPress post list tables.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Admin;

use WPTitleLayer\Core\Schema;
use WPTitleLayer\Core\Series;

defined( 'ABSPATH' ) || exit;

final class PostListColumns {
	private const COLUMN = 'wptl_subtitle';

	/** @var string[] */
	private static $post_types = array();

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'registerPostTypeHooks' ), 30 );
		add_action( 'admin_head-edit.php', array( __CLASS__, 'renderStyles' ) );
	}

	public static function registerPostTypeHooks(): void {
		$taxonomy = Series::claimed_taxonomy();
		$object   = '' !== $taxonomy ? get_taxonomy( $taxonomy ) : null;
		$post_types = $object instanceof \WP_Taxonomy ? (array) $object->object_type : array( 'post' );

		/**
		 * @param string[] $post_types Post types whose list table exposes Subtitle.
		 */
		$post_types = (array) apply_filters( 'wptl_subtitle_admin_column_post_types', $post_types );
		foreach ( array_unique( array_filter( array_map( 'sanitize_key', $post_types ) ) ) as $post_type ) {
			$post_type_object = get_post_type_object( $post_type );
			if ( ! $post_type_object instanceof \WP_Post_Type || empty( $post_type_object->show_ui ) ) {
				continue;
			}

			self::$post_types[] = $post_type;
			add_filter( "manage_{$post_type}_posts_columns", array( __CLASS__, 'addColumn' ), 20 );
			add_action( "manage_{$post_type}_posts_custom_column", array( __CLASS__, 'renderColumn' ), 10, 2 );
		}
	}

	/** @param array<string,string> $columns */
	public static function addColumn( array $columns ): array {
		$result = array();
		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'title' === $key ) {
				$result[ self::COLUMN ] = __( 'Subtitle', 'wp-title-layer' );
			}
		}

		if ( ! isset( $result[ self::COLUMN ] ) ) {
			$result[ self::COLUMN ] = __( 'Subtitle', 'wp-title-layer' );
		}
		return $result;
	}

	public static function renderColumn( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$subtitle = self::subtitle( $post_id );
		if ( '' === $subtitle ) {
			echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No subtitle', 'wp-title-layer' ) . '</span>';
			return;
		}

		echo '<span class="wptl-admin-subtitle">' . esc_html( $subtitle ) . '</span>';
	}

	public static function renderStyles(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || ! in_array( (string) $screen->post_type, self::$post_types, true ) ) {
			return;
		}
		?>
		<style>
			.fixed .column-wptl_subtitle { width: 20%; }
			.wptl-admin-subtitle { display: block; max-width: 32rem; line-height: 1.4; }
			@media (max-width: 1100px) { .fixed .column-wptl_subtitle { width: 16%; } }
		</style>
		<?php
	}

	private static function subtitle( int $post_id ): string {
		if ( metadata_exists( 'post', $post_id, Schema::META_SUBTITLE ) ) {
			return trim( sanitize_text_field( (string) get_post_meta( $post_id, Schema::META_SUBTITLE, true ) ) );
		}

		$legacy = trim( sanitize_text_field( (string) get_post_meta( $post_id, '_secondary_title', true ) ) );
		return 1 === preg_match( '/^field_[A-Za-z0-9_-]+$/', $legacy ) ? '' : $legacy;
	}
}
