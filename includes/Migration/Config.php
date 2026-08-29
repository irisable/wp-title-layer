<?php
/**
 * Migration constants and small value helpers.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Migration;

final class Config {
	public const SCHEMA_VERSION = 1;

	public const LEGACY_META = '_secondary_title';
	public const ACF_META = 'subtitle';
	public const ACF_REFERENCE_META = '_subtitle';
	public const TARGET_META = 'wptl_subtitle';
	public const ACF_SERIES_FIELDS = [
		'series_key',
		'series_title',
		'series_order',
		'series_has_order',
		'series_stage',
	];

	public const ACTIVE_RUN_OPTION = 'wptl_migration_active_run';
	public const RUN_INDEX_OPTION = 'wptl_migration_runs';
	public const LAST_SCAN_OPTION = 'wptl_migration_last_scan';
	public const GLOBAL_LOCK_OPTION = 'wptl_migration_global_lock';
	public const SCAN_MANIFEST_PREFIX = 'wptl_migration_scan_manifest_';
	public const SCAN_MANIFEST_CHUNK_SIZE = 250;

	public const DEFAULT_BATCH_SIZE = 100;
	public const MAX_BATCH_SIZE = 500;

	/**
	 * Legacy Secondary Title options. They are detected and reported, but are
	 * deliberately not rewritten into a new settings schema by this module.
	 */
	public const LEGACY_OPTIONS = [
		'secondary_title_post_types',
		'secondary_title_categories',
		'secondary_title_post_ids',
		'secondary_title_auto_show',
		'secondary_title_title_format',
		'secondary_title_input_field_position',
		'secondary_title_only_show_in_main_post',
		'secondary_title_use_in_permalinks',
		'secondary_title_permalinks_position',
		'secondary_title_column_position',
		'secondary_title_feed_auto_show',
		'secondary_title_feed_title_format',
		'secondary_title_include_in_search',
		'secondary_title_show_donation_notice',
	];

	private function __construct() {
	}

	public static function run_option( string $run_id ): string {
		return 'wptl_migration_run_' . self::clean_run_id( $run_id );
	}

	public static function log_option( string $run_id, int $batch ): string {
		return sprintf( 'wptl_migration_log_%s_%06d', self::clean_run_id( $run_id ), max( 1, $batch ) );
	}

	public static function conflict_option( string $run_id, int $batch ): string {
		return sprintf( 'wptl_migration_conflicts_%s_%06d', self::clean_run_id( $run_id ), max( 1, $batch ) );
	}

	public static function lock_option( string $run_id ): string {
		return 'wptl_migration_lock_' . self::clean_run_id( $run_id );
	}

	public static function manifest_option( string $scan_id, int $chunk ): string {
		return self::SCAN_MANIFEST_PREFIX . self::clean_run_id( $scan_id ) . '_' . sprintf( '%06d', max( 1, $chunk ) );
	}

	public static function clean_run_id( string $run_id ): string {
		return substr( preg_replace( '/[^a-f0-9]/', '', strtolower( $run_id ) ), 0, 32 );
	}

	public static function new_run_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 20 );
		}

		return substr( hash( 'sha256', uniqid( 'wptl', true ) ), 0, 20 );
	}

	public static function now(): string {
		return gmdate( 'c' );
	}

	/**
	 * Stable hash used by the rollback journal. Values are never written to the
	 * batch log itself.
	 *
	 * @param mixed $value Metadata value.
	 */
	public static function value_hash( $value ): string {
		return hash( 'sha256', serialize( $value ) );
	}

	/**
	 * Run the target meta's registered sanitization before comparing or writing.
	 * This makes repeated migration passes idempotent even when the canonical
	 * field has a sanitize callback.
	 */
	public static function sanitize_target_value( string $value, int $post_id ): string {
		$post_type = get_post_type( $post_id );
		$sanitized = function_exists( 'sanitize_meta' )
			? sanitize_meta( self::TARGET_META, $value, 'post', $post_type ?: '' )
			: $value;

		$sanitized = apply_filters( 'wptl_migration_target_value', $sanitized, $value, $post_id );

		return is_scalar( $sanitized ) ? (string) $sanitized : '';
	}

	public static function is_acf_field_key( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^field_[A-Za-z0-9_-]+$/', $value );
	}
}
