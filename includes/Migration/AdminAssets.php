<?php
/**
 * Migration screen assets and browser bootstrap data.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Migration;

final class AdminAssets {
	public const HANDLE = 'wptl-migration-admin';

	public static function enqueue( string $hook_suffix ): void {
		if ( 'tools_page_' . AdminPage::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$version = defined( 'WPTL_VERSION' ) ? (string) WPTL_VERSION : '1.0.0-rc.1';
		$url     = defined( 'WPTL_URL' ) ? trailingslashit( (string) WPTL_URL ) : '';
		if ( '' === $url ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			$url . 'assets/migration.js',
			[],
			$version,
			true
		);

		$message = isset( $_GET['wptl_message'] ) && is_scalar( $_GET['wptl_message'] ) ? sanitize_key( (string) wp_unslash( $_GET['wptl_message'] ) ) : '';
		$run_id  = isset( $_GET['run_id'] ) && is_scalar( $_GET['run_id'] ) ? Config::clean_run_id( (string) wp_unslash( $_GET['run_id'] ) ) : '';

		wp_localize_script(
			self::HANDLE,
			'WPTitleLayerMigration',
			[
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'wptl_migration_ajax' ),
				'autoRunId' => 'run_started' === $message ? $run_id : '',
				'text'      => [
					'running'  => __( 'Migration is running. You may leave this page; completed batches are already saved.', 'wp-title-layer' ),
					'continue' => __( 'Run all remaining batches', 'wp-title-layer' ),
					'complete' => __( 'Migration complete. Run the read-only scan again to verify the result.', 'wp-title-layer' ),
					'failed'   => __( 'The automatic run stopped safely. Reload the page and continue this run.', 'wp-title-layer' ),
				],
			]
		);
	}
}
