<?php
/**
 * Persist an immutable, plaintext-free snapshot of one migration scan.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Migration;

final class ScanSnapshot {
	private $store;

	public function __construct( ?Store $store = null ) {
		$this->store = $store ?: new Store();
	}

	/**
	 * Run a read-only scan and commit its frozen candidate manifest atomically.
	 *
	 * @return array|\WP_Error
	 */
	public function create( int $max_posts = 100000 ) {
		$global_token = $this->store->acquire_global_lock();
		if ( false === $global_token ) {
			return new \WP_Error( 'wptl_migration_control_locked', __( 'Another migration control action is in progress.', 'wp-title-layer' ) );
		}

		$scan_id           = Config::new_run_id();
		$chunk_number      = 0;
		$chunk             = array();
		$result            = null;
		$summary_committed = false;

		try {
			$active_id  = Config::clean_run_id( (string) get_option( Config::ACTIVE_RUN_OPTION, '' ) );
			$active_run = '' !== $active_id ? $this->store->get_run( $active_id ) : null;
			if ( is_array( $active_run ) && in_array( (string) ( $active_run['status'] ?? '' ), array( 'running', 'rollback_running' ), true ) ) {
				$result = new \WP_Error( 'wptl_migration_active', __( 'A migration or rollback is already running.', 'wp-title-layer' ) );
			} else {
				$flush = function () use ( $scan_id, $global_token, &$chunk_number, &$chunk ): void {
					if ( empty( $chunk ) ) {
						return;
					}
					if ( ! $this->store->refresh_global_lock( $global_token ) ) {
						throw new \RuntimeException( 'global_lock_lost' );
					}
					++$chunk_number;
					if ( ! $this->store->add_manifest_chunk( $scan_id, $chunk_number, $chunk ) ) {
						throw new \RuntimeException( 'manifest_write_failed' );
					}
					if ( ! $this->store->refresh_global_lock( $global_token ) ) {
						throw new \RuntimeException( 'global_lock_lost' );
					}
					$chunk = array();
				};

				$scanner = new Scanner(
					null,
					null,
					static function ( array $record ) use ( &$chunk, $flush ): void {
						$chunk[ (string) (int) $record['post_id'] ] = $record;
						if ( count( $chunk ) >= Config::SCAN_MANIFEST_CHUNK_SIZE ) {
							$flush();
						}
					}
				);
				$scan = $scanner->dry_run( $max_posts );
				$flush();

				if ( ! $this->store->refresh_global_lock( $global_token ) ) {
					throw new \RuntimeException( 'global_lock_lost' );
				}

				$scan['scan_id']         = $scan_id;
				$scan['manifest_chunks'] = $chunk_number;
				if ( ! $this->store->verify_manifest(
					$scan_id,
					$chunk_number,
					(int) ( $scan['manifest_count'] ?? -1 ),
					(string) ( $scan['manifest_hash'] ?? '' )
				) ) {
					throw new \RuntimeException( 'manifest_verification_failed' );
				}

				if ( ! $this->store->refresh_global_lock( $global_token ) ) {
					throw new \RuntimeException( 'global_lock_lost' );
				}
				$sentinel = new \stdClass();
				$previous = get_option( Config::LAST_SCAN_OPTION, $sentinel );
				if ( ! $this->store->refresh_global_lock( $global_token ) ) {
					throw new \RuntimeException( 'global_lock_lost' );
				}
				$summary_saved = $sentinel === $previous
					? add_option( Config::LAST_SCAN_OPTION, $scan, '', 'no' )
					: $this->store->compare_and_swap( Config::LAST_SCAN_OPTION, $previous, $scan );
				if ( ! $summary_saved ) {
					throw new \RuntimeException( 'scan_summary_write_failed' );
				}
				$summary_committed = true;

				if ( ! $this->store->refresh_global_lock( $global_token ) ) {
					throw new \RuntimeException( 'global_lock_lost' );
				}
				$active_id  = Config::clean_run_id( (string) get_option( Config::ACTIVE_RUN_OPTION, '' ) );
				$active_run = '' !== $active_id ? $this->store->get_run( $active_id ) : null;
				$active_scan_id = is_array( $active_run )
					? Config::clean_run_id( is_scalar( $active_run['scan_id'] ?? null ) ? (string) $active_run['scan_id'] : '' )
					: '';
				if ( ! $this->store->refresh_global_lock( $global_token ) ) {
					throw new \RuntimeException( 'global_lock_lost' );
				}
				if ( is_array( $previous ) ) {
					$previous_id = Config::clean_run_id( is_scalar( $previous['scan_id'] ?? null ) ? (string) $previous['scan_id'] : '' );
					if ( '' !== $previous_id && $scan_id !== $previous_id && $active_scan_id !== $previous_id ) {
						$this->store->delete_manifest( $previous_id, max( 0, (int) ( $previous['manifest_chunks'] ?? 0 ) ) );
					}
				}
				$result = $scan;
			}
		} catch ( \Throwable $error ) {
			$result = new \WP_Error( 'wptl_migration_scan_write_failed', __( 'Could not save the reviewed migration scan.', 'wp-title-layer' ) );
		} finally {
			if ( is_wp_error( $result ) && ! $summary_committed ) {
				$this->store->delete_manifest( $scan_id, $chunk_number );
			}
			$this->store->release_global_lock( $global_token );
		}

		return is_array( $result ) || is_wp_error( $result )
			? $result
			: new \WP_Error( 'wptl_migration_scan_write_failed', __( 'Could not save the reviewed migration scan.', 'wp-title-layer' ) );
	}
}
