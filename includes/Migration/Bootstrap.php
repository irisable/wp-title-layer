<?php
/**
 * Migration module integration point.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Migration;

final class Bootstrap {
	private static $registered = false;

	private function __construct() {
	}

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', [ AdminPage::class, 'register_page' ] );
		add_action( 'admin_enqueue_scripts', [ AdminAssets::class, 'enqueue' ] );
		add_action( 'admin_post_wptl_migration_scan', [ AdminPage::class, 'handle_scan' ] );
		add_action( 'admin_post_wptl_migration_start', [ AdminPage::class, 'handle_start' ] );
		add_action( 'admin_post_wptl_migration_batch', [ AdminPage::class, 'handle_batch' ] );
		add_action( 'admin_post_wptl_migration_rollback', [ AdminPage::class, 'handle_rollback' ] );
		add_action( 'admin_post_wptl_migration_export_conflicts', [ AdminPage::class, 'handle_export_conflicts' ] );
		add_action( 'wp_ajax_wptl_migration_process_batch', [ AjaxController::class, 'process_batch' ] );

		do_action( 'wptl_migration_registered' );
	}
}
