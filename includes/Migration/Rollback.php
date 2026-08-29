<?php
/**
 * Safe rollback for metadata rows created by a migration run.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Migration;

final class Rollback {
	private $store;

	public function __construct( ?Store $store = null ) {
		$this->store = $store ?: new Store();
	}

	/**
	 * Put a migration run into rollback mode. Existing target rows skipped by the
	 * migrator are never owned by the run and therefore never eligible here.
	 *
	 * @return array|\WP_Error
	 */
	public function start( string $run_id ) {
		$run_id = Config::clean_run_id( $run_id );
		if ( '' === $run_id ) {
			return new \WP_Error( 'wptl_rollback_invalid_id', __( 'The migration run ID is invalid.', 'wp-title-layer' ) );
		}

		$global_token = $this->store->acquire_global_lock();
		if ( false === $global_token ) {
			return new \WP_Error( 'wptl_rollback_control_locked', __( 'Another migration control action is in progress.', 'wp-title-layer' ) );
		}

		$active_id = Config::clean_run_id( (string) get_option( Config::ACTIVE_RUN_OPTION, '' ) );
		if ( ! $this->store->refresh_global_lock( $global_token ) ) {
			$this->store->release_global_lock( $global_token );
			return $this->control_lock_lost_error();
		}
		if ( '' !== $active_id && $run_id !== $active_id ) {
			$active_run = $this->store->get_run( $active_id );
			if ( ! $this->store->refresh_global_lock( $global_token ) ) {
				$this->store->release_global_lock( $global_token );
				return $this->control_lock_lost_error();
			}
			if ( $active_run && in_array( $active_run['status'], [ 'running', 'rollback_running' ], true ) ) {
				$this->store->release_global_lock( $global_token );
				return new \WP_Error( 'wptl_migration_active', __( 'Another migration or rollback is already running.', 'wp-title-layer' ) );
			}
			$this->store->clear_active_run( $active_id );
			if ( ! $this->store->refresh_global_lock( $global_token ) ) {
				$this->store->release_global_lock( $global_token );
				return $this->control_lock_lost_error();
			}
		}

		$lock_token = $this->store->acquire_lock( $run_id );
		if ( false === $lock_token ) {
			$this->store->release_global_lock( $global_token );
			return new \WP_Error( 'wptl_rollback_locked', __( 'This migration is currently being processed.', 'wp-title-layer' ) );
		}

		try {
			$run = $this->store->get_run( $run_id );
			if ( ! $this->store->refresh_global_lock( $global_token ) || ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
				return $this->control_lock_lost_error();
			}
			if ( ! $run ) {
				return new \WP_Error( 'wptl_rollback_not_found', __( 'Migration run not found.', 'wp-title-layer' ) );
			}
			if ( 'rolled_back' === $run['status'] || 'rollback_complete' === $run['status'] ) {
				if ( ! $this->store->refresh_global_lock( $global_token ) || ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
					return $this->control_lock_lost_error();
				}
				$this->store->clear_active_run( $run_id );
				return $run;
			}
			if ( ! in_array( $run['status'], [ 'running', 'complete', 'rollback_running' ], true ) ) {
				return new \WP_Error( 'wptl_rollback_bad_state', __( 'This migration cannot be rolled back from its current state.', 'wp-title-layer' ) );
			}

			$current_active    = Config::clean_run_id( (string) get_option( Config::ACTIVE_RUN_OPTION, '' ) );
			$had_active_marker = $run_id === $current_active;
			if ( ! $this->store->refresh_global_lock( $global_token ) || ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
				return $this->control_lock_lost_error();
			}
			if ( ! $had_active_marker && ! $this->store->claim_active_run( $run_id ) ) {
				return new \WP_Error( 'wptl_rollback_active_write_failed', __( 'Could not reserve the active rollback marker.', 'wp-title-layer' ) );
			}

			if ( 'rollback_running' !== $run['status'] ) {
				$last_batch = max( 0, (int) $run['next_batch'] - 1 );
				if ( $this->store->get_log( $run_id, (int) $run['next_batch'] ) ) {
					$last_batch = (int) $run['next_batch'];
				}
				if ( ! $this->store->refresh_global_lock( $global_token ) || ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
					return $this->control_lock_lost_error();
				}

				$run['status']   = 'rollback_running';
				$run['rollback'] = [
					'started_at'    => Config::now(),
					'started_by'    => get_current_user_id(),
					'current_batch' => $last_batch,
					'counts'        => $this->empty_counts(),
				];
				if ( ! $this->store->refresh_global_lock( $global_token ) || ! $this->store->refresh_lock( $run_id, $lock_token ) || ! $this->store->save_run( $run ) ) {
					if (
						! $had_active_marker
						&& $this->store->refresh_global_lock( $global_token )
						&& $this->store->refresh_lock( $run_id, $lock_token )
					) {
						$this->store->clear_active_run( $run_id );
					}
					return new \WP_Error( 'wptl_rollback_state_write_failed', __( 'Could not start rollback; its active marker was restored safely.', 'wp-title-layer' ) );
				}
				do_action( 'wptl_migration_rollback_started', $run );
			}

			return $run;
		} finally {
			$this->store->release_lock( $run_id, $lock_token );
			$this->store->release_global_lock( $global_token );
		}
	}

	/**
	 * Roll back at most one migration log batch per call. Only the exact meta_id
	 * written by that batch is deleted, and only while its value hash still
	 * matches the journal.
	 *
	 * @return array|\WP_Error
	 */
	public function process_batch( string $run_id, int $limit = Config::DEFAULT_BATCH_SIZE ) {
		$run_id = Config::clean_run_id( $run_id );
		if ( '' === $run_id ) {
			return new \WP_Error( 'wptl_rollback_invalid_id', __( 'The migration run ID is invalid.', 'wp-title-layer' ) );
		}
		$run = $this->start( $run_id );
		if ( is_wp_error( $run ) || in_array( $run['status'], [ 'rolled_back', 'rollback_complete' ], true ) ) {
			return $run;
		}

		$lock_token = $this->store->acquire_lock( $run_id );
		if ( false === $lock_token ) {
			return new \WP_Error( 'wptl_rollback_locked', __( 'This rollback batch is already being processed.', 'wp-title-layer' ) );
		}

		try {
			$run = $this->store->get_run( $run_id );
			if ( ! $run ) {
				return new \WP_Error( 'wptl_rollback_not_found', __( 'Migration run not found.', 'wp-title-layer' ) );
			}
			if ( in_array( $run['status'], [ 'rolled_back', 'rollback_complete' ], true ) ) {
				return $run;
			}
			if ( 'rollback_running' !== $run['status'] ) {
				return new \WP_Error( 'wptl_rollback_bad_state', __( 'This migration is not in rollback mode.', 'wp-title-layer' ) );
			}
			if ( $run_id !== Config::clean_run_id( (string) get_option( Config::ACTIVE_RUN_OPTION, '' ) ) ) {
				return new \WP_Error( 'wptl_rollback_inactive', __( 'This rollback is no longer the active run and was stopped safely.', 'wp-title-layer' ) );
			}

			$batch = isset( $run['rollback']['current_batch'] ) ? (int) $run['rollback']['current_batch'] : 0;
			if ( $batch < 1 ) {
				return $this->complete( $run, $lock_token );
			}

			$log = $this->store->get_log( $run_id, $batch );
			if ( ! $log ) {
				$run['rollback']['current_batch'] = $batch - 1;
				if ( ! $this->store->refresh_lock( $run_id, $lock_token ) || ! $this->store->save_run( $run ) ) {
					return new \WP_Error( 'wptl_rollback_state_write_failed', __( 'Could not advance rollback state; it remains safe to retry.', 'wp-title-layer' ) );
				}
				return $run['rollback']['current_batch'] < 1 ? $this->complete( $run, $lock_token ) : $run;
			}

			$limit     = max( 1, min( Config::MAX_BATCH_SIZE, $limit ) );
			$processed = 0;
			foreach ( array_reverse( array_keys( (array) $log['records'] ) ) as $key ) {
				if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
					return new \WP_Error( 'wptl_rollback_lock_lost', __( 'The rollback lock expired or changed ownership; the batch stopped safely.', 'wp-title-layer' ) );
				}
				if ( $processed >= $limit ) {
					break;
				}

				$record = $log['records'][ $key ];
				if ( ! $this->is_owned_record( $record ) ) {
					continue;
				}
				if ( isset( $record['rollback_status'] ) ) {
					continue;
				}

				$log['records'][ $key ] = $this->rollback_record( $record );
				if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
					return $this->lock_lost_error();
				}
				if ( ! $this->store->save_log( $run_id, $batch, $log ) ) {
					return new \WP_Error( 'wptl_rollback_log_write_failed', __( 'Could not save the rollback journal; processing stopped safely.', 'wp-title-layer' ) );
				}
				if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
					return $this->lock_lost_error();
				}
				++$processed;
			}

			$remaining = false;
			foreach ( (array) $log['records'] as $record ) {
				if ( $this->is_owned_record( $record ) && ! isset( $record['rollback_status'] ) ) {
					$remaining = true;
					break;
				}
			}

			if ( ! $remaining ) {
				$log['rollback_completed_at']       = Config::now();
				$run['rollback']['current_batch'] = $batch - 1;
			}
			if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
				return $this->lock_lost_error();
			}
			if ( ! $this->store->save_log( $run_id, $batch, $log ) ) {
				return new \WP_Error( 'wptl_rollback_log_write_failed', __( 'Could not finalize the rollback journal; processing stopped safely.', 'wp-title-layer' ) );
			}
			$run['rollback']['counts'] = $this->aggregate_counts( $run_id, max( $batch, (int) $run['next_batch'] ) );
			if ( ! $this->store->refresh_lock( $run_id, $lock_token ) || ! $this->store->save_run( $run ) ) {
				return new \WP_Error( 'wptl_rollback_state_write_failed', __( 'Could not advance rollback state; the journal remains safe to retry.', 'wp-title-layer' ) );
			}

			do_action( 'wptl_migration_rollback_batch_completed', $run_id, $batch, $run['rollback']['counts'] );
			if ( ! $this->store->refresh_lock( $run_id, $lock_token ) ) {
				return $this->lock_lost_error();
			}
			return $run['rollback']['current_batch'] < 1 ? $this->complete( $run, $lock_token ) : $run;
		} finally {
			$this->store->release_lock( $run_id, $lock_token );
		}
	}

	private function rollback_record( array $record ): array {
		global $wpdb;

		$post_id = isset( $record['post_id'] ) ? (int) $record['post_id'] : 0;
		$hash    = isset( $record['value_hash'] ) ? (string) $record['value_hash'] : ( isset( $record['target_hash'] ) ? (string) $record['target_hash'] : '' );
		$meta_id = isset( $record['meta_id'] ) ? (int) $record['meta_id'] : 0;

		if ( $meta_id > 0 ) {
			$metadata = get_metadata_by_mid( 'post', $meta_id );
			if ( ! $metadata ) {
				return $this->mark( $record, 'already_missing' );
			}

			$metadata_post_id = isset( $metadata->post_id ) ? (int) $metadata->post_id : ( isset( $metadata->object_id ) ? (int) $metadata->object_id : 0 );
			if ( $metadata_post_id !== $post_id || ! isset( $metadata->meta_key ) || Config::TARGET_META !== $metadata->meta_key ) {
				return $this->mark( $record, 'preserved_identity_mismatch' );
			}
			if ( Config::value_hash( $metadata->meta_value ) !== $hash ) {
				return $this->mark( $record, 'preserved_changed' );
			}

			/** This filter is documented in wp-includes/meta.php. */
			$check = apply_filters( 'delete_post_metadata_by_mid', null, $meta_id );
			if ( null !== $check ) {
				return $this->status_after_short_circuit( $record, $post_id, $meta_id, $hash, (bool) $check );
			}

			/** This action is documented in wp-includes/meta.php. */
			do_action( 'delete_post_meta', array( $meta_id ), $post_id, Config::TARGET_META, $metadata->meta_value );
			/** This action is documented in wp-includes/meta.php. */
			do_action( 'delete_postmeta', $meta_id );

			// Compare and delete in one statement. A user or another request can change
			// the row from a run-owned value between the read above and this query; the
			// exact byte predicate makes that change win instead of deleting it.
			$deleted = 1 === (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta}
					WHERE meta_id = %d
						AND post_id = %d
						AND BINARY meta_key = BINARY %s
						AND BINARY meta_value = BINARY %s",
					$meta_id,
					$post_id,
					Config::TARGET_META,
					maybe_serialize( $metadata->meta_value )
				)
			);
			wp_cache_delete( $post_id, 'post_meta' );

			if ( $deleted ) {
				/** This action is documented in wp-includes/meta.php. */
				do_action( 'deleted_post_meta', array( $meta_id ), $post_id, Config::TARGET_META, $metadata->meta_value );
				/** This action is documented in wp-includes/meta.php. */
				do_action( 'deleted_postmeta', $meta_id );
				return $this->mark( $record, 'deleted' );
			}

			return $this->status_after_failed_compare( $record, $post_id, $meta_id, $hash );
		}

		// A write-ahead pending record has no durable proof that a matching row was
		// created by this run. Another request or a user could have created the same
		// value after the journal entry. Preserve it unless the exact meta_id was
		// recorded after add_post_meta() succeeded.
		return $this->mark( $record, 'preserved_unproven_pending' );
	}

	/**
	 * Re-read a row after an atomic comparison did not delete it.
	 */
	private function status_after_failed_compare( array $record, int $post_id, int $meta_id, string $hash ): array {
		$current = get_metadata_by_mid( 'post', $meta_id );
		if ( ! $current ) {
			return $this->mark( $record, 'already_missing' );
		}

		$current_post_id = isset( $current->post_id ) ? (int) $current->post_id : ( isset( $current->object_id ) ? (int) $current->object_id : 0 );
		if ( $current_post_id !== $post_id || ! isset( $current->meta_key ) || Config::TARGET_META !== $current->meta_key ) {
			return $this->mark( $record, 'preserved_identity_mismatch' );
		}
		if ( Config::value_hash( $current->meta_value ) !== $hash ) {
			return $this->mark( $record, 'preserved_changed' );
		}

		return $this->mark( $record, 'delete_failed' );
	}

	/**
	 * Preserve the core short-circuit filter while verifying its claimed result.
	 */
	private function status_after_short_circuit( array $record, int $post_id, int $meta_id, string $hash, bool $claimed_deleted ): array {
		if ( ! $claimed_deleted ) {
			return $this->mark( $record, 'delete_failed' );
		}
		if ( ! get_metadata_by_mid( 'post', $meta_id ) ) {
			wp_cache_delete( $post_id, 'post_meta' );
			return $this->mark( $record, 'deleted' );
		}

		return $this->status_after_failed_compare( $record, $post_id, $meta_id, $hash );
	}

	/** Whether the journal contains durable proof that this run owns a row. */
	private function is_owned_record( array $record ): bool {
		$status = isset( $record['status'] ) ? (string) $record['status'] : '';
		return in_array( $status, array( 'migrated', 'pending' ), true )
			|| ( 'conflict' === $status && ! empty( $record['owned_write'] ) && ! empty( $record['meta_id'] ) );
	}

	private function mark( array $record, string $status ): array {
		$record['rollback_status'] = $status;
		$record['rollback_at']     = Config::now();
		return $record;
	}

	private function aggregate_counts( string $run_id, int $last_batch ): array {
		$counts = $this->empty_counts();
		for ( $batch = 1; $batch <= $last_batch; ++$batch ) {
			$log = $this->store->get_log( $run_id, $batch );
			if ( ! $log ) {
				continue;
			}
			foreach ( (array) $log['records'] as $record ) {
				if ( ! isset( $record['rollback_status'] ) ) {
					continue;
				}
				++$counts['processed'];
				$status = $record['rollback_status'];
				if ( in_array( $status, [ 'deleted', 'deleted_recovered_pending' ], true ) ) {
					++$counts['deleted'];
				} elseif ( 'already_missing' === $status ) {
					++$counts['missing'];
				} elseif ( 'delete_failed' === $status ) {
					++$counts['errors'];
				} else {
					++$counts['preserved'];
				}
			}
		}

		return $counts;
	}

	private function empty_counts(): array {
		return [
			'processed' => 0,
			'deleted'   => 0,
			'preserved' => 0,
			'missing'   => 0,
			'errors'    => 0,
		];
	}

	private function complete( array $run, string $lock_token ) {
		$run['status']                       = 'rolled_back';
		$run['rollback']['completed_at']     = Config::now();
		$run['rollback']['current_batch']    = 0;
		$run['rollback']['counts']           = $this->aggregate_counts( $run['id'], max( 0, (int) $run['next_batch'] ) );
		if ( ! $this->store->refresh_lock( $run['id'], $lock_token ) ) {
			return $this->lock_lost_error();
		}
		if ( ! $this->store->save_run( $run ) ) {
			return new \WP_Error( 'wptl_rollback_completion_write_failed', __( 'Could not record rollback completion; the active marker was preserved for a safe retry.', 'wp-title-layer' ) );
		}
		if ( ! $this->store->refresh_lock( $run['id'], $lock_token ) ) {
			return $this->lock_lost_error();
		}
		$this->store->clear_active_run( $run['id'] );
		do_action( 'wptl_migration_rollback_completed', $run );

		return $run;
	}

	private function lock_lost_error(): \WP_Error {
		return new \WP_Error( 'wptl_rollback_lock_lost', __( 'The rollback lock expired or changed ownership; the batch stopped safely.', 'wp-title-layer' ) );
	}

	private function control_lock_lost_error(): \WP_Error {
		return new \WP_Error( 'wptl_rollback_control_lock_lost', __( 'The migration control lock changed ownership; rollback was stopped safely.', 'wp-title-layer' ) );
	}
}
