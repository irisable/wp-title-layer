<?php
/**
 * Sequential, nonce-protected migration batch endpoint.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Migration;

final class AjaxController {
	public static function process_batch(): void {
		self::authorize();

		$run_id = isset( $_POST['run_id'] ) && is_scalar( $_POST['run_id'] )
			? Config::clean_run_id( (string) wp_unslash( $_POST['run_id'] ) )
			: '';
		if ( '' === $run_id ) {
			wp_send_json_error( [ 'code' => 'wptl_migration_invalid_id' ], 400 );
		}

		$result = ( new Migrator() )->process_batch( $run_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
				409
			);
		}

		wp_send_json_success( self::public_state( $result ) );
	}

	private static function authorize(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_scalar( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( (string) $_SERVER['REQUEST_METHOD'] )
			: '';
		if ( 'POST' !== $method ) {
			wp_send_json_error( [ 'code' => 'wptl_migration_method_not_allowed' ], 405 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'code' => 'wptl_migration_forbidden' ], 403 );
		}
		if ( ! isset( $_POST['nonce'] ) || ! is_scalar( $_POST['nonce'] ) ) {
			wp_send_json_error( [ 'code' => 'wptl_migration_invalid_nonce' ], 403 );
		}
		check_ajax_referer( 'wptl_migration_ajax', 'nonce' );
	}

	private static function public_state( array $run ): array {
		$counts = isset( $run['counts'] ) && is_array( $run['counts'] ) ? $run['counts'] : [];
		return [
			'runId'     => Config::clean_run_id( (string) ( $run['id'] ?? '' ) ),
			'status'    => sanitize_key( (string) ( $run['status'] ?? '' ) ),
			'nextBatch' => max( 1, (int) ( $run['next_batch'] ?? 1 ) ),
			'counts'    => [
				'processed' => max( 0, (int) ( $counts['processed'] ?? 0 ) ),
				'migrated'  => max( 0, (int) ( $counts['migrated'] ?? 0 ) ),
				'conflict'  => max( 0, (int) ( $counts['conflict'] ?? 0 ) ),
				'errors'    => max( 0, (int) ( $counts['errors'] ?? 0 ) ),
				'batches'   => max( 0, (int) ( $counts['batches'] ?? 0 ) ),
			],
		];
	}
}
