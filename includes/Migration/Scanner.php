<?php
/**
 * Dry-run scanner for migration counts, conflicts and category distribution.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Migration;

final class Scanner {
	private $resolver;
	private $detector;
	private $manifest_callback;

	public function __construct( ?SourceResolver $resolver = null, ?Detector $detector = null, ?callable $manifest_callback = null ) {
		$this->resolver = $resolver ?: new SourceResolver();
		$this->detector = $detector ?: new Detector();
		$this->manifest_callback = $manifest_callback;
	}

	/**
	 * Perform a completely read-only migration preview.
	 */
	public function dry_run( int $max_posts = 100000 ): array {
		$max_posts = max( 1, (int) apply_filters( 'wptl_migration_scan_limit', $max_posts ) );
		$cursor    = 0;
		$scanned   = 0;
		$high      = $this->resolver->max_candidate_post_id();
		$stats     = [
			'candidate_posts' => 0,
			'ready'           => 0,
			'existing_same'   => 0,
			'conflicts'       => 0,
			'transformed'     => 0,
			'legacy_source'   => 0,
			'acf_source'      => 0,
			'both_sources'    => 0,
		];
		$reasons    = [];
		$post_types = [];
		$statuses   = [];
		$categories = [];
		$manifest_count        = 0;
		$manifest_hash_context = hash_init( 'sha256' );

		while ( $high > 0 && $scanned < $max_posts ) {
			$ids = $this->resolver->candidate_post_ids( $cursor, min( 250, $max_posts - $scanned ), $high );
			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $post_id ) {
				$inspection = $this->resolver->inspect( $post_id );
				$fingerprint = $this->resolver->fingerprint( $inspection );
				$post       = get_post( $post_id );
				++$stats['candidate_posts'];
				++$scanned;
				++$manifest_count;
				$manifest_record = [
					'post_id'     => (int) $post_id,
					'fingerprint' => $fingerprint,
				];
				$encoded = wp_json_encode( $manifest_record );
				hash_update( $manifest_hash_context, is_string( $encoded ) ? $encoded : '' );
				if ( is_callable( $this->manifest_callback ) ) {
					call_user_func( $this->manifest_callback, $manifest_record, $manifest_count );
				}

				$status = isset( $inspection['status'] ) ? $inspection['status'] : 'conflict';
				$stat_key = 'conflict' === $status ? 'conflicts' : $status;
				if ( isset( $stats[ $stat_key ] ) ) {
					++$stats[ $stat_key ];
				}
				if ( 'conflict' === $status ) {
					$reason = isset( $inspection['reason'] ) ? $inspection['reason'] : 'unknown';
					$reasons[ $reason ] = isset( $reasons[ $reason ] ) ? $reasons[ $reason ] + 1 : 1;
				}

				$sources = isset( $inspection['sources'] ) ? $inspection['sources'] : [];
				if ( 2 === count( $sources ) ) {
					++$stats['both_sources'];
				} elseif ( in_array( Config::LEGACY_META, $sources, true ) ) {
					++$stats['legacy_source'];
				} elseif ( in_array( Config::ACF_META, $sources, true ) ) {
					++$stats['acf_source'];
				}
				if ( ! empty( $inspection['value_transformed'] ) ) {
					++$stats['transformed'];
				}

				if ( $post ) {
					$post_types[ $post->post_type ] = isset( $post_types[ $post->post_type ] ) ? $post_types[ $post->post_type ] + 1 : 1;
					$statuses[ $post->post_status ] = isset( $statuses[ $post->post_status ] ) ? $statuses[ $post->post_status ] + 1 : 1;
				}

				foreach ( wp_get_post_categories( $post_id ) as $term_id ) {
					if ( ! isset( $categories[ $term_id ] ) ) {
						$term = get_term( $term_id, 'category' );
						if ( ! $term || is_wp_error( $term ) ) {
							continue;
						}
						$categories[ $term_id ] = [
							'term_id'       => (int) $term->term_id,
							'name'          => (string) $term->name,
							'slug'          => (string) $term->slug,
							'candidate_posts'=> 0,
							'ready'         => 0,
							'existing_same' => 0,
							'conflicts'     => 0,
						];
					}
					++$categories[ $term_id ]['candidate_posts'];
					if ( isset( $categories[ $term_id ][ $stat_key ] ) ) {
						++$categories[ $term_id ][ $stat_key ];
					}
				}
			}

			$cursor = max( $ids );
			if ( count( $ids ) < min( 250, $max_posts - ( $scanned - count( $ids ) ) ) ) {
				break;
			}
		}

		usort(
			$categories,
			static function ( array $left, array $right ): int {
				return $right['candidate_posts'] <=> $left['candidate_posts'];
			}
		);
		ksort( $reasons );
		ksort( $post_types );
		ksort( $statuses );

		$more = $high > 0 && ! empty( $this->resolver->candidate_post_ids( $cursor, 1, $high ) );

		return [
			'schema_version' => Config::SCHEMA_VERSION,
			'generated_at'   => Config::now(),
			'target_meta'    => Config::TARGET_META,
			'high_water_mark'=> $high,
			'manifest_count' => $manifest_count,
			'manifest_hash'  => hash_final( $manifest_hash_context ),
			'scanned_posts'  => $scanned,
			'truncated'      => $more,
			'stats'          => $stats,
			'conflict_reasons'=> $reasons,
			'post_types'     => $post_types,
			'post_statuses'  => $statuses,
			'categories'     => array_values( $categories ),
			'detection'      => $this->detector->detect(),
		];
	}
}
