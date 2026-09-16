<?php
/**
 * Fail-safe Title Layer controls for the classic post editor.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Admin;

use WPTitleLayer\Core\Meta;
use WPTitleLayer\Core\Schema;
use WPTitleLayer\Core\Series;
use WPTitleLayer\Core\BookStructure;
use WPTitleLayer\Presentation\Presets;

defined( 'ABSPATH' ) || exit;

final class ClassicEditor {
	private const NONCE_ACTION = 'wptl_save_classic_editor';
	private const NONCE_NAME   = 'wptl_classic_editor_nonce';
	private const FIELD_ROOT   = 'wptl_classic';

	public static function register(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'addMetaBox' ), 20, 2 );
		add_action( 'save_post', array( __CLASS__, 'savePost' ), 20, 3 );
	}

	/** @param mixed $post */
	public static function addMetaBox( string $post_type, $post ): void {
		if ( ! $post instanceof \WP_Post || $post_type !== $post->post_type || ! self::shouldRender( $post ) ) {
			return;
		}

		add_meta_box(
			'wptl-classic-title-layer',
			__( 'Title Layer', 'wp-title-layer' ),
			array( __CLASS__, 'renderMetaBox' ),
			$post_type,
			'normal',
			'high'
		);
		self::enqueueAssets();
	}

	/**
	 * Add no duplicate UI when the block editor owns the document sidebar.
	 */
	public static function shouldRender( \WP_Post $post ): bool {
		if ( ! in_array( $post->post_type, self::postTypes(), true ) ) {
			return false;
		}

		// WordPress 6.5 always provides this API. If another environment omits
		// it, preserve the existing editor instead of guessing and duplicating UI.
		if ( ! function_exists( 'use_block_editor_for_post' ) ) {
			return false;
		}

		return ! use_block_editor_for_post( $post );
	}

	/** @return string[] */
	private static function postTypes(): array {
		$post_types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $post_type ) {
			if ( $post_type instanceof \WP_Post_Type && 'attachment' !== $post_type->name && post_type_supports( $post_type->name, 'custom-fields' ) ) {
				$post_types[] = $post_type->name;
			}
		}

		/** @param string[] $post_types Classic-editor post types. */
		$filtered = (array) apply_filters( 'wptl_classic_editor_post_types', $post_types );
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $filtered ) ) ) );
	}

	private static function ownsSeriesControl(): bool {
		$taxonomy = Series::claimed_taxonomy();
		if ( '' === $taxonomy ) {
			return false;
		}

		$owns = Schema::TAXONOMY_SERIES === $taxonomy;
		/**
		 * Reused taxonomies keep their native editor by default. Opt in only when
		 * WP Title Layer is intended to own their single-Series relationship UI.
		 *
		 * @param bool   $owns     Whether WPTL owns the control.
		 * @param string $taxonomy Claimed taxonomy.
		 */
		return (bool) apply_filters( 'wptl_classic_editor_owns_series_control', $owns, $taxonomy );
	}

	public static function renderMetaBox( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$series_terms = self::ownsSeriesControl()
			? get_terms( array( 'taxonomy' => Series::claimed_taxonomy(), 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) )
			: array();
		$series_terms = is_wp_error( $series_terms ) ? array() : $series_terms;
		$current_term = Series::get_primary_term( (int) $post->ID );
		$current_id   = $current_term instanceof \WP_Term ? (int) $current_term->term_id : 0;
		$current_season = (string) get_post_meta( $post->ID, Schema::META_SEASON_KEY, true );
		$current_managed = $current_term instanceof \WP_Term && \WPTitleLayer\Core\Sequence::is_managed( $current_term );
		$current_book = $current_term instanceof \WP_Term && BookStructure::is_enabled( $current_term );
		$current_book_context = $current_book ? BookStructure::context( (int) $post->ID, $current_term ) : array();
		$current_track = ! empty( $current_book_context['valid'] ) ? (string) $current_book_context['track'] : '';
		$current_main = ! $current_book || ! empty( $current_book_context['main'] );
		$current_ordinal = $current_managed ? \WPTitleLayer\Core\Sequence::automatic_ordinal( (int) $post->ID, $current_term ) : 0;
		$current_sequence_heading = 0 < $current_ordinal
			? sprintf( '%1$s %2$d', __( 'Automatic article number:', 'wp-title-layer' ), $current_ordinal )
			: __( 'Managed sequence', 'wp-title-layer' );
		$manager_base_url = add_query_arg(
			'page',
			'wp-title-layer-sequence-manager',
			admin_url( 'admin.php' )
		);
		$manager_args = array(
			'series_id'    => $current_id,
			'highlight'    => (int) $post->ID,
			'manager_view' => $current_main ? 'sequence' : 'structure',
		);
		if ( ! $current_main && '' !== $current_track ) {
			$manager_args['track'] = $current_track;
		} elseif ( '' !== $current_season ) {
			$manager_args['season_key'] = $current_season;
		}
		$manager_url = add_query_arg( $manager_args, $manager_base_url );
		?>
		<div class="wptl-classic-editor" data-wptl-classic-editor>
			<input type="hidden" name="<?php echo esc_attr( self::FIELD_ROOT ); ?>[_present]" value="1">
			<p>
				<label for="wptl-classic-subtitle"><strong><?php esc_html_e( 'Subtitle', 'wp-title-layer' ); ?></strong></label><br>
				<input class="widefat" type="text" id="wptl-classic-subtitle" name="<?php echo esc_attr( self::FIELD_ROOT ); ?>[subtitle]" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, Schema::META_SUBTITLE, true ) ); ?>">
				<span class="description"><?php esc_html_e( 'Explains or extends the main title. It is not a Series label.', 'wp-title-layer' ); ?></span>
			</p>

			<?php if ( self::ownsSeriesControl() ) : ?>
				<p>
					<label for="wptl-classic-series"><strong><?php esc_html_e( 'Series', 'wp-title-layer' ); ?></strong></label><br>
					<select class="widefat" id="wptl-classic-series" name="<?php echo esc_attr( self::FIELD_ROOT ); ?>[series_id]" data-wptl-series-select>
						<option value="0"><?php esc_html_e( 'No Series', 'wp-title-layer' ); ?></option>
						<?php foreach ( $series_terms as $term ) : ?>
							<option
								value="<?php echo esc_attr( (string) $term->term_id ); ?>"
								data-mode="<?php echo esc_attr( Series::mode( $term ) ); ?>"
								data-structure="<?php echo esc_attr( Series::structure( $term ) ); ?>"
								data-managed="<?php echo \WPTitleLayer\Core\Sequence::is_managed( $term ) ? '1' : '0'; ?>"
								data-book="<?php echo BookStructure::is_enabled( $term ) ? '1' : '0'; ?>"
								<?php selected( $current_id, (int) $term->term_id ); ?>
							><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="description"><?php esc_html_e( 'Choose one existing Series. Series creation and structure remain on the Series screen.', 'wp-title-layer' ); ?></span>
				</p>

					<p>
						<label for="wptl-classic-role"><strong><?php esc_html_e( 'Series role', 'wp-title-layer' ); ?></strong></label><br>
						<select id="wptl-classic-role" name="<?php echo esc_attr( self::FIELD_ROOT ); ?>[series_role]" data-wptl-role-select>
							<?php foreach ( Schema::role_labels() as $role => $role_label ) : ?>
								<option value="<?php echo esc_attr( $role ); ?>" <?php selected( (string) get_post_meta( $post->ID, Schema::META_SERIES_ROLE, true ), $role ); ?>><?php echo esc_html( $role_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p data-wptl-series-condition="book-scope">
						<label for="wptl-classic-scope"><strong><?php esc_html_e( 'Book structure scope', 'wp-title-layer' ); ?></strong></label><br>
						<select id="wptl-classic-scope" name="<?php echo esc_attr( self::FIELD_ROOT ); ?>[series_scope]" data-wptl-scope-select>
							<?php foreach ( Schema::scope_labels() as $scope => $scope_label ) : ?>
								<option value="<?php echo esc_attr( $scope ); ?>" <?php selected( (string) get_post_meta( $post->ID, Schema::META_SERIES_SCOPE, true ), $scope ); ?>><?php echo esc_html( $scope_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>

				<div data-wptl-series-condition="season-required">
					<p>
						<label for="wptl-classic-season"><strong><?php esc_html_e( 'Season', 'wp-title-layer' ); ?></strong></label><br>
						<select class="widefat" id="wptl-classic-season" name="<?php echo esc_attr( self::FIELD_ROOT ); ?>[season_key]" data-wptl-season-select>
							<option value=""><?php esc_html_e( 'Select a season', 'wp-title-layer' ); ?></option>
							<?php foreach ( $series_terms as $term ) : ?>
								<?php foreach ( Series::seasons( $term ) as $season ) : ?>
									<option value="<?php echo esc_attr( $season['key'] ); ?>" data-series="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( $current_id === (int) $term->term_id ? $current_season : '', $season['key'] ); ?>><?php echo esc_html( $term->name . ' — ' . $season['label'] ); ?></option>
								<?php endforeach; ?>
							<?php endforeach; ?>
						</select>
					</p>
				</div>

				<div data-wptl-series-condition="content-group">
				<p>
					<label for="wptl-classic-group"><strong><?php esc_html_e( 'Content group', 'wp-title-layer' ); ?></strong></label><br>
					<?php $wptl_current_group = \WPTitleLayer\Core\ContentGroups::for_post( (int) $post->ID ); ?>
					<input class="widefat" type="text" id="wptl-classic-group" list="wptl-classic-groups" name="<?php echo esc_attr( self::FIELD_ROOT ); ?>[series_group]" value="<?php echo esc_attr( $wptl_current_group ? $wptl_current_group->name : (string) get_post_meta( $post->ID, Schema::META_SERIES_GROUP, true ) ); ?>">
					<datalist id="wptl-classic-groups"></datalist>
					<span class="description"><?php esc_html_e( 'Optional group, for example W1 Seeing human weakness. Article numbers remain independent.', 'wp-title-layer' ); ?></span>
				</p>
				<div data-wptl-group-rename hidden>
					<button type="button" class="button-link" data-wptl-group-rename-start><?php esc_html_e( 'Rename this group', 'wp-title-layer' ); ?></button>
					<div data-wptl-group-rename-form hidden>
						<label for="wptl-classic-group-new"><?php esc_html_e( 'New group name', 'wp-title-layer' ); ?></label>
						<input type="text" id="wptl-classic-group-new" class="widefat">
						<p class="description"><?php esc_html_e( 'Renames this group for all its articles. Existing group links stay valid.', 'wp-title-layer' ); ?></p>
						<button type="button" class="button" data-wptl-group-rename-save><?php esc_html_e( 'Save group name', 'wp-title-layer' ); ?></button>
						<button type="button" class="button-link" data-wptl-group-rename-cancel><?php esc_html_e( 'Cancel', 'wp-title-layer' ); ?></button>
					</div>
				</div>
				<p data-wptl-group-status role="status"></p>
				</div>
				<p data-wptl-series-condition="inactive-group"><?php esc_html_e( 'The saved content group is inactive for this role. It will be restored if you switch back to a main article.', 'wp-title-layer' ); ?></p>
				<div data-wptl-series-condition="ordered">
					<div class="notice notice-info inline" data-wptl-series-condition="managed-ordered">
						<p>
							<strong data-wptl-managed-heading data-current-term-id="<?php echo esc_attr( (string) $current_id ); ?>" data-current-season-key="<?php echo esc_attr( $current_season ); ?>" data-current-ordinal="<?php echo esc_attr( (string) $current_ordinal ); ?>" data-automatic-label="<?php esc_attr_e( 'Automatic article number:', 'wp-title-layer' ); ?>" data-managed-label="<?php esc_attr_e( 'Managed sequence', 'wp-title-layer' ); ?>"><?php echo esc_html( $current_sequence_heading ); ?></strong><br>
							<?php esc_html_e( 'Sequence Manager owns the private rank. Saving after changing Series or season appends this article to that scope.', 'wp-title-layer' ); ?>
							<a href="<?php echo esc_url( $manager_url ); ?>" data-wptl-manager-link data-base-url="<?php echo esc_url( $manager_base_url ); ?>" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"><?php esc_html_e( 'Move in Sequence Manager', 'wp-title-layer' ); ?></a>
						</p>
					</div>
					<div class="wptl-classic-grid">
						<div data-wptl-series-condition="legacy-ordered">
							<p>
								<label for="wptl-classic-position"><strong><?php esc_html_e( 'Legacy sequence position', 'wp-title-layer' ); ?></strong></label><br>
								<input type="number" min="0" step="1" id="wptl-classic-position" name="<?php echo esc_attr( self::FIELD_ROOT ); ?>[sequence_position]" value="<?php echo esc_attr( metadata_exists( 'post', $post->ID, Schema::META_SEQUENCE_POSITION ) ? (string) get_post_meta( $post->ID, Schema::META_SEQUENCE_POSITION, true ) : '' ); ?>">
								<span class="description"><?php esc_html_e( 'Initialize this Series in Sequence Manager to insert articles without renumbering later entries.', 'wp-title-layer' ); ?></span>
							</p>
						</div>
					</div>
				</div>

				<div class="wptl-classic-grid">
					<p>
						<label for="wptl-classic-sequence-label"><strong><?php esc_html_e( 'Public structure label', 'wp-title-layer' ); ?></strong></label><br>
						<input type="text" id="wptl-classic-sequence-label" name="<?php echo esc_attr( self::FIELD_ROOT ); ?>[sequence_label]" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, Schema::META_SEQUENCE_LABEL, true ) ); ?>">
						<span class="description"><?php esc_html_e( 'Optional label such as 0-1, Preface II, or Appendix A.', 'wp-title-layer' ); ?></span>
					</p>

				</div>

				<div class="notice notice-info inline" data-wptl-series-condition="book-track">
					<p>
						<strong><?php esc_html_e( 'Managed structure track', 'wp-title-layer' ); ?></strong><br>
						<?php esc_html_e( 'Introductions, epilogues, and appendices have an independent private order.', 'wp-title-layer' ); ?>
						<a href="<?php echo esc_url( $manager_url ); ?>" data-wptl-manager-link data-base-url="<?php echo esc_url( $manager_base_url ); ?>" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"><?php esc_html_e( 'Move in Sequence & Structure', 'wp-title-layer' ); ?></a>
					</p>
				</div>


			<?php endif; ?>

			<div class="wptl-classic-grid">
				<p>
					<label for="wptl-classic-kicker"><strong><?php esc_html_e( 'Kicker override', 'wp-title-layer' ); ?></strong></label><br>
					<input type="text" id="wptl-classic-kicker" name="<?php echo esc_attr( self::FIELD_ROOT ); ?>[kicker_override]" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, Schema::META_KICKER_OVERRIDE, true ) ); ?>">
				</p>
				<p>
					<label for="wptl-classic-template"><strong><?php esc_html_e( 'Template override', 'wp-title-layer' ); ?></strong></label><br>
					<select id="wptl-classic-template" name="<?php echo esc_attr( self::FIELD_ROOT ); ?>[template_override]">
						<option value=""><?php esc_html_e( 'Inherit', 'wp-title-layer' ); ?></option>
						<option value="disabled" <?php selected( (string) get_post_meta( $post->ID, Schema::META_TEMPLATE_OVERRIDE, true ), 'disabled' ); ?>><?php esc_html_e( 'Do not display a Title Layer', 'wp-title-layer' ); ?></option>
						<?php foreach ( Presets::choices() as $preset ) : ?>
							<option value="<?php echo esc_attr( $preset['value'] ); ?>" <?php selected( (string) get_post_meta( $post->ID, Schema::META_TEMPLATE_OVERRIDE, true ), $preset['value'] ); ?>><?php echo esc_html( $preset['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			</div>
		</div>
		<?php
	}

	public static function savePost( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $update );
		if (
			( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| wp_is_post_revision( $post_id )
			|| ! current_user_can( 'edit_post', $post_id )
			|| ! isset( $_POST[ self::NONCE_NAME ] )
			|| ! is_string( $_POST[ self::NONCE_NAME ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION )
			|| ! isset( $_POST[ self::FIELD_ROOT ] )
			|| ! is_array( $_POST[ self::FIELD_ROOT ] )
		) {
			return;
		}

		$input = wp_unslash( $_POST[ self::FIELD_ROOT ] );
		if ( empty( $input['_present'] ) || ! in_array( $post->post_type, self::postTypes(), true ) ) {
			return;
		}

		// An explicit empty canonical Subtitle prevents a retained legacy value
		// from reappearing after the editor intentionally clears the field.
		if ( array_key_exists( 'subtitle', $input ) ) {
			update_post_meta( $post_id, Schema::META_SUBTITLE, Meta::sanitize_text( self::scalarInput( $input['subtitle'] ) ) );
		}

		$term = Series::get_primary_term( $post_id );
		if ( self::ownsSeriesControl() && array_key_exists( 'series_id', $input ) ) {
			$taxonomy = Series::claimed_taxonomy();
			$object   = '' !== $taxonomy ? get_taxonomy( $taxonomy ) : null;
			if ( $object instanceof \WP_Taxonomy && current_user_can( $object->cap->assign_terms ) ) {
				$term_id = absint( self::scalarInput( $input['series_id'] ) );
				$chosen  = 0 < $term_id ? get_term( $term_id, $taxonomy ) : null;
				if ( 0 === $term_id || $chosen instanceof \WP_Term ) {
					wp_set_object_terms( $post_id, $chosen instanceof \WP_Term ? array( $term_id ) : array(), $taxonomy, false );
					$term = $chosen instanceof \WP_Term ? $chosen : null;
				}
			}
		}

		$role = Meta::sanitize_role( get_post_meta( $post_id, Schema::META_SERIES_ROLE, true ) );
		if ( array_key_exists( 'series_role', $input ) ) {
			$role = Meta::sanitize_role( self::scalarInput( $input['series_role'] ) );
		}
		$scope = Meta::sanitize_content_scope( get_post_meta( $post_id, Schema::META_SERIES_SCOPE, true ) );
		if ( array_key_exists( 'series_scope', $input ) ) {
			$scope = Meta::sanitize_content_scope( self::scalarInput( $input['series_scope'] ) );
		}
		$main_article = '' === $role || Schema::ROLE_ARTICLE === $role;

		if ( ! $term instanceof \WP_Term ) {
			delete_post_meta( $post_id, Schema::META_SEASON_KEY );
			delete_post_meta( $post_id, Schema::META_SERIES_ROLE );
			delete_post_meta( $post_id, Schema::META_SERIES_SCOPE );
		} else {
			'' === $role ? delete_post_meta( $post_id, Schema::META_SERIES_ROLE ) : update_post_meta( $post_id, Schema::META_SERIES_ROLE, $role );
			if ( ! Series::is_seasoned( $term ) ) {
				delete_post_meta( $post_id, Schema::META_SEASON_KEY );
				delete_post_meta( $post_id, Schema::META_SERIES_SCOPE );
			} elseif ( $main_article ) {
				delete_post_meta( $post_id, Schema::META_SERIES_SCOPE );
				self::saveSeason( $post_id, $term, $input );
			} else {
				'' === $scope ? delete_post_meta( $post_id, Schema::META_SERIES_SCOPE ) : update_post_meta( $post_id, Schema::META_SERIES_SCOPE, $scope );
				if ( Schema::SCOPE_SERIES === $scope ) {
					delete_post_meta( $post_id, Schema::META_SEASON_KEY );
				} else {
					self::saveSeason( $post_id, $term, $input );
				}
			}
		}

		if ( array_key_exists( 'sequence_position', $input ) && ! ( $term instanceof \WP_Term && \WPTitleLayer\Core\Sequence::is_managed( $term ) ) ) {
			$position = self::scalarInput( $input['sequence_position'] );
			if ( '' !== trim( $position ) && is_numeric( $position ) && 0 <= (int) $position ) {
				update_post_meta( $post_id, Schema::META_SEQUENCE_POSITION, Meta::sanitize_nonnegative_integer( $position ) );
			} else {
				delete_post_meta( $post_id, Schema::META_SEQUENCE_POSITION );
			}
		}

		self::saveOptionalText( $post_id, Schema::META_SEQUENCE_LABEL, $input['sequence_label'] ?? null );
		self::saveOptionalText( $post_id, Schema::META_SERIES_GROUP, $input['series_group'] ?? null );
		\WPTitleLayer\Core\ContentGroups::sync( $post_id );
		self::saveOptionalText( $post_id, Schema::META_KICKER_OVERRIDE, $input['kicker_override'] ?? null );

		if ( array_key_exists( 'template_override', $input ) ) {
			$template = sanitize_key( self::scalarInput( $input['template_override'] ) );
			$allowed  = array_merge( array( 'disabled' ), wp_list_pluck( Presets::choices(), 'value' ) );
			in_array( $template, $allowed, true )
				? update_post_meta( $post_id, Schema::META_TEMPLATE_OVERRIDE, $template )
				: delete_post_meta( $post_id, Schema::META_TEMPLATE_OVERRIDE );
		}
	}

	/** @param array<string,mixed> $input */
	private static function saveSeason( int $post_id, \WP_Term $term, array $input ): void {
		if ( ! array_key_exists( 'season_key', $input ) ) {
			return;
		}
		$season_key = Meta::sanitize_key( self::scalarInput( $input['season_key'] ) );
		if ( '' !== $season_key && Series::season( $term, $season_key ) ) {
			update_post_meta( $post_id, Schema::META_SEASON_KEY, $season_key );
		} else {
			delete_post_meta( $post_id, Schema::META_SEASON_KEY );
		}
	}

	/** @param mixed $value */
	private static function saveOptionalText( int $post_id, string $key, $value ): void {
		if ( null === $value ) {
			return;
		}
		$value = Meta::sanitize_text( self::scalarInput( $value ) );
		'' === $value ? delete_post_meta( $post_id, $key ) : update_post_meta( $post_id, $key, $value );
	}

	/** @param mixed $value */
	private static function scalarInput( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function enqueueAssets(): void {
		$base_url = defined( 'WPTL_URL' ) ? trailingslashit( (string) WPTL_URL ) : plugin_dir_url( dirname( __DIR__, 2 ) . '/wp-title-layer.php' );
		$version  = defined( 'WPTL_VERSION' ) ? (string) WPTL_VERSION : '1.0.0-rc.1';
		wp_enqueue_script( 'wptl-classic-editor', $base_url . 'assets/classic-editor.js', array( 'wp-api-fetch' ), $version, true );
		$taxonomy = get_taxonomy( Series::claimed_taxonomy() );
		wp_localize_script( 'wptl-classic-editor', 'WPTLGroupEditor', array(
			'canRename' => $taxonomy && current_user_can( $taxonomy->cap->edit_terms ),
			'loadError' => __( 'Group suggestions could not be loaded. You can still enter a name.', 'wp-title-layer' ),
			'renameError' => __( 'Group rename failed.', 'wp-title-layer' ),
		) );
		wp_enqueue_style( 'wptl-classic-editor', $base_url . 'assets/classic-editor.css', array(), $version );
	}
}
