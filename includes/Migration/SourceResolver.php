<?php
/**
 * Resolve legacy and ACF subtitle sources without mutating either source.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Migration;

final class SourceResolver {
	/** @var array<string,bool>|null */
	private $trusted_acf_keys;

	/**
	 * Fetch a stable page of source-bearing post IDs.
	 */
	public function candidate_post_ids( int $after_id, int $limit, int $high_water_mark = 0 ): array {
		global $wpdb;

		$post_types = $this->eligible_post_types();
		if ( empty( $post_types ) ) {
			return [];
		}

		$limit       = max( 1, min( Config::MAX_BATCH_SIZE, $limit ) );
		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$upper_sql    = $high_water_mark > 0 ? ' AND pm.post_id <= %d' : '';
		$sql          = "SELECT DISTINCT pm.post_id
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.post_id > %d
				{$upper_sql}
				AND p.post_type IN ({$placeholders})
				AND p.post_status <> 'auto-draft'
				AND (
					(pm.meta_key = %s AND pm.meta_value <> '')
					OR (
						pm.meta_key = %s AND pm.meta_value <> ''
						AND EXISTS (
							SELECT 1 FROM {$wpdb->postmeta} acf_ref
							WHERE acf_ref.post_id = pm.post_id
								AND acf_ref.meta_key = %s
								AND LEFT(acf_ref.meta_value, 6) = 'field_'
						)
					)
				)
			ORDER BY pm.post_id ASC
			LIMIT %d";

		$args = [ $after_id ];
		if ( $high_water_mark > 0 ) {
			$args[] = $high_water_mark;
		}
		$args = array_merge(
			$args,
			$post_types,
			[ Config::LEGACY_META, Config::ACF_META, Config::ACF_REFERENCE_META, $limit ]
		);

		$prepared = $wpdb->prepare( $sql, $args );
		return array_map( 'intval', (array) $wpdb->get_col( $prepared ) );
	}

	public function max_candidate_post_id(): int {
		global $wpdb;

		$post_types = $this->eligible_post_types();
		if ( empty( $post_types ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$sql          = "SELECT MAX(pm.post_id)
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_type IN ({$placeholders})
				AND p.post_status <> 'auto-draft'
				AND (
					(pm.meta_key = %s AND pm.meta_value <> '')
					OR (
						pm.meta_key = %s AND pm.meta_value <> ''
						AND EXISTS (
							SELECT 1 FROM {$wpdb->postmeta} acf_ref
							WHERE acf_ref.post_id = pm.post_id
								AND acf_ref.meta_key = %s
								AND LEFT(acf_ref.meta_value, 6) = 'field_'
						)
					)
				)";

		$args = array_merge( $post_types, [ Config::LEGACY_META, Config::ACF_META, Config::ACF_REFERENCE_META ] );
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Inspect one post and decide whether it is ready, already migrated, or a
	 * conflict. The target's mere existence is authoritative, including an empty
	 * target value.
	 */
	public function inspect( int $post_id ): array {
		$legacy_raw = get_post_meta( $post_id, Config::LEGACY_META, false );
		$acf_raw    = get_post_meta( $post_id, Config::ACF_META, false );
		$acf_refs   = get_post_meta( $post_id, Config::ACF_REFERENCE_META, false );

		$legacy = $this->normalize_values( $legacy_raw, true );
		$acf_values = $this->normalize_values( $acf_raw, false );
		$references = $this->classify_acf_references( $acf_refs );
		$acf        = ! empty( $references['trusted'] ) && empty( $references['unverified'] )
			? $acf_values
			: [ 'values' => [], 'invalid' => $acf_values['invalid'], 'acf_references' => [] ];

		$warnings = [];
		if ( ! empty( $legacy['acf_references'] ) ) {
			$warnings[] = 'legacy_key_contains_acf_reference';
		}
		if ( ! empty( $legacy['invalid'] ) || ! empty( $acf['invalid'] ) ) {
			$warnings[] = 'non_scalar_source_value';
		}
		if ( ! empty( $references['unverified'] ) ) {
			$warnings[] = 'unverified_acf_field_reference';
		}

		if ( ! empty( $acf_values['values'] ) && ! empty( $references['unverified'] ) ) {
			$acf_values['acf_references'] = $references['unverified'];
			return $this->conflict( $post_id, 'unverified_acf_field_reference', $legacy, $acf_values, $warnings );
		}

		if ( count( $legacy['values'] ) > 1 ) {
			return $this->conflict( $post_id, 'multiple_legacy_values', $legacy, $acf, $warnings );
		}
		if ( count( $acf['values'] ) > 1 ) {
			return $this->conflict( $post_id, 'multiple_acf_values', $legacy, $acf, $warnings );
		}

		$legacy_value = isset( $legacy['values'][0] ) ? $legacy['values'][0] : null;
		$acf_value    = isset( $acf['values'][0] ) ? $acf['values'][0] : null;

		if ( null !== $legacy_value && null !== $acf_value && $legacy_value !== $acf_value ) {
			return $this->conflict( $post_id, 'sources_disagree', $legacy, $acf, $warnings );
		}

		$candidate = null !== $legacy_value ? $legacy_value : $acf_value;
		$sources   = [];
		if ( null !== $legacy_value ) {
			$sources[] = Config::LEGACY_META;
		}
		if ( null !== $acf_value ) {
			$sources[] = Config::ACF_META;
		}

		if ( null === $candidate ) {
			$reason = ! empty( $legacy['acf_references'] )
				? 'legacy_value_is_acf_reference'
				: 'no_usable_source';
			return $this->conflict( $post_id, $reason, $legacy, $acf, $warnings );
		}

		$target_value = Config::sanitize_target_value( $candidate, $post_id );
		if ( '' === $target_value && '' !== $candidate ) {
			return $this->conflict( $post_id, 'target_sanitized_to_empty', $legacy, $acf, $warnings );
		}

		$result = [
			'post_id'          => $post_id,
			'status'           => 'ready',
			'reason'           => '',
			'sources'          => $sources,
			'source_value'     => $candidate,
			'target_value'     => $target_value,
			'source_hash'      => Config::value_hash( $candidate ),
			'target_hash'      => Config::value_hash( $target_value ),
			'value_transformed' => $candidate !== $target_value,
			'warnings'         => $warnings,
		];

		if ( ! metadata_exists( 'post', $post_id, Config::TARGET_META ) ) {
			return $result;
		}

		$target_values = get_post_meta( $post_id, Config::TARGET_META, false );
		if ( 1 !== count( $target_values ) ) {
			$result['status']        = 'conflict';
			$result['reason']        = 'multiple_target_values';
			$result['target_values'] = $target_values;
			return $result;
		}

		$result['existing_target'] = $target_values[0];
		if ( $target_values[0] === $target_value ) {
			$result['status'] = 'existing_same';
			$result['reason'] = 'target_already_matches';
			return $result;
		}

		$result['status'] = 'conflict';
		$result['reason'] = 'target_exists_different';
		return $result;
	}

	/**
	 * Build a plaintext-free fingerprint for one reviewed inspection.
	 *
	 * Source and target values are represented only by the hashes already used by
	 * the migration journal. Conflict details are reduced to a deterministic hash
	 * so the frozen manifest never becomes another copy of legacy content.
	 *
	 * @return array<string,mixed>
	 */
	public function fingerprint( array $inspection ): array {
		$post_id = (int) ( $inspection['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		$sources = isset( $inspection['sources'] ) && is_array( $inspection['sources'] )
			? array_values( array_map( 'sanitize_key', $inspection['sources'] ) )
			: array();
		sort( $sources, SORT_STRING );

		$status = sanitize_key( (string) ( $inspection['status'] ?? 'conflict' ) );
		$reason = sanitize_key( (string) ( $inspection['reason'] ?? '' ) );
		$target_exists = metadata_exists( 'post', $post_id, Config::TARGET_META );
		$target_values = isset( $inspection['target_values'] ) && is_array( $inspection['target_values'] )
			? $inspection['target_values']
			: ( $target_exists ? get_post_meta( $post_id, Config::TARGET_META, false ) : array() );
		$eligible_types = $this->eligible_post_types();

		return [
			'post_exists'  => $post instanceof \WP_Post,
			'post_type'    => $post instanceof \WP_Post ? sanitize_key( (string) $post->post_type ) : '',
			'post_status'  => $post instanceof \WP_Post ? sanitize_key( (string) $post->post_status ) : '',
			'eligible'     => $post instanceof \WP_Post && 'auto-draft' !== $post->post_status && in_array( $post->post_type, $eligible_types, true ),
			'status'        => $status,
			'reason'        => $reason,
			'sources'       => $sources,
			'source_hash'   => is_scalar( $inspection['source_hash'] ?? null ) ? (string) $inspection['source_hash'] : '',
			'target_hash'   => is_scalar( $inspection['target_hash'] ?? null ) ? (string) $inspection['target_hash'] : '',
			'target_exists' => $target_exists,
			'detail_hash'   => Config::value_hash(
				[
					'legacy'  => (array) ( $inspection['legacy_values'] ?? array() ),
					'acf'     => (array) ( $inspection['acf_values'] ?? array() ),
					'targets' => $target_values,
					'warnings'=> (array) ( $inspection['warnings'] ?? array() ),
				]
			),
		];
	}

	/** Whether an inspection still matches the exact state reviewed by a scan. */
	public function fingerprint_matches( array $inspection, array $expected ): bool {
		return hash_equals(
			Config::value_hash( $expected ),
			Config::value_hash( $this->fingerprint( $inspection ) )
		);
	}

	public function conflict_record( array $inspection ): array {
		$post_id = isset( $inspection['post_id'] ) ? (int) $inspection['post_id'] : 0;
		$post    = get_post( $post_id );

		return [
			'post_id'         => $post_id,
			'post_title'      => $post ? (string) $post->post_title : '',
			'post_type'       => $post ? (string) $post->post_type : '',
			'reason'          => isset( $inspection['reason'] ) ? (string) $inspection['reason'] : 'unknown',
			'legacy_values'   => isset( $inspection['legacy_values'] ) ? $inspection['legacy_values'] : [],
			'acf_values'      => isset( $inspection['acf_values'] ) ? $inspection['acf_values'] : [],
			'target_values'   => isset( $inspection['target_values'] ) ? $inspection['target_values'] : [],
			'warnings'        => isset( $inspection['warnings'] ) ? $inspection['warnings'] : [],
			'recorded_at'     => Config::now(),
		];
	}

	public function eligible_post_types(): array {
		$post_types = get_post_types( [ 'show_ui' => true ], 'names' );
		$post_types = array_values( array_diff( (array) $post_types, [ 'attachment', 'revision' ] ) );
		$post_types = apply_filters( 'wptl_migration_post_types', $post_types );
		$post_types = is_array( $post_types ) ? $post_types : [];

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $post_types ) ) ) );
	}

	private function normalize_values( array $raw_values, bool $recognize_acf_references ): array {
		$values         = [];
		$invalid        = [];
		$acf_references = [];

		foreach ( $raw_values as $value ) {
			if ( $recognize_acf_references && Config::is_acf_field_key( $value ) ) {
				$acf_references[] = $value;
				continue;
			}

			if ( ! is_scalar( $value ) || is_bool( $value ) ) {
				$invalid[] = gettype( $value );
				continue;
			}

			$value = (string) $value;
			if ( '' === $value ) {
				continue;
			}
			$values[] = $value;
		}

		return [
			'values'         => array_values( array_unique( $values ) ),
			'invalid'        => $invalid,
			'acf_references' => array_values( array_unique( $acf_references ) ),
		];
	}

	/**
	 * Separate verified ACF subtitle field keys from orphaned or forged keys.
	 *
	 * @return array{trusted:string[],unverified:string[]}
	 */
	private function classify_acf_references( array $references ): array {
		$trusted    = [];
		$unverified = [];

		foreach ( $references as $reference ) {
			if ( ! Config::is_acf_field_key( $reference ) ) {
				continue;
			}
			if ( $this->is_trusted_acf_key( $reference ) ) {
				$trusted[] = $reference;
			} else {
				$unverified[] = $reference;
			}
		}

		return [
			'trusted'    => array_values( array_unique( $trusted ) ),
			'unverified' => array_values( array_unique( $unverified ) ),
		];
	}

	private function is_trusted_acf_key( string $field_key ): bool {
		global $wpdb;

		if ( null === $this->trusted_acf_keys ) {
			$keys = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_name FROM {$wpdb->posts}
					WHERE post_type = 'acf-field'
						AND post_status NOT IN ('trash', 'auto-draft')
						AND post_excerpt = %s",
					Config::ACF_META
				)
			);
			$this->trusted_acf_keys = [];
			foreach ( (array) $keys as $key ) {
				if ( Config::is_acf_field_key( $key ) ) {
					$this->trusted_acf_keys[ $key ] = true;
				}
			}
		}

		if ( isset( $this->trusted_acf_keys[ $field_key ] ) ) {
			return true;
		}

		if ( function_exists( 'acf_get_field' ) ) {
			$field = acf_get_field( $field_key );
			if ( is_array( $field ) && Config::ACF_META === (string) ( $field['name'] ?? '' ) ) {
				$this->trusted_acf_keys[ $field_key ] = true;
				return true;
			}
		}

		return false;
	}

	private function conflict( int $post_id, string $reason, array $legacy, array $acf, array $warnings ): array {
		return [
			'post_id'       => $post_id,
			'status'        => 'conflict',
			'reason'        => $reason,
			'legacy_values' => $legacy['values'],
			'acf_values'    => $acf['values'],
			'target_values' => metadata_exists( 'post', $post_id, Config::TARGET_META )
				? get_post_meta( $post_id, Config::TARGET_META, false )
				: [],
			'warnings'      => $warnings,
		];
	}
}
