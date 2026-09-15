<?php
/**
 * Block editor assets and bootstrap data.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Presentation;

defined( 'ABSPATH' ) || exit;

final class EditorAssets {
	public const SCRIPT_HANDLE = 'wptl-editor';
	public const STYLE_HANDLE  = 'wptl-editor';
	public const FRONT_HANDLE  = 'wptl-frontend';

	public static function register(): void {
		$base_url = self::assetsUrl();
		$version  = defined( 'WPTL_VERSION' ) ? (string) WPTL_VERSION : '1.0.0-rc.1';
		$editor_data = self::editorData();

		wp_register_script(
			self::SCRIPT_HANDLE,
			$base_url . 'editor.js',
			array(
				'wp-api-fetch',
				'wp-block-editor',
				'wp-blocks',
				'wp-components',
				'wp-compose',
				'wp-core-data',
				'wp-data',
				'wp-element',
				'wp-edit-post',
				'wp-i18n',
				'wp-plugins',
				'wp-server-side-render',
			),
			$version,
			true
		);

		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'wp-title-layer',
			defined( 'WPTL_PATH' ) ? WPTL_PATH . 'languages' : dirname( __DIR__, 2 ) . '/languages'
		);

		wp_register_style(
			self::STYLE_HANDLE,
			$base_url . 'editor.css',
			array( 'wp-edit-blocks' ),
			$version
		);
		if ( ! empty( $editor_data['nativeSeriesPanel'] ) ) {
			$panel_class = sanitize_html_class( (string) $editor_data['nativeSeriesPanel'] );
			if ( '' !== $panel_class ) {
				// Hide the plugin-owned native tag panel before Gutenberg finishes
				// mounting it. The JavaScript guard then preserves that state after
				// editor-store resets. Reused taxonomies are never hidden by a
				// hard-coded default slug.
				wp_add_inline_style( self::STYLE_HANDLE, '.' . $panel_class . '{display:none!important;}' );
			}
		}

		wp_register_style(
			self::FRONT_HANDLE,
			$base_url . 'frontend.css',
			array(),
			$version
		);

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.WPTitleLayerEditor = ' . wp_json_encode( $editor_data ) . ';',
			'before'
		);
	}

	/**
	 * Enqueue sidebar assets for block-editor post screens even when no dynamic
	 * block has been inserted yet.
	 */
	public static function enqueueBlockEditorAssets(): void {
		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_enqueue_style( self::STYLE_HANDLE );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function editorData(): array {
		$settings = SettingsPage::presentationSettings();
		$claimed_taxonomy = class_exists( '\\WPTitleLayer\\Core\\Series' ) && is_callable( array( '\\WPTitleLayer\\Core\\Series', 'claimed_taxonomy' ) )
			? (string) call_user_func( array( '\\WPTitleLayer\\Core\\Series', 'claimed_taxonomy' ) )
			: '';
		$taxonomy = '' !== $claimed_taxonomy ? get_taxonomy( $claimed_taxonomy ) : null;
		$schema   = Schema::editorMap();
		$schema['taxonomy'] = $claimed_taxonomy;
		$series_attribute = $taxonomy && is_string( $taxonomy->rest_base ) && '' !== $taxonomy->rest_base
			? $taxonomy->rest_base
			: $claimed_taxonomy;
		$default_taxonomy = class_exists( '\\WPTitleLayer\\Core\\Schema' )
			? (string) \WPTitleLayer\Core\Schema::TAXONOMY_SERIES
			: 'wptl_series';
		$hide_native_series_panel = $taxonomy && $default_taxonomy === $claimed_taxonomy;
		if ( $taxonomy ) {
			/**
			 * Whether WPTL may replace this taxonomy's native editor panel.
			 *
			 * Custom/reused taxonomies remain visible by default. Integrators should
			 * opt in only when WPTL is intended to own their single-term UI.
			 *
			 * @param bool         $hide_native_series_panel Whether to hide the panel.
			 * @param \WP_Taxonomy $taxonomy_object         Claimed taxonomy object.
			 * @param string       $claimed_taxonomy        Claimed taxonomy slug.
			 */
			$hide_native_series_panel = (bool) apply_filters(
				'wptl_hide_native_series_panel',
				$hide_native_series_panel,
				$taxonomy,
				$claimed_taxonomy
			);
		}
		$supported_post_types = array();
		foreach ( get_post_types( array( 'show_in_rest' => true, 'show_ui' => true ), 'objects' ) as $post_type ) {
			if (
				$post_type instanceof \WP_Post_Type
				&& 'attachment' !== $post_type->name
				&& post_type_supports( $post_type->name, 'custom-fields' )
			) {
				$supported_post_types[] = $post_type->name;
			}
		}
		/** @param string[] $supported_post_types Post types with the Title Layer document panel. */
		$supported_post_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'wptl_editor_post_types', $supported_post_types ) ) ) ) );
		$rules    = array();
		foreach ( $settings['category_rules'] as $category_id => $preset_id ) {
			$term = get_term( $category_id, 'category' );
			$rules[] = array(
				'categoryId'   => (int) $category_id,
				'categoryName' => $term instanceof \WP_Term ? $term->name : '',
				'preset'       => $preset_id,
			);
		}
		$role_labels = \WPTitleLayer\Core\Schema::role_labels();
		$post_id     = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		$current_sequence = array(
			'postId'    => $post_id,
			'termId'    => 0,
			'seasonKey' => '',
			'ordinal'   => 0,
		);
		if ( 0 < $post_id && class_exists( '\\WPTitleLayer\\Core\\Sequence' ) ) {
			$current_term = \WPTitleLayer\Core\Series::get_primary_term( $post_id );
			if ( $current_term instanceof \WP_Term && \WPTitleLayer\Core\Sequence::is_managed( $current_term ) ) {
				$current_sequence = array(
					'postId'    => $post_id,
					'termId'    => (int) $current_term->term_id,
					'seasonKey' => (string) get_post_meta( $post_id, \WPTitleLayer\Core\Schema::META_SEASON_KEY, true ),
					'ordinal'   => \WPTitleLayer\Core\Sequence::automatic_ordinal( $post_id, $current_term ),
				);
			}
		}

		$data = array(
			'canRenameGroups' => $taxonomy && current_user_can( $taxonomy->cap->edit_terms ),
			'schema'          => $schema,
			'presets'         => Presets::choices(),
			'defaultTemplate' => $settings['default_template'],
			'categoryRules'   => $rules,
			'supportedPostTypes' => $supported_post_types,
			'seriesPostTypes' => $taxonomy ? array_values( (array) $taxonomy->object_type ) : array(),
			'seriesAttribute' => $series_attribute,
			'nativeSeriesPanel' => $hide_native_series_panel ? 'taxonomy-panel-' . $claimed_taxonomy : '',
			'seriesCategoryBranches' => self::seriesCategoryBranches( $claimed_taxonomy ),
			'sequenceManagerUrl' => add_query_arg( 'page', 'wp-title-layer-sequence-manager', admin_url( 'admin.php' ) ),
			'currentSequence'  => $current_sequence,
			'managedSeriesIds' => self::managedSeriesIds( $claimed_taxonomy ),
			'bookStructureSeriesIds' => self::bookStructureSeriesIds( $claimed_taxonomy ),
			'roles'           => array_map(
				static function ( string $value, string $label ): array {
					return array( 'value' => $value, 'label' => $label );
				},
				array_keys( $role_labels ),
				array_values( $role_labels )
			),
			'scopes'          => array_map(
				static function ( string $value, string $label ): array {
					return array( 'value' => $value, 'label' => $label );
				},
				array_keys( \WPTitleLayer\Core\Schema::scope_labels() ),
				array_values( \WPTitleLayer\Core\Schema::scope_labels() )
			),
		);

		/** @param array<string,mixed> $data Editor bootstrap data. */
		$filtered = apply_filters( 'wptl_editor_data', $data );

		return is_array( $filtered ) ? $filtered : $data;
	}

	/** @return int[] */
	private static function bookStructureSeriesIds( string $taxonomy ): array {
		if ( '' === $taxonomy ) {
			return array();
		}
		$ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_query' => array(
					array(
						'key'     => \WPTitleLayer\Core\Schema::TERM_META_BOOK_STRUCTURE_VERSION,
						'value'   => \WPTitleLayer\Core\BookStructure::VERSION,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		return is_wp_error( $ids ) ? array() : array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/** @return int[] */
	private static function managedSeriesIds( string $taxonomy ): array {
		if ( '' === $taxonomy ) {
			return array();
		}
		$ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_query' => array(
					array(
						'key'     => \WPTitleLayer\Core\Schema::TERM_META_SEQUENCE_SCHEMA_VERSION,
						'value'   => \WPTitleLayer\Core\Schema::SEQUENCE_SCHEMA_VERSION,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		return is_wp_error( $ids ) ? array() : array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * Export only Category branches actually referenced by a Series. This keeps
	 * the editor warning accurate for descendants without localizing the site's
	 * entire Category tree when most Categories are unrelated to Series.
	 *
	 * @return array<string,array{name:string,ids:int[]}>
	 */
	private static function seriesCategoryBranches( string $taxonomy ): array {
		if ( '' === $taxonomy || ! class_exists( '\\WPTitleLayer\\Core\\Series' ) ) {
			return array();
		}

		$term_ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_query' => array(
					array(
						'key'     => \WPTitleLayer\Core\Schema::TERM_META_PARENT_CATEGORY_ID,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		if ( is_wp_error( $term_ids ) ) {
			return array();
		}

		$branches = array();
		foreach ( array_map( 'absint', (array) $term_ids ) as $term_id ) {
			$category_id = \WPTitleLayer\Core\Series::parent_category_id( $term_id );
			if ( 0 >= $category_id || isset( $branches[ (string) $category_id ] ) ) {
				continue;
			}
			$category = get_term( $category_id, 'category' );
			$children = get_term_children( $category_id, 'category' );
			if ( ! $category instanceof \WP_Term || is_wp_error( $children ) ) {
				continue;
			}
			$branches[ (string) $category_id ] = array(
				'name' => (string) $category->name,
				'ids'  => array_values( array_unique( array_merge( array( $category_id ), array_map( 'absint', $children ) ) ) ),
			);
		}

		return $branches;
	}

	private static function assetsUrl(): string {
		if ( defined( 'WPTL_URL' ) ) {
			return trailingslashit( (string) WPTL_URL ) . 'assets/';
		}

		if ( defined( 'WPTL_PLUGIN_URL' ) ) {
			return trailingslashit( (string) WPTL_PLUGIN_URL ) . 'assets/';
		}

		// includes/Presentation -> plugin root.
		return plugin_dir_url( dirname( __DIR__, 2 ) . '/wp-title-layer.php' ) . 'assets/';
	}
}
