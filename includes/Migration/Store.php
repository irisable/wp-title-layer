<?php
/**
 * Non-autoloaded option storage and per-run locks for migrations.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Migration;

final class Store {
	public function get_run( string $run_id ) {
		$run_id = Config::clean_run_id( $run_id );
		if ( '' === $run_id ) {
			return null;
		}

		$run = get_option( Config::run_option( $run_id ), null );
		return is_array( $run ) ? $run : null;
	}

	public function add_run( array $run, string $global_token = '' ): bool {
		if ( empty( $run['id'] ) ) {
			return false;
		}
		if ( '' !== $global_token && ! $this->refresh_global_lock( $global_token ) ) {
			return false;
		}

		$added = add_option( Config::run_option( $run['id'] ), $run, '', 'no' );
		if ( ! $added ) {
			return false;
		}
		if ( '' !== $global_token && ! $this->refresh_global_lock( $global_token ) ) {
			return $this->fail_run_initialization( $run, 'global_lock_lost' );
		}

		$index_saved = $this->prepend_run_index( (string) $run['id'], $global_token );
		if ( '' !== $global_token && ! $this->refresh_global_lock( $global_token ) ) {
			return $this->fail_run_initialization( $run, 'global_lock_lost' );
		}
		$active_saved = $index_saved && $this->claim_active_run( (string) $run['id'] );
		if ( ! $index_saved || ! $active_saved ) {
			if ( $active_saved ) {
				$this->clear_active_run( (string) $run['id'] );
			}
			$run['status']       = 'initialization_failed';
			$run['failure_code'] = ! $index_saved ? 'run_index_write_failed' : 'active_marker_write_failed';
			$this->save_run( $run );
			return false;
		}
		if ( '' !== $global_token && ! $this->refresh_global_lock( $global_token ) ) {
			$this->clear_active_run( (string) $run['id'] );
			return $this->fail_run_initialization( $run, 'global_lock_lost_after_active_claim' );
		}

		return true;
	}

	/**
	 * Atomically claim the single active-run marker without overwriting another
	 * worker's marker. Callers hold the global token, but this CAS is the last
	 * line of defence if a lease expires between database writes.
	 */
	public function claim_active_run( string $run_id ): bool {
		$run_id = Config::clean_run_id( $run_id );
		if ( '' === $run_id ) {
			return false;
		}

		$current = get_option( Config::ACTIVE_RUN_OPTION, null );
		if ( $run_id === Config::clean_run_id( is_scalar( $current ) ? (string) $current : '' ) ) {
			return true;
		}
		if ( null !== $current && false !== $current && '' !== $current ) {
			return false;
		}
		if ( null === $current || false === $current ) {
			return add_option( Config::ACTIVE_RUN_OPTION, $run_id, '', 'no' );
		}

		return $this->compare_and_swap_option( Config::ACTIVE_RUN_OPTION, $current, $run_id );
	}

	public function save_run( array $run ): bool {
		if ( empty( $run['id'] ) ) {
			return false;
		}

		$run['updated_at'] = Config::now();
		return $this->save_option( Config::run_option( $run['id'] ), $run );
	}

	public function get_log( string $run_id, int $batch ) {
		$log = get_option( Config::log_option( $run_id, $batch ), null );
		return is_array( $log ) ? $log : null;
	}

	public function save_log( string $run_id, int $batch, array $log ): bool {
		return $this->save_option( Config::log_option( $run_id, $batch ), $log );
	}

	public function save_conflicts( string $run_id, int $batch, array $conflicts ): bool {
		return $this->save_option( Config::conflict_option( $run_id, $batch ), $conflicts );
	}

	public function get_conflicts( string $run_id, int $batch ): array {
		$conflicts = get_option( Config::conflict_option( $run_id, $batch ), [] );
		return is_array( $conflicts ) ? $conflicts : [];
	}

	/** Persist one immutable, non-autoloaded chunk of a scan manifest. */
	public function add_manifest_chunk( string $scan_id, int $chunk, array $records ): bool {
		$scan_id = Config::clean_run_id( $scan_id );
		if ( '' === $scan_id || $chunk < 1 ) {
			return false;
		}

		return add_option( Config::manifest_option( $scan_id, $chunk ), $records, '', 'no' );
	}

	/** @return array<string,array<string,mixed>> */
	public function get_manifest_chunk( string $scan_id, int $chunk ): array {
		$scan_id = Config::clean_run_id( $scan_id );
		if ( '' === $scan_id || $chunk < 1 ) {
			return array();
		}

		$records = get_option( Config::manifest_option( $scan_id, $chunk ), array() );
		return is_array( $records ) ? $records : array();
	}

	/**
	 * Read a stable slice by manifest index, never by the current post table.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_manifest_slice( string $scan_id, int $offset, int $limit, int $total_count ): array {
		$scan_id = Config::clean_run_id( $scan_id );
		$offset  = max( 0, $offset );
		$limit   = max( 1, min( Config::MAX_BATCH_SIZE, $limit ) );
		$total_count = max( 0, $total_count );
		if ( '' === $scan_id || $offset >= $total_count ) {
			return array();
		}

		$records     = array();
		$chunk_index = (int) floor( $offset / Config::SCAN_MANIFEST_CHUNK_SIZE ) + 1;
		$chunk_skip  = $offset % Config::SCAN_MANIFEST_CHUNK_SIZE;
		while ( count( $records ) < $limit && $offset + count( $records ) < $total_count ) {
			$chunk = array_values( $this->get_manifest_chunk( $scan_id, $chunk_index ) );
			if ( empty( $chunk ) ) {
				return array();
			}
			foreach ( array_slice( $chunk, $chunk_skip ) as $record ) {
				if ( ! is_array( $record ) ) {
					return array();
				}
				$records[] = $record;
				if ( count( $records ) >= $limit || $offset + count( $records ) >= $total_count ) {
					break 2;
				}
			}
			++$chunk_index;
			$chunk_skip = 0;
		}

		return $records;
	}

	/** Verify the complete immutable manifest before it authorizes a run. */
	public function verify_manifest( string $scan_id, int $chunks, int $expected_count, string $expected_hash ): bool {
		$scan_id = Config::clean_run_id( $scan_id );
		if ( '' === $scan_id || $chunks < 0 || $expected_count < 0 || 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_hash ) ) {
			return false;
		}

		$count   = 0;
		$context = hash_init( 'sha256' );
		for ( $chunk = 1; $chunk <= $chunks; ++$chunk ) {
			$records = $this->get_manifest_chunk( $scan_id, $chunk );
			if ( empty( $records ) ) {
				return false;
			}
			if ( count( $records ) > Config::SCAN_MANIFEST_CHUNK_SIZE || ( $chunk < $chunks && Config::SCAN_MANIFEST_CHUNK_SIZE !== count( $records ) ) ) {
				return false;
			}
			foreach ( $records as $key => $record ) {
				if ( ! is_array( $record ) || (string) (int) ( $record['post_id'] ?? 0 ) !== (string) $key || ! isset( $record['fingerprint'] ) || ! is_array( $record['fingerprint'] ) ) {
					return false;
				}
				$encoded = wp_json_encode( $record );
				hash_update( $context, is_string( $encoded ) ? $encoded : '' );
				++$count;
			}
		}

		$expected_chunks = (int) ceil( $expected_count / Config::SCAN_MANIFEST_CHUNK_SIZE );
		return $chunks === $expected_chunks && $count === $expected_count && hash_equals( $expected_hash, hash_final( $context ) );
	}

	/** Delete only chunks created for a scan whose summary could not be committed. */
	public function delete_manifest( string $scan_id, int $chunks ): void {
		$scan_id = Config::clean_run_id( $scan_id );
		if ( '' === $scan_id ) {
			return;
		}
		for ( $chunk = 1; $chunk <= max( 0, $chunks ); ++$chunk ) {
			delete_option( Config::manifest_option( $scan_id, $chunk ) );
		}
	}

	/**
	 * Acquire a token-owned run lock. An expired value is replaced with a
	 * compare-and-swap so an older worker can never release a newer lock.
	 *
	 * @return string|false Lock token, or false when another worker owns it.
	 */
	public function acquire_lock( string $run_id, int $ttl = 300 ) {
		return $this->acquire_option_lock( Config::lock_option( $run_id ), $ttl );
	}

	/** @return string|false */
	public function acquire_global_lock( int $ttl = 300 ) {
		return $this->acquire_option_lock( Config::GLOBAL_LOCK_OPTION, $ttl );
	}

	public function refresh_lock( string $run_id, string $token, int $ttl = 300 ): bool {
		return $this->refresh_option_lock( Config::lock_option( $run_id ), $token, $ttl );
	}

	public function refresh_global_lock( string $token, int $ttl = 300 ): bool {
		return $this->refresh_option_lock( Config::GLOBAL_LOCK_OPTION, $token, $ttl );
	}

	public function release_lock( string $run_id, string $token ): bool {
		return $this->release_option_lock( Config::lock_option( $run_id ), $token );
	}

	public function release_global_lock( string $token ): bool {
		return $this->release_option_lock( Config::GLOBAL_LOCK_OPTION, $token );
	}

	public function clear_active_run( string $run_id ): void {
		global $wpdb;

		$run_id  = Config::clean_run_id( $run_id );
		$current = get_option( Config::ACTIVE_RUN_OPTION, null );
		if ( '' === $run_id || Config::clean_run_id( (string) $current ) !== $run_id ) {
			return;
		}

		// Delete only the exact marker observed above. If another worker starts a
		// new run between this read and the DELETE, its marker is preserved.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				Config::ACTIVE_RUN_OPTION,
				maybe_serialize( $current )
			)
		);
		wp_cache_delete( Config::ACTIVE_RUN_OPTION, 'options' );
	}

	public function save_option( string $key, $value ): bool {
		if ( 0 !== strpos( $key, 'wptl_' ) ) {
			return false;
		}

		$sentinel = new \stdClass();
		$current  = get_option( $key, $sentinel );
		if ( $sentinel === $current ) {
			return add_option( $key, $value, '', 'no' );
		}
		if ( $current === $value ) {
			return true;
		}

		return update_option( $key, $value, false );
	}

	/** Save only when the option still has the exact value the caller reviewed. */
	public function compare_and_swap( string $key, $current, $next ): bool {
		if ( 0 !== strpos( $key, 'wptl_' ) ) {
			return false;
		}
		return $this->compare_and_swap_option( $key, $current, $next );
	}

	private function fail_run_initialization( array $run, string $code ): bool {
		$run['status']       = 'initialization_failed';
		$run['failure_code'] = $code;
		$this->save_run( $run );
		return false;
	}

	private function prepend_run_index( string $run_id, string $global_token ): bool {
		$sentinel = new \stdClass();
		$current  = get_option( Config::RUN_INDEX_OPTION, $sentinel );
		if ( '' !== $global_token && ! $this->refresh_global_lock( $global_token ) ) {
			return false;
		}

		$index = is_array( $current ) ? $current : [];
		array_unshift( $index, $run_id );
		$clean_index = [];
		foreach ( $index as $indexed_run_id ) {
			if ( ! is_scalar( $indexed_run_id ) ) {
				continue;
			}
			$indexed_run_id = Config::clean_run_id( (string) $indexed_run_id );
			if ( '' !== $indexed_run_id ) {
				$clean_index[] = $indexed_run_id;
			}
		}
		$next = array_slice( array_values( array_unique( $clean_index ) ), 0, 25 );

		if ( $sentinel === $current ) {
			return add_option( Config::RUN_INDEX_OPTION, $next, '', 'no' );
		}

		// The exact-value CAS prevents a worker whose global lease expired inside
		// an option read from overwriting the successor's newer run index.
		return $this->compare_and_swap_option( Config::RUN_INDEX_OPTION, $current, $next );
	}

	/** @return string|false */
	private function acquire_option_lock( string $key, int $ttl ) {
		$token = Config::new_run_id() . Config::new_run_id();
		$value = [
			'token'   => $token,
			'expires' => time() + max( 30, $ttl ),
		];

		if ( add_option( $key, $value, '', 'no' ) ) {
			return $token;
		}

		$current = get_option( $key, null );
		$expires = is_array( $current ) && isset( $current['expires'] ) ? (int) $current['expires'] : 0;
		if ( $expires >= time() || null === $current ) {
			return false;
		}

		return $this->compare_and_swap_option( $key, $current, $value ) ? $token : false;
	}

	private function refresh_option_lock( string $key, string $token, int $ttl ): bool {
		$current = get_option( $key, null );
		if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
			return false;
		}

		$ttl = max( 30, $ttl );
		// Avoid a no-op SQL update. MySQL reports zero affected rows when the
		// serialized value is unchanged, which must not be mistaken for lock loss.
		if ( (int) ( $current['expires'] ?? 0 ) >= time() + $ttl - 5 ) {
			return true;
		}

		$next            = $current;
		$next['expires'] = time() + $ttl;
		return $this->compare_and_swap_option( $key, $current, $next );
	}

	private function release_option_lock( string $key, string $token ): bool {
		global $wpdb;

		$current = get_option( $key, null );
		if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
			return false;
		}

		$deleted = 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$key,
				maybe_serialize( $current )
			)
		);
		wp_cache_delete( $key, 'options' );

		return $deleted;
	}

	private function compare_and_swap_option( string $key, $current, $next ): bool {
		global $wpdb;

		if ( $current === $next ) {
			return true;
		}

		$updated = 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $next ),
				$key,
				maybe_serialize( $current )
			)
		);
		wp_cache_delete( $key, 'options' );

		return $updated;
	}
}
