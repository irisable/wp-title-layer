<?php
/**
 * Idempotent, journaled batch migration into wptl_subtitle.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Migration;

final class Migrator {
	private $resolver;
	private $store;

	public function __construct( ?SourceResolver $resolver = null, ?Store $store = null ) {
		$this->resolver = $resolver ?: new SourceResolver();
		$this->store    = $store ?: new Store();
	}

	/**
	 * Start a run from the exact candidate manifest committed by a saved scan.
	 * Values are re-read before writing, but any state change becomes a conflict
	 * instead of silently expanding or changing the reviewed batch.
	 *
	 * @return array|\WP_Error
	 */
	public function start_run( array $args = [] ) {
		$global_token = $this->store->acquire_global_lock();
		if ( false === $global_token ) {
			return new \WP_Error( 'wptl_migration_control_locked', __( 'Another migration control action is in progress.', 'wp-title-layer' ) );
		}

		try {
			$active_id = Config::clean_run_id( (string) get_option( Config::ACTIVE_RUN_OPTION, '' ) );
			if ( '' !== $active_id ) {
				$active_run = $this->store->get_run( $active_id );
				if ( $active_run && in_array( $active_run['status'], [ 'running', 'rollback_running' ], true ) ) {
					return new \WP_Error( 'wptl_migration_active', __( 'A migration or rollback is already running.', 'wp-title-layer' ) );
				}
				$this->store->clear_active_run( $active_id );
			}

			$scan = $this->validated_scan( $args );
			if ( is_wp_error( $scan ) ) {
				return $scan;
			}
			if ( ! $this->store->refresh_global_lock( $global_token ) ) {
				return new \WP_Error( 'wptl_migration_control_lock_lost', __( 'The migration control lock changed ownership before the run was created.', 'wp-title-layer' ) );
			}
			if ( ! $this->store->verify_manifest(
				(string) $scan['scan_id'],
				(int) $scan['manifest_chunks'],
				(int) $scan['manifest_count'],
				(string) $scan['manifest_hash']
			) ) {
				return new \WP_Error( 'wptl_migration_manifest_invalid', __( 'The reviewed migration scan is incomplete or has changed. Run the scan again.', 'wp-title-layer' ) );
			}
			if ( ! $this->store->refresh_global_lock( $global_token ) ) {
				return new \WP_Error( 'wptl_migration_control_lock_lost', __( 'The migration control lock changed ownership before the run was created.', 'wp-title-layer' ) );
			}

			$run_id = Config::new_run_id();
			$run    = [
				'id'              => $run_id,
				'schema_version'  => Config::SCHEMA_VERSION,
				'status'          => 'running',
				'target_meta'     => Config::TARGET_META,
				'source_meta'     => [ Config::LEGACY_META, Config::ACF_META ],
				'created_at'      => Config::now(),
				'updated_at'      => Config::now(),
				'created_by'      => get_current_user_id(),
				'high_water_mark' => max( 0, (int) ( $scan['high_water_mark'] ?? 0 ) ),
				'scan_id'         => (string) $scan['scan_id'],
				'manifest_count'  => (int) $scan['manifest_count'],
				'manifest_chunks' => (int) $scan['manifest_chunks'],
				'manifest_hash'   => (string) $scan['manifest_hash'],
				'manifest_cursor' => 0,
				'cursor'          => 0,
				'next_batch'      => 1,
				'batch_size'      => $this->normalize_batch_size( isset( $args['batch_size'] ) ? (int) $args['batch_size'] : Config::DEFAULT_BATCH_SIZE ),
				'counts'          => $this->empty_counts(),
				'rollback'        => null,
			];

			if ( ! $this->store->add_run( $run, $global_token ) ) {
				return new \WP_Error( 'wptl_migration_create_failed', __( 'Could not create the migration journal.', 'wp-title-layer' ) );
			}

			do_action( 'wptl_migration_run_started', $run );
			return $run;
		} finally {
			$this->store->release_global_lock( $global_token );
		}
	}

	/**
	 * Process or safely resume one journaled batch.
	 *
	 * @return array|\WP_Error
	 */
	public function process_batch( string $run_id, ?int $requested_size = null ) {
		$run_id = Config::clean_run_id( $run_id );
		if ( '' === $run_id ) {
			return new \WP_Error( 'wptl_migration_invalid_id', __( 'The migration run ID is invalid.', 'wp-title-layer' ) );
		}
		$lock_token = $this->store->acquire_lock( $run_id );
		if ( false === $lock_token ) {
			return new \WP_Error( 'wptl_migration_locked', __( 'This migration batch is already being processed.', 'wp-title-layer' ) );
		}

		try {
			$run = $this->store->get_run( $run_id );
			if ( ! $run ) {
				return new \WP_Error( 'wptl_migration_not_found', __( 'Migration run not found.', 'wp-title-layer' ) );
			}
			if ( 'complete' === $run['status'] ) {
				if ( $this->store->refresh_lock( $run_id, $lock_token ) ) {
					$this->store->clear_active_run( $run_id );
				}
				return $run;
			}
			if ( 'running' !== $run['status'] ) {
				return new \WP_Error( 'wptl_migration_bad_state', __( 'This migration is not in a runnable state.', 'wp-title-layer' ) );
			}
			if ( $run_id !== Config::clean_run_id( (string) get_option( Config::ACTIVE_RUN_OPTION, '' ) ) ) {
				return new \WP_Error( 'wptl_migration_inactive', __( 'This migration is no longer the active run and was stopped safely.', 'wp-title-layer' ) );
			}
			if ( ! $this->run_has_manifest( $run ) ) {
				return new \WP_Error( 'wptl_migration_legacy_run_requires_rollback', __( 'This run predates frozen scan manifests. Roll it back, then scan again before migrating.', 'wp-title-layer' ) );
			}

			$batch = max( 1, (int) $run['next_batch'] );
			$size  = $this->normalize_batch_size( null === $requested_size ? (int) $run['batch_size'] : $requested_size );
			$log   = $this->store->get_log( $run_id, $batch );

			if ( $log && 'complete' === $log['status'] ) {
				return $this->advance_completed_batch( $run, $log, $lock_token );
			}

			if ( ! $log ) {
				$offset = max( 0, (int) ( $run['manifest_cursor'] ?? $run['cursor'] ?? 0 ) );
				$total  = max( 0, (int) ( $run['manifest_count'] ?? -1 ) );
				if ( $offset >= $total ) {
					return $this->complete_run( $run, $lock_token );
				}
				$manifest_records = $this->store->get_manifest_slice(
					(string) ( $run['scan_id'] ?? '' ),
					$offset,
					$size,
					$total
				);
				if ( empty( $manifest_records ) ) {
					return new \WP_Error( 'wptl_migration_manifest_unavailable', __( 'The frozen migration candidate list is unavailable; processing stopped without writing.', 'wp-title-layer' ) );
				}

				$candidates = array();
				foreach ( $manifest_records as $manifest_record ) {
					$post_id     = (int) ( $manifest_record['post_id'] ?? 0 );
					$fingerprint = $manifest_record['fingerprint'] ?? null;
					if ( $post_id < 1 || ! is_array( $fingerprint ) || isset( $candidates[ (string) $post_id ] ) ) {
						return new \WP_Error( 'wptl_migration_manifest_invalid', __( 'The frozen migration candidate list is invalid; processing stopped without writing.', 'wp-title-layer' ) );
					}
					$candidates[ (string) $post_id ] = $fingerprint;
				}
				$ids = array_map( 'intval', array_keys( $candidates ) );

				$log = [
					'run_id'        => $run_id,
					'batch'         => $batch,
					'status'        => 'running',
					'started_at'    => Config::now(),
					'finished_at'   => null,
					'cursor_start'  => $offset,
					'cursor_end'    => $offset + count( $ids ),
					'candidate_ids' => $ids,
					'candidates'    => $candidates,
					'records'       => [],
					'counts'        => $this->empty_counts(),
				];
				if ( ! $this->store->refresh_lock( $run_id, $lock_token ) || ! $this->store->save_log( $run_id, $batch, $log ) ) {
					return new \WP_Error( 'wptl_migration_log_failed', __( 'Could not initialize the migration batch journal.', 'wp-title-layer' ) );
				}
				if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
					return $this->lock_lost_error();
				}
			}

			$conflicts = $this->store->get_conflicts( $run_id, $batch );
			foreach ( (array) $log['candidate_ids'] as $post_id ) {
				if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
					return new \WP_Error( 'wptl_migration_lock_lost', __( 'The migration lock expired or changed ownership; the batch stopped safely.', 'wp-title-layer' ) );
				}
				$post_id = (int) $post_id;
				$key     = (string) $post_id;
				$expected = isset( $log['candidates'][ $key ] ) && is_array( $log['candidates'][ $key ] )
					? $log['candidates'][ $key ]
					: null;
				if ( null === $expected ) {
					return new \WP_Error( 'wptl_migration_manifest_invalid', __( 'The batch journal is missing its reviewed candidate state.', 'wp-title-layer' ) );
				}
				if ( isset( $log['records'][ $key ] ) && $this->is_final_record( $log['records'][ $key ] ) ) {
					continue;
				}

				if ( isset( $log['records'][ $key ] ) && 'pending' === $log['records'][ $key ]['status'] ) {
					$error = $this->resume_pending_record( $run_id, $batch, $post_id, $expected, $log, $conflicts, $lock_token );
					if ( is_wp_error( $error ) ) {
						return $error;
					}
					continue;
				}

				$error = $this->process_post( $run_id, $batch, $post_id, $expected, $log, $conflicts, $lock_token );
				if ( is_wp_error( $error ) ) {
					return $error;
				}
			}

			$log['counts']      = $this->count_records( $log['records'] );
			$log['status']      = 'complete';
			$log['finished_at'] = Config::now();
			$error = $this->persist_progress( $run_id, $batch, $log, $conflicts, $lock_token, true );
			if ( is_wp_error( $error ) ) {
				return $error;
			}

			do_action( 'wptl_migration_batch_completed', $run_id, $batch, $log['counts'] );
			if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
				return $this->lock_lost_error();
			}
			return $this->advance_completed_batch( $run, $log, $lock_token );
		} finally {
			$this->store->release_lock( $run_id, $lock_token );
		}
	}

	private function process_post( string $run_id, int $batch, int $post_id, array $expected, array &$log, array &$conflicts, string $lock_token ) {
		$inspection = $this->resolver->inspect( $post_id );
		$key        = (string) $post_id;
		if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
			return $this->lock_lost_error();
		}
		if ( ! $this->resolver->fingerprint_matches( $inspection, $expected ) ) {
			$inspection['status']  = 'conflict';
			$inspection['reason']  = 'state_changed_after_scan';
			$log['records'][ $key ] = $this->final_record_from_inspection( $inspection );
			$conflicts[ $key ]       = $this->resolver->conflict_record( $inspection );
			return $this->persist_progress( $run_id, $batch, $log, $conflicts, $lock_token, true );
		}

		if ( 'ready' !== $inspection['status'] ) {
			$log['records'][ $key ] = $this->final_record_from_inspection( $inspection );
			if ( 'conflict' === $inspection['status'] ) {
				$conflicts[ $key ] = $this->resolver->conflict_record( $inspection );
			}
			return $this->persist_progress( $run_id, $batch, $log, $conflicts, $lock_token, true );
		}

		// Pre-journal the intent before writing. A crash can therefore be safely
		// recovered even if it occurs immediately after add_post_meta().
		$log['records'][ $key ] = [
			'post_id'       => $post_id,
			'status'        => 'pending',
			'reason'        => '',
			'sources'       => $inspection['sources'],
			'source_hash'   => $inspection['source_hash'],
			'target_hash'   => $inspection['target_hash'],
			'transformed'   => ! empty( $inspection['value_transformed'] ),
			'planned_at'    => Config::now(),
		];
		$error = $this->persist_progress( $run_id, $batch, $log, $conflicts, $lock_token, false );
		if ( is_wp_error( $error ) ) {
			$log['records'][ $key ]['status'] = 'error';
			$log['records'][ $key ]['reason'] = 'intent_journal_failed';
			return $error;
		}

		// The intent journal is a hook boundary. Re-read through a fresh resolver
		// so source, ACF trust, post eligibility, and target absence must still be
		// exactly the state authorized by the scan before the write begins.
		$before_write_resolver = new SourceResolver();
		$before_write          = $before_write_resolver->inspect( $post_id );
		if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
			return $this->lock_lost_error();
		}
		if ( ! $before_write_resolver->fingerprint_matches( $before_write, $expected ) ) {
			$before_write['status']  = 'conflict';
			$before_write['reason']  = 'state_changed_after_intent';
			$log['records'][ $key ] = $this->final_record_from_inspection( $before_write );
			$conflicts[ $key ]       = $before_write_resolver->conflict_record( $before_write );
			return $this->persist_progress( $run_id, $batch, $log, $conflicts, $lock_token, true );
		}

		$meta_id = add_post_meta( $post_id, Config::TARGET_META, $before_write['target_value'], true );
		if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
			return $this->lock_lost_error();
		}
		if ( false === $meta_id ) {
			$after_resolver = new SourceResolver();
			$after          = $after_resolver->inspect( $post_id );
			$target_rows    = $this->target_rows( $post_id );
			if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
				return $this->lock_lost_error();
			}
			if ( 1 === count( $target_rows ) && $this->post_write_state_matches( $after_resolver, $after, $expected ) ) {
				$log['records'][ $key ] = $this->final_record_from_inspection( $after );
			} else {
				$after['status']         = 'conflict';
				$after['reason']         = 'target_created_concurrently';
				$log['records'][ $key ] = $this->final_record_from_inspection( $after );
				$conflicts[ $key ]       = $after_resolver->conflict_record( $after );
			}
			return $this->persist_progress( $run_id, $batch, $log, $conflicts, $lock_token, true );
		}

		$target_rows = $this->target_rows( $post_id );
		$owned       = 1 === count( $target_rows )
			&& (int) ( $target_rows[0]['meta_id'] ?? 0 ) === (int) $meta_id
			&& hash_equals( (string) $before_write['target_hash'], Config::value_hash( maybe_unserialize( $target_rows[0]['meta_value'] ?? null ) ) );
		$after_resolver = new SourceResolver();
		$after          = $after_resolver->inspect( $post_id );
		$state_matches  = $this->post_write_state_matches( $after_resolver, $after, $expected );
		if ( ! $owned || ! $state_matches ) {
			$after['status']         = 'conflict';
			$after['reason']         = $owned ? 'state_changed_during_write' : 'target_changed_during_write';
			$record                  = $this->final_record_from_inspection( $after );
			$record['meta_id']       = (int) $meta_id;
			$record['value_hash']    = (string) $before_write['target_hash'];
			$record['owned_write']   = $this->target_row_is_owned( $target_rows, (int) $meta_id, (string) $before_write['target_hash'] );
			$log['records'][ $key ] = $record;
			$conflicts[ $key ]       = $after_resolver->conflict_record( $after );
			return $this->persist_progress( $run_id, $batch, $log, $conflicts, $lock_token, true );
		}

		$log['records'][ $key ] = [
			'post_id'       => $post_id,
			'status'        => 'migrated',
			'reason'        => '',
			'sources'       => $inspection['sources'],
			'source_hash'   => $before_write['source_hash'],
			'value_hash'    => (string) $before_write['target_hash'],
			'meta_id'       => (int) $meta_id,
			'transformed'   => ! empty( $inspection['value_transformed'] ),
			'written_at'    => Config::now(),
		];
		return $this->persist_progress( $run_id, $batch, $log, $conflicts, $lock_token, false );
	}

	private function resume_pending_record( string $run_id, int $batch, int $post_id, array $expected, array &$log, array &$conflicts, string $lock_token ) {
		$key       = (string) $post_id;
		$pending   = $log['records'][ $key ];
		$rows      = $this->target_rows( $post_id );
		$matches   = $this->matching_target_rows( $post_id, (string) $pending['target_hash'] );
		$resolver  = new SourceResolver();
		$current   = $resolver->inspect( $post_id );
		if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
			return $this->lock_lost_error();
		}

		if ( 1 === count( $rows ) && 1 === count( $matches ) && $this->post_write_state_matches( $resolver, $current, $expected ) ) {
			$log['records'][ $key ] = array_merge(
				$pending,
				[
					'status'     => 'existing_same',
					'reason'     => 'matching_target_after_interruption_not_owned',
					'value_hash' => (string) $pending['target_hash'],
					'recovered'  => true,
					'recorded_at'=> Config::now(),
				]
			);
			return $this->persist_progress( $run_id, $batch, $log, $conflicts, $lock_token, false );
		}

		if ( ! empty( $rows ) ) {
			$inspection            = $current;
			if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
				return $this->lock_lost_error();
			}
			$inspection['status']  = 'conflict';
			$inspection['reason']  = count( $rows ) > 1 || count( $matches ) > 1 ? 'ambiguous_pending_write' : 'state_changed_after_pending';
			$log['records'][ $key ] = $this->final_record_from_inspection( $inspection );
			$conflicts[ $key ]       = $resolver->conflict_record( $inspection );
			return $this->persist_progress( $run_id, $batch, $log, $conflicts, $lock_token, true );
		}

		$inspection = $current;
		if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
			return $this->lock_lost_error();
		}
		if ( ! $resolver->fingerprint_matches( $inspection, $expected )
			|| $inspection['source_hash'] !== $pending['source_hash']
			|| $inspection['target_hash'] !== $pending['target_hash'] ) {
			$inspection['status'] = 'conflict';
			$inspection['reason'] = 'source_changed_after_pending';
			$log['records'][ $key ] = $this->final_record_from_inspection( $inspection );
			$conflicts[ $key ]       = $resolver->conflict_record( $inspection );
			return $this->persist_progress( $run_id, $batch, $log, $conflicts, $lock_token, true );
		}

		// The journal is still valid and no target exists; reuse the normal write
		// path after removing only the pending in-memory record.
		unset( $log['records'][ $key ] );
		return $this->process_post( $run_id, $batch, $post_id, $expected, $log, $conflicts, $lock_token );
	}

	private function advance_completed_batch( array $run, array $log, string $lock_token ) {
		$run['manifest_cursor'] = (int) $log['cursor_end'];
		$run['cursor']     = (int) $log['cursor_end'];
		$run['next_batch'] = (int) $log['batch'] + 1;
		$run['counts']     = $this->aggregate_counts( $run['id'], (int) $log['batch'] );
		if ( ! $this->store->refresh_lock( $run['id'], $lock_token ) ) {
			return $this->lock_lost_error();
		}
		if ( ! $this->store->save_run( $run ) ) {
			return new \WP_Error( 'wptl_migration_state_write_failed', __( 'Could not advance the migration state; the completed journal remains safe to retry.', 'wp-title-layer' ) );
		}

		return (int) $run['manifest_cursor'] >= max( 0, (int) ( $run['manifest_count'] ?? 0 ) )
			? $this->complete_run( $run, $lock_token )
			: $run;
	}

	private function complete_run( array $run, string $lock_token ) {
		$run['status']       = 'complete';
		$run['completed_at'] = Config::now();
		$run['counts']       = $this->aggregate_counts( $run['id'], max( 0, (int) $run['next_batch'] - 1 ) );
		if ( ! $this->store->refresh_lock( $run['id'], $lock_token ) ) {
			return $this->lock_lost_error();
		}
		if ( ! $this->store->save_run( $run ) ) {
			return new \WP_Error( 'wptl_migration_completion_write_failed', __( 'Could not record migration completion; the active marker was preserved for a safe retry.', 'wp-title-layer' ) );
		}
		if ( ! $this->store->refresh_lock( $run['id'], $lock_token ) ) {
			return $this->lock_lost_error();
		}
		$this->store->clear_active_run( $run['id'] );
		do_action( 'wptl_migration_run_completed', $run );

		return $run;
	}

	private function persist_progress( string $run_id, int $batch, array $log, array $conflicts, string $lock_token, bool $save_conflicts ) {
		if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
			return $this->lock_lost_error();
		}
		if ( $save_conflicts && ! $this->store->save_conflicts( $run_id, $batch, $conflicts ) ) {
			return new \WP_Error( 'wptl_migration_conflict_log_failed', __( 'Could not save the migration conflict report; the batch stopped safely.', 'wp-title-layer' ) );
		}
		if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
			return $this->lock_lost_error();
		}
		if ( ! $this->store->save_log( $run_id, $batch, $log ) ) {
			return new \WP_Error( 'wptl_migration_log_failed', __( 'Could not save the migration batch journal.', 'wp-title-layer' ) );
		}
		if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
			return $this->lock_lost_error();
		}

		return null;
	}

	private function lock_lost_error(): \WP_Error {
		return new \WP_Error( 'wptl_migration_lock_lost', __( 'The migration lock expired or changed ownership; the batch stopped safely.', 'wp-title-layer' ) );
	}

	private function aggregate_counts( string $run_id, int $last_batch ): array {
		$total = $this->empty_counts();
		for ( $batch = 1; $batch <= $last_batch; ++$batch ) {
			$log = $this->store->get_log( $run_id, $batch );
			if ( ! $log || 'complete' !== $log['status'] ) {
				continue;
			}
			$counts = isset( $log['counts'] ) ? $log['counts'] : $this->count_records( isset( $log['records'] ) ? $log['records'] : [] );
			foreach ( $total as $key => $value ) {
				if ( 'batches' !== $key ) {
					$total[ $key ] += isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
				}
			}
			++$total['batches'];
		}

		return $total;
	}

	private function count_records( array $records ): array {
		$counts = $this->empty_counts();
		foreach ( $records as $record ) {
			++$counts['processed'];
			$status = isset( $record['status'] ) ? $record['status'] : 'error';
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			} else {
				++$counts['errors'];
			}
			if ( ! empty( $record['transformed'] ) ) {
				++$counts['transformed'];
			}
		}

		return $counts;
	}

	private function empty_counts(): array {
		return [
			'processed'     => 0,
			'migrated'      => 0,
			'existing_same' => 0,
			'conflict'      => 0,
			'errors'        => 0,
			'transformed'   => 0,
			'batches'       => 0,
		];
	}

	private function final_record_from_inspection( array $inspection ): array {
		return [
			'post_id'     => isset( $inspection['post_id'] ) ? (int) $inspection['post_id'] : 0,
			'status'      => isset( $inspection['status'] ) ? (string) $inspection['status'] : 'error',
			'reason'      => isset( $inspection['reason'] ) ? (string) $inspection['reason'] : '',
			'sources'     => isset( $inspection['sources'] ) ? $inspection['sources'] : [],
			'source_hash' => isset( $inspection['source_hash'] ) ? $inspection['source_hash'] : '',
			'target_hash' => isset( $inspection['target_hash'] ) ? $inspection['target_hash'] : '',
			'transformed' => ! empty( $inspection['value_transformed'] ),
			'recorded_at' => Config::now(),
		];
	}

	private function matching_target_rows( int $post_id, string $hash ): array {
		$matches = [];
		foreach ( $this->target_rows( $post_id ) as $row ) {
			$value = maybe_unserialize( $row['meta_value'] );
			if ( Config::value_hash( $value ) === $hash ) {
				$matches[] = $row;
			}
		}

		return $matches;
	}

	/** @return array<int,array<string,mixed>> */
	private function target_rows( int $post_id ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
				$post_id,
				Config::TARGET_META
			),
			ARRAY_A
		);
	}

	private function target_row_is_owned( array $rows, int $meta_id, string $hash ): bool {
		foreach ( $rows as $row ) {
			if ( (int) ( $row['meta_id'] ?? 0 ) === $meta_id
				&& hash_equals( $hash, Config::value_hash( maybe_unserialize( $row['meta_value'] ?? null ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	private function post_write_state_matches( SourceResolver $resolver, array $inspection, array $expected ): bool {
		if ( 'existing_same' !== (string) ( $inspection['status'] ?? '' ) ) {
			return false;
		}

		$current = $resolver->fingerprint( $inspection );
		$fields  = array( 'post_exists', 'post_type', 'post_status', 'eligible', 'sources', 'source_hash', 'target_hash' );
		foreach ( $fields as $field ) {
			if ( Config::value_hash( $current[ $field ] ?? null ) !== Config::value_hash( $expected[ $field ] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	private function is_final_record( array $record ): bool {
		return isset( $record['status'] ) && in_array( $record['status'], [ 'migrated', 'existing_same', 'conflict', 'error' ], true );
	}

	private function run_has_manifest( array $run ): bool {
		$scan_id = Config::clean_run_id( is_scalar( $run['scan_id'] ?? null ) ? (string) $run['scan_id'] : '' );
		$count   = isset( $run['manifest_count'] ) ? (int) $run['manifest_count'] : -1;
		$chunks  = isset( $run['manifest_chunks'] ) ? (int) $run['manifest_chunks'] : -1;
		$hash    = is_scalar( $run['manifest_hash'] ?? null ) ? (string) $run['manifest_hash'] : '';

		return '' !== $scan_id
			&& $count >= 0
			&& $chunks === (int) ceil( $count / Config::SCAN_MANIFEST_CHUNK_SIZE )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $hash );
	}

	/** @return array|\WP_Error */
	private function validated_scan( array $args ) {
		$scan = get_option( Config::LAST_SCAN_OPTION, null );
		if ( ! is_array( $scan ) || ! AdminPage::canStartFromScan( $scan ) ) {
			return new \WP_Error( 'wptl_migration_scan_required', __( 'Run and save a complete migration scan before starting.', 'wp-title-layer' ) );
		}

		$requested_id = Config::clean_run_id( is_scalar( $args['scan_id'] ?? null ) ? (string) $args['scan_id'] : '' );
		$saved_id     = Config::clean_run_id( is_scalar( $scan['scan_id'] ?? null ) ? (string) $scan['scan_id'] : '' );
		if ( '' === $requested_id || ! hash_equals( $saved_id, $requested_id ) ) {
			return new \WP_Error( 'wptl_migration_scan_changed', __( 'The reviewed migration scan has changed. Review the latest scan before starting.', 'wp-title-layer' ) );
		}

		foreach ( array( 'manifest_count', 'manifest_chunks' ) as $field ) {
			if ( array_key_exists( $field, $args ) && (int) $args[ $field ] !== (int) $scan[ $field ] ) {
				return new \WP_Error( 'wptl_migration_scan_changed', __( 'The reviewed migration scan has changed. Review the latest scan before starting.', 'wp-title-layer' ) );
			}
		}
		if ( array_key_exists( 'manifest_hash', $args ) ) {
			$requested_hash = is_scalar( $args['manifest_hash'] ) ? strtolower( (string) $args['manifest_hash'] ) : '';
			if ( ! hash_equals( (string) $scan['manifest_hash'], $requested_hash ) ) {
				return new \WP_Error( 'wptl_migration_scan_changed', __( 'The reviewed migration scan has changed. Review the latest scan before starting.', 'wp-title-layer' ) );
			}
		}

		return $scan;
	}

	private function normalize_batch_size( int $size ): int {
		return max( 1, min( Config::MAX_BATCH_SIZE, $size ) );
	}
}
