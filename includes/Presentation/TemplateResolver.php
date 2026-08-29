<?php
/**
 * Resolve the effective presentation template and its source.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Presentation;

defined( 'ABSPATH' ) || exit;

final class TemplateResolver {
	/**
	 * Resolve a post's effective preset.
	 *
	 * Priority: post override > selected Series default > first matching
	 * category rule > global default. The special article override `disabled`
	 * intentionally resolves without a renderable preset.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public function resolve( int $post_id ): array {
		$presets = Presets::all();
		$settings = SettingsPage::presentationSettings();
		$result = array(
			'preset'       => $this->validPreset( (string) ( $settings['default_template'] ?? '' ), $presets ),
			'disabled'     => false,
			'source'       => 'global',
			'source_label' => __( 'Global default', 'wp-title-layer' ),
			'series_term'  => null,
		);

		$category_match = $this->categoryRule( $post_id, (array) ( $settings['category_rules'] ?? array() ), $presets );
		if ( null !== $category_match ) {
			$result = $category_match;
		}

		$series_term = $this->selectedSeries( $post_id );
		if ( $series_term instanceof \WP_Term ) {
			$result['series_term'] = $series_term;
			$series_default = sanitize_key( (string) get_term_meta( $series_term->term_id, Schema::defaultTemplateMeta(), true ) );
			if ( isset( $presets[ $series_default ] ) ) {
				$result['preset']       = $series_default;
				$result['source']       = 'series';
				$result['source_label'] = sprintf(
					/* translators: %s: Series name. */
					__( 'Series default: %s', 'wp-title-layer' ),
					$series_term->name
				);
			}
		}

		if ( metadata_exists( 'post', $post_id, Schema::templateOverrideMeta() ) ) {
			$override = sanitize_key( (string) get_post_meta( $post_id, Schema::templateOverrideMeta(), true ) );
			if ( 'disabled' === $override ) {
				$result['preset']       = 'disabled';
				$result['disabled']     = true;
				$result['source']       = 'post';
				$result['source_label'] = __( 'Disabled by post override', 'wp-title-layer' );
			} elseif ( '' !== $override && isset( $presets[ $override ] ) ) {
				$result['preset']       = $override;
				$result['disabled']     = false;
				$result['source']       = 'post';
				$result['source_label'] = __( 'Post override', 'wp-title-layer' );
			}
		}

		/**
		 * Filter the resolved template result.
		 *
		 * @param array<string,mixed> $result  Resolution result.
		 * @param int                 $post_id Post ID.
		 */
		$filtered = apply_filters( 'wptl_resolved_template', $result, $post_id );
		$filter_disables = is_array( $filtered ) && ! empty( $filtered['disabled'] );
		$filter_preset   = is_array( $filtered ) ? sanitize_key( (string) ( $filtered['preset'] ?? '' ) ) : '';
		if ( is_array( $filtered ) && ( $filter_disables || isset( $presets[ $filter_preset ] ) ) ) {
			$result = array_merge( $result, $filtered );
			$result['disabled'] = ! empty( $result['disabled'] );
			$result['preset']   = $result['disabled'] ? 'disabled' : sanitize_key( (string) $result['preset'] );
		}

		return $result;
	}

	/**
	 * Get the single Series selected for a post.
	 *
	 * The editor intentionally enforces single selection. Existing imported
	 * posts with multiple terms use the lowest term_order/term_id deterministically.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Term|null
	 */
	public function selectedSeries( int $post_id ): ?\WP_Term {
		$terms = wp_get_object_terms(
			$post_id,
			Schema::seriesTaxonomy(),
			array(
				'orderby' => 'term_order',
				'order'   => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) || ! $terms[0] instanceof \WP_Term ) {
			return null;
		}

		return $terms[0];
	}

	/**
	 * @param int                                $post_id Post ID.
	 * @param array<int|string,mixed>            $rules   Category rules.
	 * @param array<string,array<string,mixed>>  $presets Available presets.
	 * @return array<string,mixed>|null
	 */
	private function categoryRule( int $post_id, array $rules, array $presets ): ?array {
		$category_ids = wp_get_post_categories( $post_id, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $category_ids ) || empty( $category_ids ) ) {
			return null;
		}

		foreach ( $rules as $category_id => $preset_id ) {
			$category_id = absint( $category_id );
			$preset_id   = sanitize_key( (string) $preset_id );
			if ( in_array( $category_id, $category_ids, true ) && isset( $presets[ $preset_id ] ) ) {
				$category = get_term( $category_id, 'category' );
				return array(
					'preset'       => $preset_id,
					'disabled'     => false,
					'source'       => 'category',
					'source_label' => $category instanceof \WP_Term
						? sprintf(
							/* translators: %s: Category name. */
							__( 'Category rule: %s', 'wp-title-layer' ),
							$category->name
						)
						: __( 'Category rule', 'wp-title-layer' ),
					'series_term'  => null,
				);
			}
		}

		return null;
	}

	/**
	 * @param string                             $candidate Candidate preset ID.
	 * @param array<string,array<string,mixed>>  $presets   Available presets.
	 * @return string
	 */
	private function validPreset( string $candidate, array $presets ): string {
		$candidate = sanitize_key( $candidate );
		if ( isset( $presets[ $candidate ] ) ) {
			return $candidate;
		}

		return isset( $presets[ Presets::DEFAULT_ID ] ) ? Presets::DEFAULT_ID : (string) array_key_first( $presets );
	}
}
