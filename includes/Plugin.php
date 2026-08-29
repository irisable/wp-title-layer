<?php
/**
 * Main application bootstrap.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer;

use WPTitleLayer\Admin\ControlCenter;
use WPTitleLayer\Admin\ChannelHealth;
use WPTitleLayer\Admin\ClassicEditor;
use WPTitleLayer\Admin\PostListColumns;
use WPTitleLayer\Admin\SequenceManager;
use WPTitleLayer\Admin\SeriesHealth;
use WPTitleLayer\Core\Bootstrap as Core_Bootstrap;
use WPTitleLayer\Integrations\Bootstrap as Integrations_Bootstrap;
use WPTitleLayer\Migration\Bootstrap as Migration_Bootstrap;
use WPTitleLayer\Presentation\Bootstrap as Presentation_Bootstrap;
use WPTitleLayer\Reader\Bootstrap as Reader_Bootstrap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates independent plugin modules.
 */
final class Plugin {
	/**
	 * Register hooks after all plugins are available for conflict detection.
	 *
	 * @return void
	 */
	public static function register() {
		$legacy_active = function_exists( 'get_secondary_title' );

		load_plugin_textdomain(
			'wp-title-layer',
			false,
			dirname( plugin_basename( WPTL_FILE ) ) . '/languages'
		);

		Core_Bootstrap::register( ! $legacy_active );
		Migration_Bootstrap::register();
		ControlCenter::register( $legacy_active );

		if ( $legacy_active ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_legacy_notice' ) );
			return;
		}

		require_once WPTL_PATH . 'includes/compat.php';
		ChannelHealth::register();
		SeriesHealth::register();
		SequenceManager::register();
		PostListColumns::register();
		ClassicEditor::register();
		Presentation_Bootstrap::register();
		Reader_Bootstrap::register();
		Integrations_Bootstrap::register();
	}

	/**
	 * Warn administrators while the legacy plugin still owns title output.
	 *
	 * @return void
	 */
	public static function render_legacy_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$url = admin_url( 'tools.php?page=wp-title-layer-migration' );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'WP Title Layer is in migration-only mode.', 'wp-title-layer' ); ?></strong>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: migration page URL. */
						__( 'Secondary Title is still active, so WP Title Layer has disabled its editor and front-end output to prevent duplicate titles. <a href="%s">Review the migration report</a>, then deactivate Secondary Title before switching output.', 'wp-title-layer' ),
						esc_url( $url )
					)
				);
				?>
			</p>
		</div>
		<?php
	}
}
