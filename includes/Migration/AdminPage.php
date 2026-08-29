<?php
/**
 * Capability- and nonce-protected migration tools screen.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Migration;

final class AdminPage {
	public const PAGE_SLUG = 'wp-title-layer-migration';

	public static function register_page(): void {
		add_management_page(
			__( 'WP Title Layer Migration', 'wp-title-layer' ),
			__( 'Title Layer Migration', 'wp-title-layer' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage migrations.', 'wp-title-layer' ) );
		}

		$detector  = new Detector();
		$detection = $detector->detect();
		$scan      = get_option( Config::LAST_SCAN_OPTION, null );
		$run_ids   = get_option( Config::RUN_INDEX_OPTION, [] );
		$run_ids   = is_array( $run_ids ) ? $run_ids : [];
		$store     = new Store();
		$runs      = [];
		foreach ( $run_ids as $run_id ) {
			$run = $store->get_run( (string) $run_id );
			if ( $run ) {
				$runs[] = $run;
			}
		}
		$acf_series_value_posts = 0;
		foreach ( Config::ACF_SERIES_FIELDS as $field_name ) {
			$acf_series_value_posts += (int) ( $detection['acf']['series_fields'][ $field_name ]['value_posts'] ?? 0 );
		}
		$acf_series_unique_posts = (int) ( $detection['acf']['series_value_posts'] ?? 0 );

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Title Layer Migration', 'wp-title-layer' ); ?></h1>
			<p><?php echo esc_html__( 'Copy trusted legacy subtitles into wptl_subtitle. Source metadata is never deleted, and an existing target is never overwritten.', 'wp-title-layer' ); ?></p>

			<?php self::render_notice(); ?>

			<h2><?php echo esc_html__( 'Environment', 'wp-title-layer' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
				<tr>
					<th><?php echo esc_html__( 'Secondary Title plugin', 'wp-title-layer' ); ?></th>
					<td><?php echo ! empty( $detection['secondary_title']['active'] ) ? esc_html__( 'Active', 'wp-title-layer' ) : ( ! empty( $detection['secondary_title']['installed'] ) ? esc_html__( 'Installed but inactive', 'wp-title-layer' ) : esc_html__( 'Not installed', 'wp-title-layer' ) ); ?></td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'Legacy candidate posts', 'wp-title-layer' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $detection['legacy_data']['candidate_posts'] ) ); ?></td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'Orphaned legacy data', 'wp-title-layer' ); ?></th>
					<td><?php echo ! empty( $detection['orphaned_legacy_data'] ) ? esc_html__( 'Yes', 'wp-title-layer' ) : esc_html__( 'No', 'wp-title-layer' ); ?></td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'Trusted ACF subtitle posts', 'wp-title-layer' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $detection['acf']['acf_subtitle_posts'] ) ); ?></td>
				</tr>
				<tr>
					<th><?php echo esc_html__( 'ACF / _secondary_title collisions', 'wp-title-layer' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $detection['acf']['secondary_title_key_collision_posts'] ) ); ?></td>
				</tr>
				</tbody>
			</table>
			<h2><?php echo esc_html__( 'Previous ACF Series fields', 'wp-title-layer' ); ?></h2>
			<p><?php echo esc_html__( 'These five fields are audited only. Counts cover non-empty stored values, including values such as 0 or false that ACF may save when an article is updated; exact empty-string rows are not counted. This is not confirmed Series use. WP Title Layer does not depend on these fields or silently convert them into Series relationships.', 'wp-title-layer' ); ?></p>
			<table class="widefat striped" style="max-width:900px">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Field', 'wp-title-layer' ); ?></th>
						<th><?php echo esc_html__( 'Definitions found', 'wp-title-layer' ); ?></th>
						<th><?php echo esc_html__( 'Posts with a non-empty stored value', 'wp-title-layer' ); ?></th>
						<th><?php echo esc_html__( 'Verified non-empty ACF values', 'wp-title-layer' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( Config::ACF_SERIES_FIELDS as $field_name ) : ?>
					<?php $field_report = isset( $detection['acf']['series_fields'][ $field_name ] ) ? $detection['acf']['series_fields'][ $field_name ] : []; ?>
					<tr>
						<td><code><?php echo esc_html( $field_name ); ?></code></td>
						<td><?php echo esc_html( number_format_i18n( isset( $field_report['definition_count'] ) ? $field_report['definition_count'] : 0 ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( isset( $field_report['value_posts'] ) ? $field_report['value_posts'] : 0 ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( isset( $field_report['verified_value_posts'] ) ? $field_report['verified_value_posts'] : 0 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( 0 === $acf_series_value_posts ) : ?>
				<p class="notice notice-success inline"><span><?php echo esc_html__( 'No non-empty stored values were found in these five ACF Series fields. Exact empty-string rows are not included, and this does not inspect unrelated ACF fields used by a theme or another plugin.', 'wp-title-layer' ); ?></span></p>
			<?php else : ?>
				<p class="notice notice-info inline"><span><?php echo esc_html( sprintf( __( '%1$s non-empty field-post values were found across %2$s unique post IDs. This is not a confirmed Series-usage count: ACF-saved defaults are included, while exact empty strings are excluded. WP Title Layer never imports these five fields automatically. If you know they were unused, they do not block subtitle migration; inspect unrelated ACF fields separately before uninstalling ACF.', 'wp-title-layer' ), number_format_i18n( $acf_series_value_posts ), number_format_i18n( $acf_series_unique_posts ) ) ); ?></span></p>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Dry run', 'wp-title-layer' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wptl_migration_scan">
				<?php wp_nonce_field( 'wptl_migration_scan' ); ?>
				<?php submit_button( __( 'Scan without writing', 'wp-title-layer' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( is_array( $scan ) ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: ready, 2: already equal, 3: conflicts. */
							__( 'Ready: %1$s; already equal: %2$s; conflicts: %3$s.', 'wp-title-layer' ),
							number_format_i18n( $scan['stats']['ready'] ),
							number_format_i18n( $scan['stats']['existing_same'] ),
							number_format_i18n( $scan['stats']['conflicts'] )
						)
					);
					?>
				</p>
				<?php if ( ! empty( $scan['truncated'] ) ) : ?>
					<p class="notice notice-warning inline"><span><?php echo esc_html__( 'The scan reached its configured limit and is not a complete site count.', 'wp-title-layer' ); ?></span></p>
				<?php endif; ?>
				<?php self::render_categories( isset( $scan['categories'] ) ? $scan['categories'] : [] ); ?>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Migration runs', 'wp-title-layer' ); ?></h2>
			<?php if ( self::canStartFromScan( $scan ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wptl_migration_start">
					<input type="hidden" name="scan_id" value="<?php echo esc_attr( (string) $scan['scan_id'] ); ?>">
					<?php wp_nonce_field( 'wptl_migration_start' ); ?>
					<details style="margin:8px 0">
						<summary><?php echo esc_html__( 'Advanced batch settings', 'wp-title-layer' ); ?></summary>
						<label>
							<?php echo esc_html__( 'Posts per saved batch', 'wp-title-layer' ); ?>
							<input type="number" name="batch_size" min="1" max="500" value="100">
						</label>
					</details>
					<?php submit_button( __( 'Start and complete migration', 'wp-title-layer' ), 'primary', 'submit', false ); ?>
				</form>
			<?php elseif ( is_array( $scan ) && ! empty( $scan['truncated'] ) ) : ?>
				<p><?php echo esc_html__( 'This preview was truncated. Increase the scan limit and run a complete read-only scan before starting migration.', 'wp-title-layer' ); ?></p>
			<?php else : ?>
				<p><?php echo esc_html__( 'Run the read-only scan before starting a migration.', 'wp-title-layer' ); ?></p>
			<?php endif; ?>

			<?php self::render_runs( $runs ); ?>
		</div>
		<?php
	}

	public static function handle_scan(): void {
		self::authorize( 'wptl_migration_scan' );
		$scan = ( new ScanSnapshot() )->create();
		if ( is_wp_error( $scan ) ) {
			self::redirect( $scan->get_error_code(), 'error' );
		}
		self::redirect( 'scan_complete' );
	}

	public static function handle_start(): void {
		self::authorize( 'wptl_migration_start' );
		$scan = get_option( Config::LAST_SCAN_OPTION, null );
		if ( ! self::canStartFromScan( $scan ) ) {
			if ( is_array( $scan ) && ! empty( $scan['truncated'] ) ) {
				self::redirect( 'wptl_migration_scan_truncated', 'error' );
			}
			self::redirect( 'wptl_migration_scan_required', 'error' );
		}
		$posted_scan_id = isset( $_POST['scan_id'] ) && is_scalar( $_POST['scan_id'] )
			? Config::clean_run_id( (string) wp_unslash( $_POST['scan_id'] ) )
			: '';
		$saved_scan_id = Config::clean_run_id( (string) ( $scan['scan_id'] ?? '' ) );
		if ( '' === $posted_scan_id || ! hash_equals( $saved_scan_id, $posted_scan_id ) ) {
			self::redirect( 'wptl_migration_scan_changed', 'error' );
		}
		$batch_size = isset( $_POST['batch_size'] ) && is_scalar( $_POST['batch_size'] )
			? absint( wp_unslash( $_POST['batch_size'] ) )
			: Config::DEFAULT_BATCH_SIZE;
		$run        = ( new Migrator() )->start_run(
			[
				'batch_size'      => $batch_size,
				'high_water_mark' => max( 0, (int) ( $scan['high_water_mark'] ?? 0 ) ),
				'scan_id'         => $posted_scan_id,
				'manifest_count'  => max( 0, (int) ( $scan['manifest_count'] ?? 0 ) ),
				'manifest_chunks' => max( 0, (int) ( $scan['manifest_chunks'] ?? 0 ) ),
				'manifest_hash'   => is_scalar( $scan['manifest_hash'] ?? null ) ? (string) $scan['manifest_hash'] : '',
			]
		);
		if ( is_wp_error( $run ) ) {
			self::redirect( $run->get_error_code(), 'error' );
		}
		self::redirect( 'run_started', 'success', $run['id'] );
	}

	/**
	 * Confirm that a stored dry run is complete and matches this schema.
	 *
	 * A truncated preview must never authorize writes outside the records the
	 * administrator actually reviewed.
	 *
	 * @param mixed $scan Stored scan payload.
	 */
	public static function canStartFromScan( $scan ): bool {
		return is_array( $scan )
			&& empty( $scan['truncated'] )
			&& Config::SCHEMA_VERSION === (int) ( $scan['schema_version'] ?? 0 )
			&& Config::TARGET_META === (string) ( $scan['target_meta'] ?? '' )
			&& '' !== Config::clean_run_id( is_scalar( $scan['scan_id'] ?? null ) ? (string) $scan['scan_id'] : '' )
			&& isset( $scan['manifest_count'], $scan['manifest_chunks'], $scan['stats']['candidate_posts'] )
			&& (int) $scan['manifest_count'] >= 0
			&& (int) $scan['manifest_chunks'] >= 0
			&& (int) $scan['stats']['candidate_posts'] >= 0
			&& (int) $scan['manifest_count'] === (int) $scan['stats']['candidate_posts']
			&& (int) $scan['manifest_chunks'] === (int) ceil( (int) $scan['manifest_count'] / Config::SCAN_MANIFEST_CHUNK_SIZE )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', is_scalar( $scan['manifest_hash'] ?? null ) ? (string) $scan['manifest_hash'] : '' );
	}

	public static function handle_batch(): void {
		self::authorize( 'wptl_migration_batch' );
		$run_id = self::posted_run_id();
		$result = ( new Migrator() )->process_batch( $run_id );
		if ( is_wp_error( $result ) ) {
			self::redirect( $result->get_error_code(), 'error', $run_id );
		}
		self::redirect( 'batch_complete', 'success', $run_id );
	}

	public static function handle_rollback(): void {
		self::authorize( 'wptl_migration_rollback' );
		$run_id  = self::posted_run_id();
		$confirm = isset( $_POST['confirm_rollback'] ) && is_scalar( $_POST['confirm_rollback'] ) ? (string) wp_unslash( $_POST['confirm_rollback'] ) : '';
		if ( '1' !== $confirm ) {
			self::redirect( 'rollback_confirmation_required', 'error', $run_id );
		}

		$rollback = new Rollback();
		$result   = $rollback->start( $run_id );
		if ( ! is_wp_error( $result ) ) {
			$result = $rollback->process_batch( $run_id );
		}
		if ( is_wp_error( $result ) ) {
			self::redirect( $result->get_error_code(), 'error', $run_id );
		}
		self::redirect( 'rollback_batch_complete', 'success', $run_id );
	}

	public static function handle_export_conflicts(): void {
		self::authorize( 'wptl_migration_export_conflicts' );
		$run_id = self::posted_run_id();
		$store  = new Store();
		$run    = $store->get_run( $run_id );
		if ( ! $run ) {
			wp_die( esc_html__( 'Migration run not found.', 'wp-title-layer' ), '', [ 'response' => 404 ] );
		}
		if ( ! in_array( (string) ( $run['status'] ?? '' ), [ 'complete', 'rolled_back' ], true ) ) {
			wp_die( esc_html__( 'Conflict export is available only after a run reaches a stable terminal state.', 'wp-title-layer' ), '', [ 'response' => 409 ] );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wp-title-layer-conflicts-' . $run_id . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$output = fopen( 'php://output', 'wb' );
		if ( false === $output ) {
			wp_die( esc_html__( 'Could not open the conflict export.', 'wp-title-layer' ) );
		}
		fwrite( $output, "\xEF\xBB\xBF" );
		fputcsv( $output, [ 'post_id', 'post_type', 'post_title', 'reason', 'warnings' ] );
		for ( $batch = 1; $batch <= max( 1, (int) ( $run['next_batch'] ?? 1 ) ); ++$batch ) {
			foreach ( $store->get_conflicts( $run_id, $batch ) as $conflict ) {
				fputcsv(
					$output,
					[
						(int) ( $conflict['post_id'] ?? 0 ),
						self::sanitize_csv_cell( (string) ( $conflict['post_type'] ?? '' ) ),
						self::sanitize_csv_cell( (string) ( $conflict['post_title'] ?? '' ) ),
						self::sanitize_csv_cell( (string) ( $conflict['reason'] ?? '' ) ),
						self::sanitize_csv_cell( implode( '|', array_map( 'sanitize_key', (array) ( $conflict['warnings'] ?? [] ) ) ) ),
					]
				);
			}
		}
		fclose( $output );
		exit;
	}

	private static function render_runs( array $runs ): void {
		if ( empty( $runs ) ) {
			return;
		}
		?>
		<table class="widefat striped" style="max-width:1100px;margin-top:16px">
			<thead><tr>
				<th><?php echo esc_html__( 'Run', 'wp-title-layer' ); ?></th>
				<th><?php echo esc_html__( 'Status', 'wp-title-layer' ); ?></th>
				<th><?php echo esc_html__( 'Processed', 'wp-title-layer' ); ?></th>
				<th><?php echo esc_html__( 'Migrated', 'wp-title-layer' ); ?></th>
				<th><?php echo esc_html__( 'Conflicts', 'wp-title-layer' ); ?></th>
				<th><?php echo esc_html__( 'Actions', 'wp-title-layer' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $runs as $run ) : ?>
				<?php $counts = isset( $run['counts'] ) ? $run['counts'] : []; ?>
				<tr data-wptl-run-id="<?php echo esc_attr( $run['id'] ); ?>">
					<td><code><?php echo esc_html( $run['id'] ); ?></code></td>
					<td><span data-wptl-field="status"><?php echo esc_html( $run['status'] ); ?></span><br><small><?php echo esc_html__( 'Saved batches:', 'wp-title-layer' ); ?> <span data-wptl-field="batch"><?php echo esc_html( number_format_i18n( isset( $counts['batches'] ) ? $counts['batches'] : 0 ) ); ?></span></small></td>
					<td data-wptl-field="processed"><?php echo esc_html( number_format_i18n( isset( $counts['processed'] ) ? $counts['processed'] : 0 ) ); ?></td>
					<td data-wptl-field="migrated"><?php echo esc_html( number_format_i18n( isset( $counts['migrated'] ) ? $counts['migrated'] : 0 ) ); ?></td>
					<td data-wptl-field="conflict"><?php echo esc_html( number_format_i18n( isset( $counts['conflict'] ) ? $counts['conflict'] : 0 ) ); ?></td>
					<td>
						<?php if ( 'running' === $run['status'] ) : ?>
							<button type="button" class="button button-primary wptl-run-all" data-run-id="<?php echo esc_attr( $run['id'] ); ?>"><?php echo esc_html__( 'Run all remaining batches', 'wp-title-layer' ); ?></button>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
								<input type="hidden" name="action" value="wptl_migration_batch">
								<input type="hidden" name="run_id" value="<?php echo esc_attr( $run['id'] ); ?>">
								<?php wp_nonce_field( 'wptl_migration_batch' ); ?>
								<?php submit_button( __( 'Run next batch', 'wp-title-layer' ), 'secondary small', 'submit', false ); ?>
							</form>
						<?php endif; ?>
						<span data-wptl-live-status role="status" aria-live="polite" style="display:block;margin-top:6px"></span>
						<?php if ( in_array( $run['status'], [ 'running', 'complete', 'rollback_running' ], true ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:8px">
								<input type="hidden" name="action" value="wptl_migration_rollback">
								<input type="hidden" name="run_id" value="<?php echo esc_attr( $run['id'] ); ?>">
								<?php wp_nonce_field( 'wptl_migration_rollback' ); ?>
								<label><input type="checkbox" name="confirm_rollback" value="1" required> <?php echo esc_html__( 'Confirm', 'wp-title-layer' ); ?></label>
								<?php submit_button( 'rollback_running' === $run['status'] ? __( 'Continue rollback', 'wp-title-layer' ) : __( 'Roll back owned rows', 'wp-title-layer' ), 'delete small', 'submit', false ); ?>
							</form>
						<?php endif; ?>
						<form class="wptl-export-conflicts" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:8px" <?php echo (int) ( $counts['conflict'] ?? 0 ) > 0 && in_array( $run['status'], [ 'complete', 'rolled_back' ], true ) ? '' : 'hidden'; ?>>
							<input type="hidden" name="action" value="wptl_migration_export_conflicts">
							<input type="hidden" name="run_id" value="<?php echo esc_attr( $run['id'] ); ?>">
							<?php wp_nonce_field( 'wptl_migration_export_conflicts' ); ?>
							<?php submit_button( __( 'Export conflicts (CSV)', 'wp-title-layer' ), 'secondary small', 'submit', false ); ?>
						</form>
					</td>
				</tr>
				<?php self::render_run_conflicts( $run ); ?>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_run_conflicts( array $run ): void {
		$count = isset( $run['counts']['conflict'] ) ? (int) $run['counts']['conflict'] : 0;
		if ( $count < 1 ) {
			return;
		}

		$store = new Store();
		$shown = [];
		for ( $batch = 1; $batch < (int) $run['next_batch'] && count( $shown ) < 20; ++$batch ) {
			foreach ( $store->get_conflicts( $run['id'], $batch ) as $conflict ) {
				$shown[] = $conflict;
				if ( count( $shown ) >= 20 ) {
					break;
				}
			}
		}

		if ( empty( $shown ) ) {
			return;
		}
		?>
		<tr><td colspan="6">
			<details>
				<summary><?php echo esc_html( sprintf( __( 'Show conflict queue (%s total)', 'wp-title-layer' ), number_format_i18n( $count ) ) ); ?></summary>
				<ul>
				<?php foreach ( $shown as $conflict ) : ?>
					<li><a href="<?php echo esc_url( get_edit_post_link( (int) $conflict['post_id'] ) ); ?>"><?php echo esc_html( '#' . (int) $conflict['post_id'] . ' ' . $conflict['post_title'] ); ?></a> — <code><?php echo esc_html( $conflict['reason'] ); ?></code></li>
				<?php endforeach; ?>
				</ul>
			</details>
		</td></tr>
		<?php
	}

	private static function render_categories( array $categories ): void {
		if ( empty( $categories ) ) {
			return;
		}
		?>
		<details>
			<summary><?php echo esc_html__( 'Category distribution', 'wp-title-layer' ); ?></summary>
			<table class="widefat striped" style="max-width:800px">
				<thead><tr><th><?php echo esc_html__( 'Category', 'wp-title-layer' ); ?></th><th><?php echo esc_html__( 'Candidates', 'wp-title-layer' ); ?></th><th><?php echo esc_html__( 'Ready', 'wp-title-layer' ); ?></th><th><?php echo esc_html__( 'Conflicts', 'wp-title-layer' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $categories, 0, 50 ) as $category ) : ?>
					<tr><td><?php echo esc_html( $category['name'] ); ?></td><td><?php echo esc_html( number_format_i18n( $category['candidate_posts'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $category['ready'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $category['conflicts'] ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<?php
	}

	private static function authorize( string $nonce_action ): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_scalar( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( (string) $_SERVER['REQUEST_METHOD'] )
			: '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'This migration action accepts POST requests only.', 'wp-title-layer' ), '', [ 'response' => 405 ] );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage migrations.', 'wp-title-layer' ),
				'',
				[ 'response' => 403 ]
			);
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! is_scalar( $_POST['_wpnonce'] ) ) {
			wp_die( esc_html__( 'The migration security token is invalid.', 'wp-title-layer' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( $nonce_action );
	}

	public static function sanitize_csv_cell( string $value ): string {
		$value = str_replace( [ "\r\n", "\r", "\n" ], ' ', $value );
		$value = preg_replace( '/[\x00-\x1F\x7F]/u', '', $value ) ?: '';
		$probe = ltrim( $value, " \t\n\r\0\x0B" );
		return preg_match( '/^[=+\-@]/u', $probe ) ? "'" . $value : $value;
	}

	private static function posted_run_id(): string {
		if ( ! isset( $_POST['run_id'] ) || ! is_scalar( $_POST['run_id'] ) ) {
			return '';
		}

		return Config::clean_run_id( (string) wp_unslash( $_POST['run_id'] ) );
	}

	private static function redirect( string $message, string $type = 'success', string $run_id = '' ): void {
		$url = add_query_arg(
			[
				'page'         => self::PAGE_SLUG,
				'wptl_message' => sanitize_key( $message ),
				'wptl_type'    => 'error' === $type ? 'error' : 'success',
				'run_id'       => Config::clean_run_id( $run_id ),
			],
			admin_url( 'tools.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	private static function render_notice(): void {
		if ( empty( $_GET['wptl_message'] ) || ! is_scalar( $_GET['wptl_message'] ) ) {
			return;
		}
		$code = sanitize_key( (string) wp_unslash( $_GET['wptl_message'] ) );
		$type = isset( $_GET['wptl_type'] ) && is_scalar( $_GET['wptl_type'] ) && 'error' === sanitize_key( (string) wp_unslash( $_GET['wptl_type'] ) ) ? 'error' : 'success';
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><code><?php echo esc_html( $code ); ?></code></p></div>
		<?php
	}
}
