<?php
/**
 * Series taxonomy, four-mode model, ordering, and REST invariants.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Core;

defined( 'ABSPATH' ) || exit;

final class Series {
	private static $enforcing_single         = false;
	private static $rest_hooks               = [];
	private static $claimed_taxonomy         = '';
	private static $rest_term_guard_registered = false;
	private static $runtime_hooks_registered = false;
	private static $taxonomy_hooks           = [];

	public static function register_taxonomy(): void {
		$taxonomy    = Schema::taxonomy();
		$object_types = (array) apply_filters( 'wptl_series_object_types', [ 'post' ] );
		$object_types = array_values( array_filter( array_map( 'sanitize_key', $object_types ) ) );
		if ( '' !== self::$claimed_taxonomy && self::$claimed_taxonomy !== $taxonomy ) {
			do_action( 'wptl_series_taxonomy_claim_rejected', $taxonomy, get_taxonomy( $taxonomy ), 'another_taxonomy_claimed' );
			return;
		}

		if ( taxonomy_exists( $taxonomy ) ) {
			$existing = get_taxonomy( $taxonomy );
			if ( ! $existing instanceof \WP_Taxonomy ) {
				return;
			}

			if ( ! self::is_claimed_taxonomy( $taxonomy ) ) {
				$explicit_claim = Schema::TAXONOMY_SERIES === $taxonomy || (bool) apply_filters(
					'wptl_claim_existing_series_taxonomy',
					false,
					$existing,
					$taxonomy,
					$object_types
				);
				if ( empty( $existing->public ) || empty( $existing->show_ui ) || ! $explicit_claim ) {
					do_action( 'wptl_series_taxonomy_claim_rejected', $taxonomy, $existing, 'existing_taxonomy_not_claimable' );
					return;
				}
				self::claim_taxonomy( $taxonomy );
			} elseif ( empty( $existing->public ) || empty( $existing->show_ui ) ) {
				self::relinquish_taxonomy( $taxonomy );
				do_action( 'wptl_series_taxonomy_claim_rejected', $taxonomy, $existing, 'claimed_taxonomy_became_private' );
				return;
			}

			self::register_rest_term_write_guard();
			self::ensure_rest_visibility( $existing, $taxonomy );
			foreach ( $object_types as $object_type ) {
				register_taxonomy_for_object_type( $taxonomy, $object_type );
			}
			do_action( 'wptl_existing_series_taxonomy', $taxonomy, get_taxonomy( $taxonomy ) );
			return;
		}

		$labels = [
			'name'                       => __( 'Series', 'wp-title-layer' ),
			'singular_name'              => __( 'Series', 'wp-title-layer' ),
			'search_items'               => __( 'Search Series', 'wp-title-layer' ),
			'all_items'                  => __( 'All Series', 'wp-title-layer' ),
			'edit_item'                  => __( 'Edit Series', 'wp-title-layer' ),
			'update_item'                => __( 'Update Series', 'wp-title-layer' ),
			'add_new_item'               => __( 'Add New Series', 'wp-title-layer' ),
			'new_item_name'              => __( 'New Series Name', 'wp-title-layer' ),
			'menu_name'                  => __( 'Series', 'wp-title-layer' ),
			'not_found'                  => __( 'No Series found.', 'wp-title-layer' ),
			'back_to_items'              => __( 'Back to Series', 'wp-title-layer' ),
			'item_link'                  => __( 'Series Link', 'wp-title-layer' ),
			'item_link_description'      => __( 'A link to a Series.', 'wp-title-layer' ),
		];

		$args = [
			'labels'            => $labels,
			'public'            => true,
			'publicly_queryable'=> true,
			'hierarchical'      => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_quick_edit'=> false,
			'show_in_rest'      => true,
			'rest_base'         => $taxonomy,
			'query_var'         => $taxonomy,
			'rewrite'           => [ 'slug' => 'series', 'with_front' => false ],
			// Gutenberg Presentation owns a single-select control. This also
			// prevents the native tag box from offering an invalid multi-select.
			'meta_box_cb'       => false,
		];

		$registration_args = (array) apply_filters( 'wptl_series_taxonomy_args', $args, $taxonomy, $object_types );
		if ( empty( $registration_args['public'] ) || empty( $registration_args['show_ui'] ) ) {
			do_action( 'wptl_series_taxonomy_claim_rejected', $taxonomy, null, 'plugin_taxonomy_not_public' );
			return;
		}

		$registered = register_taxonomy(
			$taxonomy,
			$object_types,
			$registration_args
		);

		if ( $registered instanceof \WP_Taxonomy ) {
			self::claim_taxonomy( $taxonomy );
			self::ensure_rest_visibility( $registered, $taxonomy );
		}
	}

	/**
	 * Keep a deliberately reused taxonomy available to Gutenberg.
	 *
	 * Existing terms can come from an earlier implementation, ACF, or theme
	 * code. Re-registering that taxonomy would overwrite unrelated behavior, so
	 * only the REST properties required by the block editor are promoted here.
	 */
	private static function ensure_rest_visibility( \WP_Taxonomy $object, string $taxonomy ): void {
		$object->show_in_rest = true;
		// The native quick/bulk control is a multi-term tag widget and cannot
		// represent WPTL's one-Series structural contract. Full editors and the
		// Sequence & Structure owns relationship changes instead.
		$object->show_in_quick_edit = false;
		if ( ! is_string( $object->rest_base ) || '' === $object->rest_base ) {
			$object->rest_base = $taxonomy;
		}
		if ( ! is_string( $object->rest_namespace ) || '' === $object->rest_namespace ) {
			$object->rest_namespace = 'wp/v2';
		}
	}

	/** Return the concrete taxonomy this request has safely claimed. */
	public static function claimed_taxonomy(): string {
		return self::$claimed_taxonomy;
	}

	private static function is_claimed_taxonomy( string $taxonomy ): bool {
		return '' !== self::$claimed_taxonomy && self::$claimed_taxonomy === sanitize_key( $taxonomy );
	}

	private static function claim_taxonomy( string $taxonomy ): void {
		$taxonomy = sanitize_key( $taxonomy );
		if ( '' === $taxonomy || ( '' !== self::$claimed_taxonomy && self::$claimed_taxonomy !== $taxonomy ) ) {
			return;
		}

		self::$claimed_taxonomy = $taxonomy;
		self::register_rest_term_write_guard();
		self::register_claimed_taxonomy_hooks();
	}

	private static function relinquish_taxonomy( string $taxonomy ): void {
		$taxonomy = sanitize_key( $taxonomy );
		if ( ! self::is_claimed_taxonomy( $taxonomy ) ) {
			return;
		}

		remove_action( "{$taxonomy}_add_form_fields", [ self::class, 'render_add_term_fields' ] );
		remove_action( "{$taxonomy}_edit_form_fields", [ self::class, 'render_edit_term_fields' ], 10 );
		remove_action( "created_{$taxonomy}", [ self::class, 'save_term_fields' ] );
		remove_action( "edited_{$taxonomy}", [ self::class, 'save_term_fields' ] );
		remove_filter( "manage_edit-{$taxonomy}_columns", [ self::class, 'add_status_column' ] );
		remove_filter( "manage_{$taxonomy}_custom_column", [ self::class, 'render_status_column' ], 10 );
		unset( self::$taxonomy_hooks[ $taxonomy ] );
		self::$claimed_taxonomy = '';
	}

	/**
	 * Restore the editor contract after another plugin re-registers the same
	 * taxonomy later during init (for example an ACF taxonomy definition).
	 */
	public static function ensure_rest_registration(): void {
		$taxonomy = self::$claimed_taxonomy;
		if ( '' === $taxonomy ) {
			return;
		}
		$object   = get_taxonomy( $taxonomy );

		if ( ! $object instanceof \WP_Taxonomy ) {
			return;
		}
		if ( empty( $object->public ) || empty( $object->show_ui ) ) {
			self::relinquish_taxonomy( $taxonomy );
			return;
		}

		self::ensure_rest_visibility( $object, $taxonomy );
		$object_types = (array) apply_filters( 'wptl_series_object_types', [ 'post' ] );
		foreach ( array_filter( array_map( 'sanitize_key', $object_types ) ) as $object_type ) {
			register_taxonomy_for_object_type( $taxonomy, $object_type );
		}
	}

	/**
	 * Repair only the active Series taxonomy when a third party replaces its
	 * registry object after WP Title Layer has initialized.
	 */
	public static function preserve_rest_registration( string $taxonomy ): void {
		if ( self::is_claimed_taxonomy( $taxonomy ) ) {
			self::ensure_rest_registration();
		}
	}

	public static function register_runtime_hooks(): void {
		if ( self::$runtime_hooks_registered ) {
			return;
		}

		self::$runtime_hooks_registered = true;
		add_action( 'registered_taxonomy', [ self::class, 'preserve_rest_registration' ], PHP_INT_MAX );
		add_action( 'set_object_terms', [ self::class, 'enforce_single_series' ], 10, 6 );
		add_filter( 'query_vars', [ self::class, 'register_public_query_vars' ] );
		// Run after themes so the Series definition remains authoritative on its
		// own archive without affecting any other taxonomy query.
		add_action( 'pre_get_posts', [ self::class, 'prepare_archive_query' ], PHP_INT_MAX );
		add_filter( 'posts_clauses', [ self::class, 'order_archive_clauses' ], PHP_INT_MAX, 2 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_admin_assets' ] );
		add_action( 'rest_api_init', [ self::class, 'register_rest_validation_hooks' ] );
		add_action( 'template_redirect', [ self::class, 'redirect_legacy_season_url' ], 9 );
		self::register_claimed_taxonomy_hooks();
	}

	/** Register the content-model REST permission boundary exactly once. */
	private static function register_rest_term_write_guard(): void {
		if ( self::$rest_term_guard_registered ) {
			return;
		}

		add_filter( 'rest_pre_dispatch', [ self::class, 'guard_rest_term_writes' ], 10, 3 );
		self::$rest_term_guard_registered = true;
	}

	/**
	 * Keep assign-only users from using the term endpoint as a term-management
	 * endpoint. WordPress permits non-hierarchical term creation with the weaker
	 * assign_terms capability, while WP Title Layer deliberately treats Series
	 * definitions as administrator/editor-managed content.
	 *
	 * @param mixed            $response Pre-dispatch response, when another filter handled it.
	 * @param \WP_REST_Server  $server   REST server instance.
	 * @param \WP_REST_Request $request  Current REST request.
	 * @return mixed
	 */
	public static function guard_rest_term_writes( $response, \WP_REST_Server $server, \WP_REST_Request $request ) {
		unset( $server );

		if ( null !== $response || '' === self::$claimed_taxonomy ) {
			return $response;
		}

		$method = strtoupper( $request->get_method() );
		if ( in_array( $method, [ 'GET', 'HEAD', 'OPTIONS' ], true ) ) {
			return $response;
		}

		$object = get_taxonomy( self::$claimed_taxonomy );
		if ( ! $object instanceof \WP_Taxonomy || empty( $object->show_in_rest ) ) {
			return $response;
		}

		$namespace = is_string( $object->rest_namespace ) && '' !== $object->rest_namespace
			? trim( $object->rest_namespace, '/' )
			: 'wp/v2';
		$rest_base = is_string( $object->rest_base ) && '' !== $object->rest_base
			? trim( $object->rest_base, '/' )
			: self::$claimed_taxonomy;
		$collection_route = '/' . $namespace . '/' . $rest_base;
		$route            = untrailingslashit( $request->get_route() );

		if ( $collection_route !== $route && 0 !== strpos( $route, $collection_route . '/' ) ) {
			return $response;
		}

		$capability = 'DELETE' === $method ? $object->cap->delete_terms : $object->cap->edit_terms;
		if ( current_user_can( $capability ) ) {
			return $response;
		}

		return new \WP_Error(
			'wptl_rest_cannot_write_series_terms',
			__( 'Sorry, you are not allowed to create or change Series terms.', 'wp-title-layer' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}

	private static function register_claimed_taxonomy_hooks(): void {
		$taxonomy = self::$claimed_taxonomy;
		if ( ! self::$runtime_hooks_registered || '' === $taxonomy || isset( self::$taxonomy_hooks[ $taxonomy ] ) ) {
			return;
		}

		add_action( "{$taxonomy}_add_form_fields", [ self::class, 'render_add_term_fields' ] );
		add_action( "{$taxonomy}_edit_form_fields", [ self::class, 'render_edit_term_fields' ], 10, 2 );
		add_action( "created_{$taxonomy}", [ self::class, 'save_term_fields' ] );
		add_action( "edited_{$taxonomy}", [ self::class, 'save_term_fields' ] );
		add_filter( "manage_edit-{$taxonomy}_columns", [ self::class, 'add_status_column' ] );
		add_filter( "manage_{$taxonomy}_custom_column", [ self::class, 'render_status_column' ], 10, 3 );
		self::$taxonomy_hooks[ $taxonomy ] = true;
	}

	public static function render_add_term_fields(): void {
		if ( '' === self::$claimed_taxonomy ) {
			return;
		}
		self::render_term_nonce();
		self::render_category_select( 0 );
		self::render_add_select( Schema::TERM_META_SERIES_MODE, __( 'Reading mode', 'wp-title-layer' ), Schema::modes(), Schema::MODE_UNORDERED );
		self::render_add_select( Schema::TERM_META_SERIES_STRUCTURE, __( 'Structure', 'wp-title-layer' ), Schema::structures(), Schema::STRUCTURE_FLAT );
		self::render_add_select(
			Schema::TERM_META_NAVIGATION_SCOPE,
			__( 'Reading navigation scope', 'wp-title-layer' ),
			Schema::navigation_scopes(),
			Schema::NAVIGATION_SCOPE_SERIES,
			__( 'Used by ordered Series with seasons. Entire Series may cross a season boundary; Current Season stops at that season’s first and last article.', 'wp-title-layer' )
		);
		self::render_add_select(
			Schema::TERM_META_SERIES_STATUS,
			__( 'Series status', 'wp-title-layer' ),
			array_merge( [ '' ], Schema::statuses() ),
			'',
			__( 'Descriptive only. It does not publish, hide, lock, or reorder articles.', 'wp-title-layer' )
		);
		self::render_add_select(
			Schema::TERM_META_ARCHIVE_SORT,
			__( 'Archive sort', 'wp-title-layer' ),
			Schema::archive_sorts(),
			Schema::ARCHIVE_SORT_DATE_DESC,
			__( 'Used by unordered Series. Ordered Series always follow their reading sequence; seasoned unordered Series apply this order within each season.', 'wp-title-layer' )
		);
		self::render_add_select(
			Schema::TERM_META_ARCHIVE_LAYOUT,
			__( 'Archive layout for this Series', 'wp-title-layer' ),
			Schema::archive_layouts(),
			Schema::ARCHIVE_LAYOUT_INHERIT,
			__( 'Inherit uses the site-wide Reader setting. Theme keeps the active theme layout; Structured requests WP Title Layer’s season-aware layout when the theme can be safely replaced.', 'wp-title-layer' )
		);
		self::render_add_select(
			Schema::TERM_META_ARCHIVE_SUBTITLES,
			__( 'Article subtitles on this archive', 'wp-title-layer' ),
			Schema::archive_visibility_overrides(),
			Schema::ARCHIVE_VISIBILITY_INHERIT,
			__( 'Overrides the relevant structured or supported-theme subtitle setting for this Series only.', 'wp-title-layer' )
		);
		self::render_add_select(
			Schema::TERM_META_ARCHIVE_EXCERPTS,
			__( 'Article excerpts on this archive', 'wp-title-layer' ),
			Schema::archive_visibility_overrides(),
			Schema::ARCHIVE_VISIBILITY_INHERIT,
			__( 'Used only by WP Title Layer’s structured archive. The inherited site-wide default is off.', 'wp-title-layer' )
		);
		self::render_add_select(
			Schema::TERM_META_ARCHIVE_FEATURED_IMAGES,
			__( 'Featured images on this archive', 'wp-title-layer' ),
			Schema::archive_visibility_overrides(),
			Schema::ARCHIVE_VISIBILITY_INHERIT,
			__( 'Used only by WP Title Layer’s structured archive. The default is inherited and remains off on upgraded sites.', 'wp-title-layer' )
		);
		self::render_add_input( Schema::TERM_META_SHORT_LABEL, __( 'Short label', 'wp-title-layer' ), 'text' );
		self::render_add_template_select();
		self::render_cover_selector( 0 );
		self::render_add_select(
			Schema::TERM_META_TITLE_ICON,
			__( 'Series icon in article title layers', 'wp-title-layer' ),
			Schema::archive_visibility_overrides(),
			Schema::ARCHIVE_VISIBILITY_INHERIT,
			__( 'Inherit uses the site-wide Title presentation setting. The icon is decorative and never becomes part of the article title or SEO title.', 'wp-title-layer' )
		);
		self::render_icon_selector( 0 );
		self::render_add_seasons( [] );
		echo '<div class="form-field"><label>' . esc_html__( 'Book structure', 'wp-title-layer' ) . '</label><p>' . esc_html__( 'New Series start with the 0.11 book-structure model. Existing Series use a separate read-only preview before activation.', 'wp-title-layer' ) . '</p></div>';
	}

	public static function render_edit_term_fields( \WP_Term $term ): void {
		if ( ! self::is_claimed_taxonomy( $term->taxonomy ) ) {
			return;
		}
		self::render_term_nonce();
		self::render_category_select( self::parent_category_id( $term ), true );
		self::render_edit_select( $term, Schema::TERM_META_SERIES_MODE, __( 'Reading mode', 'wp-title-layer' ), Schema::modes(), self::mode( $term ) );
		self::render_edit_select( $term, Schema::TERM_META_SERIES_STRUCTURE, __( 'Structure', 'wp-title-layer' ), Schema::structures(), self::structure( $term ) );
		self::render_edit_select(
			$term,
			Schema::TERM_META_NAVIGATION_SCOPE,
			__( 'Reading navigation scope', 'wp-title-layer' ),
			Schema::navigation_scopes(),
			self::navigation_scope( $term ),
			__( 'Used by ordered Series with seasons. Existing Series default to Entire Series, which may cross season boundaries.', 'wp-title-layer' )
		);
		self::render_edit_select(
			$term,
			Schema::TERM_META_SERIES_STATUS,
			__( 'Series status', 'wp-title-layer' ),
			array_merge( [ '' ], Schema::statuses() ),
			self::status( $term ),
			__( 'Descriptive only. It does not publish, hide, lock, or reorder articles.', 'wp-title-layer' )
		);
		self::render_edit_select(
			$term,
			Schema::TERM_META_ARCHIVE_SORT,
			__( 'Archive sort', 'wp-title-layer' ),
			Schema::archive_sorts(),
			self::archive_sort( $term ),
			__( 'Used by unordered Series. Ordered Series always follow their reading sequence; seasoned unordered Series apply this order within each season.', 'wp-title-layer' )
		);
		self::render_edit_select(
			$term,
			Schema::TERM_META_ARCHIVE_LAYOUT,
			__( 'Archive layout for this Series', 'wp-title-layer' ),
			Schema::archive_layouts(),
			self::archive_layout( $term ),
			__( 'Inherit uses the site-wide Reader setting. Theme keeps the active theme layout; Structured requests WP Title Layer’s season-aware layout when the theme can be safely replaced.', 'wp-title-layer' )
		);
		self::render_edit_select(
			$term,
			Schema::TERM_META_ARCHIVE_SUBTITLES,
			__( 'Article subtitles on this archive', 'wp-title-layer' ),
			Schema::archive_visibility_overrides(),
			self::archive_subtitles( $term ),
			__( 'Overrides the relevant structured or supported-theme subtitle setting for this Series only.', 'wp-title-layer' )
		);
		self::render_edit_select(
			$term,
			Schema::TERM_META_ARCHIVE_EXCERPTS,
			__( 'Article excerpts on this archive', 'wp-title-layer' ),
			Schema::archive_visibility_overrides(),
			self::archive_excerpts( $term ),
			__( 'Used only by WP Title Layer’s structured archive. The inherited site-wide default is off.', 'wp-title-layer' )
		);
		self::render_edit_select(
			$term,
			Schema::TERM_META_ARCHIVE_FEATURED_IMAGES,
			__( 'Featured images on this archive', 'wp-title-layer' ),
			Schema::archive_visibility_overrides(),
			self::archive_featured_images( $term ),
			__( 'Used only by WP Title Layer’s structured archive. The default is inherited and remains off on upgraded sites.', 'wp-title-layer' )
		);
		self::render_edit_input( $term, Schema::TERM_META_SHORT_LABEL, __( 'Short label', 'wp-title-layer' ), 'text' );
		self::render_edit_template_select( $term );
		self::render_cover_selector( (int) get_term_meta( $term->term_id, Schema::TERM_META_COVER_ID, true ), true );
		self::render_edit_select(
			$term,
			Schema::TERM_META_TITLE_ICON,
			__( 'Series icon in article title layers', 'wp-title-layer' ),
			Schema::archive_visibility_overrides(),
			self::title_icon( $term ),
			__( 'Inherit uses the site-wide Title presentation setting. The icon is decorative and never becomes part of the article title or SEO title.', 'wp-title-layer' )
		);
		self::render_icon_selector( (int) get_term_meta( $term->term_id, Schema::TERM_META_ICON_ID, true ), true );
		self::render_edit_seasons( self::seasons( $term ) );
		self::render_book_structure_status( $term );
	}

	private static function render_book_structure_status( \WP_Term $term ): void {
		$enabled = BookStructure::is_enabled( $term );
		$url = add_query_arg(
			array( 'page' => 'wp-title-layer-sequence-manager', 'series_id' => (int) $term->term_id, 'manager_view' => 'structure' ),
			admin_url( 'admin.php' )
		);
		?>
		<tr class="form-field term-wptl-book-structure-wrap">
			<th scope="row"><?php esc_html_e( 'Book structure', 'wp-title-layer' ); ?></th>
			<td>
				<strong><?php echo esc_html( $enabled ? __( 'Active', 'wp-title-layer' ) : __( 'Compatibility mode', 'wp-title-layer' ) ); ?></strong>
				<p class="description"><?php echo esc_html( $enabled ? __( 'Series/Season scope and role tracks are canonical for this Series.', 'wp-title-layer' ) : __( 'Current front-end order is unchanged. Review legacy roles and activate advanced structure in Sequence & Structure when ready.', 'wp-title-layer' ) ); ?></p>
				<a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open Sequence & Structure', 'wp-title-layer' ); ?></a>
			</td>
		</tr>
		<?php
	}

	private static function render_term_nonce(): void {
		wp_nonce_field( 'wptl_save_series_term', 'wptl_series_term_nonce' );
	}

	private static function render_category_select( int $selected, bool $table_row = false ): void {
		$key = Schema::TERM_META_PARENT_CATEGORY_ID;
		$dropdown = wp_dropdown_categories(
			array(
				'taxonomy'          => 'category',
				'hide_empty'        => false,
				'hierarchical'      => true,
				'name'              => 'wptl_term[' . $key . ']',
				'id'                => $key,
				'selected'          => max( 0, $selected ),
				'show_option_none'  => __( 'No parent Category', 'wp-title-layer' ),
				'option_none_value' => '0',
				'echo'              => false,
			)
		);
		$description = __( 'Optional editorial organization only. It does not make Series a child taxonomy and never adds or removes Category assignments on articles.', 'wp-title-layer' );
		$allowed = array(
			'select' => array( 'name' => true, 'id' => true, 'class' => true ),
			'option' => array( 'value' => true, 'selected' => true, 'class' => true ),
		);

		if ( $table_row ) {
			echo '<tr class="form-field term-' . esc_attr( $key ) . '-wrap">';
			echo '<th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html__( 'Parent Category', 'wp-title-layer' ) . '</label></th>';
			echo '<td>' . wp_kses( (string) $dropdown, $allowed ) . '<p class="description">' . esc_html( $description ) . '</p></td>';
			echo '</tr>';
			return;
		}

		echo '<div class="form-field term-' . esc_attr( $key ) . '-wrap">';
		echo '<label for="' . esc_attr( $key ) . '">' . esc_html__( 'Parent Category', 'wp-title-layer' ) . '</label>';
		echo wp_kses( (string) $dropdown, $allowed );
		echo '<p>' . esc_html( $description ) . '</p>';
		echo '</div>';
	}

	private static function render_add_select( string $key, string $label, array $values, string $current, string $description = '' ): void {
		?>
		<div class="form-field term-<?php echo esc_attr( $key ); ?>-wrap">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<select name="wptl_term[<?php echo esc_attr( $key ); ?>]" id="<?php echo esc_attr( $key ); ?>">
				<?php foreach ( $values as $value ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( self::humanize( $value ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( '' !== $description ) : ?>
				<p><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_edit_select( \WP_Term $term, string $key, string $label, array $values, string $current, string $description = '' ): void {
		?>
		<tr class="form-field term-<?php echo esc_attr( $key ); ?>-wrap">
			<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><select name="wptl_term[<?php echo esc_attr( $key ); ?>]" id="<?php echo esc_attr( $key ); ?>">
				<?php foreach ( $values as $value ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( self::humanize( $value ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( '' !== $description ) : ?>
				<p class="description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function render_add_template_select(): void {
		self::render_template_select( '' );
	}

	private static function render_edit_template_select( \WP_Term $term ): void {
		self::render_template_select( (string) get_term_meta( $term->term_id, Schema::TERM_META_DEFAULT_TEMPLATE, true ), true );
	}

	private static function render_template_select( string $current, bool $table_row = false ): void {
		$key     = Schema::TERM_META_DEFAULT_TEMPLATE;
		$choices = self::template_choices();
		if ( $table_row ) {
			?>
			<tr class="form-field term-<?php echo esc_attr( $key ); ?>-wrap">
				<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Default template', 'wp-title-layer' ); ?></label></th>
				<td>
					<select name="wptl_term[<?php echo esc_attr( $key ); ?>]" id="<?php echo esc_attr( $key ); ?>">
						<?php foreach ( $choices as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Used when an article in this Series does not choose its own template.', 'wp-title-layer' ); ?></p>
				</td>
			</tr>
			<?php
			return;
		}
		?>
		<div class="form-field term-<?php echo esc_attr( $key ); ?>-wrap">
			<label for="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Default template', 'wp-title-layer' ); ?></label>
			<select name="wptl_term[<?php echo esc_attr( $key ); ?>]" id="<?php echo esc_attr( $key ); ?>">
				<?php foreach ( $choices as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<p><?php esc_html_e( 'Used when an article in this Series does not choose its own template.', 'wp-title-layer' ); ?></p>
		</div>
		<?php
	}

	/** @return array<string,string> */
	private static function template_choices(): array {
		$choices = [ '' => __( 'Use category or global default', 'wp-title-layer' ) ];
		if ( class_exists( '\\WPTitleLayer\\Presentation\\Presets' ) ) {
			foreach ( \WPTitleLayer\Presentation\Presets::choices() as $choice ) {
				$value = sanitize_key( (string) ( $choice['value'] ?? '' ) );
				if ( '' !== $value ) {
					$choices[ $value ] = sanitize_text_field( (string) ( $choice['label'] ?? $value ) );
				}
			}
		}

		return (array) apply_filters( 'wptl_series_template_choices', $choices );
	}

	private static function render_add_input( string $key, string $label, string $type ): void {
		?>
		<div class="form-field term-<?php echo esc_attr( $key ); ?>-wrap">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="<?php echo esc_attr( $type ); ?>" name="wptl_term[<?php echo esc_attr( $key ); ?>]" id="<?php echo esc_attr( $key ); ?>" value="" <?php echo 'number' === $type ? 'min="0" step="1"' : ''; ?> />
		</div>
		<?php
	}

	private static function render_edit_input( \WP_Term $term, string $key, string $label, string $type ): void {
		$value = get_term_meta( $term->term_id, $key, true );
		?>
		<tr class="form-field term-<?php echo esc_attr( $key ); ?>-wrap">
			<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input type="<?php echo esc_attr( $type ); ?>" name="wptl_term[<?php echo esc_attr( $key ); ?>]" id="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" <?php echo 'number' === $type ? 'min="0" step="1"' : ''; ?> /></td>
		</tr>
		<?php
	}

	private static function render_cover_selector( int $attachment_id, bool $table_row = false ): void {
		self::render_media_selector(
			Schema::TERM_META_COVER_ID,
			$attachment_id,
			__( 'Series cover', 'wp-title-layer' ),
			__( 'Used by the structured Series archive. The attachment ID remains the canonical stored value.', 'wp-title-layer' ),
			'wptl-cover-control',
			$table_row
		);
	}

	private static function render_icon_selector( int $attachment_id, bool $table_row = false ): void {
		self::render_media_selector(
			Schema::TERM_META_ICON_ID,
			$attachment_id,
			__( 'Series icon', 'wp-title-layer' ),
			__( 'Choose a small image used decoratively beside this Series name in article title layers. It is separate from the Series cover.', 'wp-title-layer' ),
			'wptl-icon-control',
			$table_row
		);
	}

	/**
	 * Render a progressive image-attachment control.
	 *
	 * JavaScript upgrades the numeric attachment field to the WordPress media
	 * library. If media scripts are unavailable, the visible attachment-ID
	 * field remains usable and existing data is never cleared.
	 */
	private static function render_media_selector( string $key, int $attachment_id, string $label, string $description, string $modifier_class, bool $table_row ): void {
		$attachment_id = max( 0, $attachment_id );
		$image         = 0 < $attachment_id && 'attachment' === get_post_type( $attachment_id ) && wp_attachment_is_image( $attachment_id )
			? wp_get_attachment_image( $attachment_id, 'thumbnail', false, array( 'class' => 'wptl-cover-preview__image' ) )
			: '';

		ob_start();
		?>
		<div class="wptl-cover-control <?php echo esc_attr( sanitize_html_class( $modifier_class ) ); ?>" data-wptl-cover-control>
			<div class="wptl-cover-preview" data-wptl-cover-preview><?php echo wp_kses_post( $image ); ?></div>
			<input
				type="number"
				class="small-text wptl-cover-id-input"
				name="wptl_term[<?php echo esc_attr( $key ); ?>]"
				id="<?php echo esc_attr( $key ); ?>"
				value="<?php echo esc_attr( (string) $attachment_id ); ?>"
				min="0"
				step="1"
				aria-describedby="<?php echo esc_attr( $key . '-description' ); ?>"
			>
			<p class="wptl-cover-actions">
				<button type="button" class="button wptl-select-cover"><?php esc_html_e( 'Choose image', 'wp-title-layer' ); ?></button>
				<button type="button" class="button-link-delete wptl-remove-cover"<?php echo '' === $image ? ' hidden' : ''; ?>><?php esc_html_e( 'Remove image', 'wp-title-layer' ); ?></button>
			</p>
			<p class="description" id="<?php echo esc_attr( $key . '-description' ); ?>"><?php echo esc_html( $description ); ?></p>
		</div>
		<?php
		$control = (string) ob_get_clean();

		if ( $table_row ) {
			?>
			<tr class="form-field term-<?php echo esc_attr( $key ); ?>-wrap">
				<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
				<td><?php echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped above. ?></td>
			</tr>
			<?php
			return;
		}
		?>
		<div class="form-field term-<?php echo esc_attr( $key ); ?>-wrap">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<?php echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped above. ?>
		</div>
		<?php
	}

	private static function render_add_seasons( array $seasons ): void {
		$rows = array_pad( $seasons, max( 4, count( $seasons ) + 3 ), [ 'key' => '', 'label' => '', 'sort' => 0 ] );
		?>
		<div class="form-field term-<?php echo esc_attr( Schema::TERM_META_SEASONS ); ?>-wrap">
			<label><?php esc_html_e( 'Seasons', 'wp-title-layer' ); ?></label>
			<?php self::render_season_rows( $rows ); ?>
			<?php self::render_add_season_button(); ?>
			<p><?php esc_html_e( 'Optional. Give each season a name and use order numbers such as 10, 20, 30.', 'wp-title-layer' ); ?></p>
		</div>
		<?php
	}

	private static function render_edit_seasons( array $seasons ): void {
		$rows = array_pad( $seasons, count( $seasons ) + 3, [ 'key' => '', 'label' => '', 'sort' => 0 ] );
		?>
		<tr class="form-field term-<?php echo esc_attr( Schema::TERM_META_SEASONS ); ?>-wrap">
			<th scope="row"><label><?php esc_html_e( 'Seasons', 'wp-title-layer' ); ?></label></th>
			<td>
				<?php self::render_season_rows( $rows ); ?>
				<?php self::render_add_season_button(); ?>
				<p class="description"><?php esc_html_e( 'Blank rows are ignored. Order numbers may leave gaps, making later insertion easier.', 'wp-title-layer' ); ?></p>
			</td>
		</tr>
		<?php
	}

	private static function render_season_rows( array $rows ): void {
		$key = Schema::TERM_META_SEASONS;
		?>
		<table class="widefat striped wptl-season-table" style="max-width:620px" data-next-index="<?php echo esc_attr( (string) count( $rows ) ); ?>">
			<thead><tr>
				<th><?php esc_html_e( 'Season name', 'wp-title-layer' ); ?></th>
				<th style="width:100px"><?php esc_html_e( 'Public URL ID', 'wp-title-layer' ); ?></th>
				<th style="width:120px"><?php esc_html_e( 'Order', 'wp-title-layer' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( array_values( $rows ) as $index => $season ) : ?>
				<?php
				$label = is_array( $season ) ? (string) ( $season['label'] ?? '' ) : '';
				$sort  = is_array( $season ) ? (int) ( $season['sort'] ?? 0 ) : 0;
				$public_id = is_array( $season ) ? max( 0, (int) ( $season['public_id'] ?? 0 ) ) : 0;
				?>
				<tr class="wptl-season-row">
					<td>
						<input type="hidden" name="wptl_term[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( (string) $index ); ?>][key]" value="<?php echo esc_attr( is_array( $season ) ? (string) ( $season['key'] ?? '' ) : '' ); ?>">
						<input type="text" class="regular-text" name="wptl_term[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( (string) $index ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'Season 1', 'wp-title-layer' ); ?>">
					</td>
					<td>
						<input type="hidden" name="wptl_term[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( (string) $index ); ?>][public_id]" value="<?php echo esc_attr( 0 < $public_id ? (string) $public_id : '' ); ?>">
						<span class="wptl-season-public-id"><?php echo 0 < $public_id ? esc_html( (string) $public_id ) : esc_html__( 'Assigned on save', 'wp-title-layer' ); ?></span>
					</td>
					<td><input type="number" name="wptl_term[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( (string) $index ); ?>][sort]" value="<?php echo esc_attr( $label ? (string) $sort : (string) ( ( $index + 1 ) * 10 ) ); ?>" step="1"></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_add_season_button(): void {
		?>
		<p><button type="button" class="button wptl-add-season-row"><?php esc_html_e( 'Add another season', 'wp-title-layer' ); ?></button></p>
		<?php
	}

	public static function enqueue_admin_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'edit-tags.php', 'term.php' ), true ) || '' === self::$claimed_taxonomy ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || self::$claimed_taxonomy !== (string) $screen->taxonomy ) {
			return;
		}

		$base_url = defined( 'WPTL_URL' )
			? trailingslashit( (string) WPTL_URL )
			: plugin_dir_url( dirname( __DIR__, 2 ) . '/wp-title-layer.php' );
		$version = defined( 'WPTL_VERSION' ) ? (string) WPTL_VERSION : '1.0.0-rc.1';
		wp_enqueue_media();
		wp_enqueue_script( 'wptl-series-admin', $base_url . 'assets/series-admin.js', array( 'media-editor' ), $version, true );
		wp_enqueue_style( 'wptl-series-admin', $base_url . 'assets/series-admin.css', array(), $version );
	}

	public static function save_term_fields( int $term_id ): void {
		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term || ! self::is_claimed_taxonomy( $term->taxonomy ) ) {
			return;
		}
		if ( ! isset( $_POST['wptl_series_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wptl_series_term_nonce'] ) ), 'wptl_save_series_term' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_term', $term_id ) || ! isset( $_POST['wptl_term'] ) || ! is_array( $_POST['wptl_term'] ) ) {
			return;
		}

		$input = wp_unslash( $_POST['wptl_term'] );
		$map = [
			Schema::TERM_META_SERIES_MODE      => [ Meta::class, 'sanitize_mode' ],
			Schema::TERM_META_SERIES_STATUS    => [ Meta::class, 'sanitize_status' ],
			Schema::TERM_META_SERIES_STRUCTURE => [ Meta::class, 'sanitize_structure' ],
			Schema::TERM_META_NAVIGATION_SCOPE => [ Meta::class, 'sanitize_navigation_scope' ],
			Schema::TERM_META_ARCHIVE_SORT     => [ Meta::class, 'sanitize_archive_sort' ],
			Schema::TERM_META_SHORT_LABEL      => [ Meta::class, 'sanitize_text' ],
			Schema::TERM_META_DEFAULT_TEMPLATE => [ Meta::class, 'sanitize_key' ],
			Schema::TERM_META_COVER_ID         => [ Meta::class, 'sanitize_nonnegative_integer' ],
			Schema::TERM_META_ICON_ID          => [ Meta::class, 'sanitize_nonnegative_integer' ],
			Schema::TERM_META_ARCHIVE_LAYOUT   => [ Meta::class, 'sanitize_archive_layout' ],
			Schema::TERM_META_ARCHIVE_SUBTITLES => [ Meta::class, 'sanitize_archive_visibility' ],
			Schema::TERM_META_ARCHIVE_EXCERPTS => [ Meta::class, 'sanitize_archive_visibility' ],
			Schema::TERM_META_ARCHIVE_FEATURED_IMAGES => [ Meta::class, 'sanitize_archive_visibility' ],
			Schema::TERM_META_TITLE_ICON       => [ Meta::class, 'sanitize_archive_visibility' ],
		];
		foreach ( $map as $key => $callback ) {
			if ( array_key_exists( $key, $input ) ) {
				$value = call_user_func( $callback, $input[ $key ] );
				if ( Schema::TERM_META_SERIES_STATUS === $key && '' === $value ) {
					delete_term_meta( $term_id, $key );
				} else {
					update_term_meta( $term_id, $key, $value );
				}
			}
		}
		if ( array_key_exists( Schema::TERM_META_PARENT_CATEGORY_ID, $input ) ) {
			$category_id = absint( $input[ Schema::TERM_META_PARENT_CATEGORY_ID ] );
			$category    = 0 < $category_id ? get_term( $category_id, 'category' ) : null;
			if ( $category instanceof \WP_Term && 'category' === $category->taxonomy ) {
				update_term_meta( $term_id, Schema::TERM_META_PARENT_CATEGORY_ID, $category_id );
			} else {
				delete_term_meta( $term_id, Schema::TERM_META_PARENT_CATEGORY_ID );
			}
		}
		if ( array_key_exists( Schema::TERM_META_SEASONS, $input ) ) {
			$season_input = $input[ Schema::TERM_META_SEASONS ];
			$seasons = is_array( $season_input )
				? self::normalize_season_rows( $season_input )
				: self::parse_season_lines( (string) $season_input );
			Sequence::persist_seasons( $term, $seasons );
		}
		if ( 0 === strpos( (string) current_filter(), 'created_' ) ) {
			$sequence_ready = Sequence::activate_empty( $term );
			if ( ! is_wp_error( $sequence_ready ) ) {
				update_term_meta( $term_id, Schema::TERM_META_BOOK_STRUCTURE_VERSION, BookStructure::VERSION );
			}
		}
	}

	private static function normalize_season_rows( array $rows ): array {
		$seasons = [];
		$used    = [];
		foreach ( array_values( $rows ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}

			$key = sanitize_key( (string) ( $row['key'] ?? '' ) );
			if ( '' === $key ) {
				$base = sanitize_key( $label );
				$base = '' !== $base ? $base : 'season';
				$key  = $base;
				$suffix = 2;
				while ( isset( $used[ $key ] ) ) {
					$key = $base . '-' . $suffix;
					++$suffix;
				}
			}
			if ( isset( $used[ $key ] ) ) {
				continue;
			}
			$used[ $key ] = true;
			$seasons[] = [
				'key'       => $key,
				'label'     => $label,
				'sort'      => isset( $row['sort'] ) && is_numeric( $row['sort'] ) ? (int) $row['sort'] : ( $index + 1 ) * 10,
				'public_id' => isset( $row['public_id'] ) ? max( 0, (int) $row['public_id'] ) : 0,
			];
		}

		return Meta::sanitize_seasons( $seasons );
	}

	private static function parse_season_lines( string $value ): array {
		$seasons = [];
		foreach ( preg_split( '/\R/u', $value ) ?: [] as $index => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 3 ) );
			$seasons[] = [
				'key'   => $parts[0] ?? '',
				'label' => $parts[1] ?? ( $parts[0] ?? '' ),
				'sort'  => isset( $parts[2] ) && is_numeric( $parts[2] ) ? (int) $parts[2] : ( $index + 1 ) * 10,
			];
		}
		return Meta::sanitize_seasons( $seasons );
	}

	private static function humanize( string $value ): string {
		$labels = [
			''                              => __( 'Not set', 'wp-title-layer' ),
			Schema::MODE_ORDERED            => __( 'Ordered', 'wp-title-layer' ),
			Schema::MODE_UNORDERED          => __( 'Unordered', 'wp-title-layer' ),
			Schema::STATUS_PLANNING         => __( 'Planning', 'wp-title-layer' ),
			Schema::STATUS_ONGOING          => __( 'Ongoing', 'wp-title-layer' ),
			Schema::STATUS_PAUSED           => __( 'Paused', 'wp-title-layer' ),
			Schema::STATUS_COMPLETED        => __( 'Completed', 'wp-title-layer' ),
			Schema::STRUCTURE_FLAT          => __( 'Flat', 'wp-title-layer' ),
			Schema::STRUCTURE_SEASONED      => __( 'Seasoned', 'wp-title-layer' ),
			Schema::NAVIGATION_SCOPE_SERIES => __( 'Entire Series', 'wp-title-layer' ),
			Schema::NAVIGATION_SCOPE_SEASON => __( 'Current Season', 'wp-title-layer' ),
			Schema::ARCHIVE_SORT_DATE_DESC  => __( 'Newest first', 'wp-title-layer' ),
			Schema::ARCHIVE_SORT_DATE_ASC   => __( 'Oldest first', 'wp-title-layer' ),
			Schema::ARCHIVE_SORT_TITLE      => __( 'Title', 'wp-title-layer' ),
			Schema::ARCHIVE_LAYOUT_INHERIT  => __( 'Inherit the site-wide setting', 'wp-title-layer' ),
			Schema::ARCHIVE_LAYOUT_THEME    => __( 'Use the active theme', 'wp-title-layer' ),
			Schema::ARCHIVE_LAYOUT_STRUCTURED => __( 'Use the structured Series layout', 'wp-title-layer' ),
			Schema::ARCHIVE_VISIBILITY_SHOW => __( 'Show', 'wp-title-layer' ),
			Schema::ARCHIVE_VISIBILITY_HIDE => __( 'Hide', 'wp-title-layer' ),
		];

		return $labels[ $value ] ?? ucwords( str_replace( '_', ' ', $value ) );
	}

	/** @param string[] $columns */
	public static function add_status_column( array $columns ): array {
		$with_status = [];
		$inserted    = false;
		foreach ( $columns as $key => $label ) {
			$with_status[ $key ] = $label;
			if ( 'name' === $key ) {
				$with_status['wptl_series_status'] = __( 'Status', 'wp-title-layer' );
				$inserted = true;
			}
		}
		if ( ! $inserted ) {
			$with_status['wptl_series_status'] = __( 'Status', 'wp-title-layer' );
		}

		return $with_status;
	}

	public static function render_status_column( string $content, string $column_name, int $term_id ): string {
		if ( 'wptl_series_status' !== $column_name ) {
			return $content;
		}

		$label = self::status_label( $term_id );
		return esc_html( '' !== $label ? $label : __( 'Not set', 'wp-title-layer' ) );
	}

	/** @return \WP_Term[] */
	public static function get_terms( int $post_id ): array {
		$taxonomy = self::$claimed_taxonomy;
		if ( '' === $taxonomy ) {
			return [];
		}

		$terms = wp_get_object_terms( $post_id, $taxonomy, [ 'orderby' => 'term_id', 'order' => 'ASC' ] );
		return is_wp_error( $terms ) ? [] : $terms;
	}

	public static function get_primary_term( int $post_id ): ?\WP_Term {
		$terms = self::get_terms( $post_id );
		return isset( $terms[0] ) ? $terms[0] : null;
	}

	/**
	 * Return the public HTTP(S) archive URL for a term owned by the active
	 * Series taxonomy. A missing, unclaimed, or non-queryable term deliberately
	 * degrades to plain text in every front-end renderer.
	 */
	public static function public_archive_url( $term ): string {
		if ( ! $term instanceof \WP_Term || ! self::is_claimed_taxonomy( $term->taxonomy ) ) {
			return '';
		}

		$taxonomy = get_taxonomy( $term->taxonomy );
		if ( ! $taxonomy instanceof \WP_Taxonomy || empty( $taxonomy->publicly_queryable ) ) {
			return '';
		}
		if ( function_exists( 'is_term_publicly_viewable' ) && ! is_term_publicly_viewable( $term ) ) {
			return '';
		}

		$url = get_term_link( $term );
		if ( is_wp_error( $url ) || ! is_string( $url ) ) {
			return '';
		}

		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * Return a filtered public archive URL for one defined season.
	 *
	 * The stable season key remains the private article relationship value. Once
	 * a public ID has been confirmed, links use that numeric identity so renames
	 * and translated labels never change the URL.
	 */
	public static function public_season_archive_url( $term, string $season_key ): string {
		$season_key = sanitize_key( $season_key );
		if ( ! $term instanceof \WP_Term || '' === $season_key || ! self::is_seasoned( $term ) || ! self::season( $term, $season_key ) ) {
			return '';
		}

		$url = self::public_archive_url( $term );
		if ( '' === $url ) {
			return '';
		}

		$season = self::season( $term, $season_key );
		$token  = is_array( $season ) && 0 < (int) ( $season['public_id'] ?? 0 )
			? (string) (int) $season['public_id']
			: $season_key;
		return esc_url_raw( add_query_arg( Schema::QUERY_VAR_SEASON, $token, $url ), array( 'http', 'https' ) );
	}

	/** Return the canonical numeric URL for an old key request, when available. */
	public static function canonical_season_url( \WP_Term $term, string $token ): string {
		$resolved = Sequence::resolve_season_token( $term, $token );
		if ( ! is_array( $resolved ) || empty( $resolved['legacy'] ) || empty( $resolved['season']['public_id'] ) ) {
			return '';
		}
		return self::public_season_archive_url( $term, (string) $resolved['season']['key'] );
	}

	/** Redirect safe legacy key URLs to their stable numeric canonical form. */
	public static function redirect_legacy_season_url(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		$taxonomy = self::$claimed_taxonomy;
		$term     = '' !== $taxonomy && is_tax( $taxonomy ) ? get_queried_object() : null;
		$token    = get_query_var( Schema::QUERY_VAR_SEASON, '' );
		if ( ! $term instanceof \WP_Term || ! is_scalar( $token ) ) {
			return;
		}
		$url = self::canonical_season_url( $term, (string) $token );
		if ( '' !== $url ) {
			$raw_group_token = get_query_var( ContentGroups::QUERY_VAR, '' );
			$group_token = is_scalar( $raw_group_token ) ? (string) $raw_group_token : '';
			if ( '' !== $group_token ) { $url = add_query_arg( ContentGroups::QUERY_VAR, $group_token, $url ); }
			$paged = max( 0, (int) get_query_var( 'paged', 0 ) );
			if ( 1 < $paged ) {
				$url = add_query_arg( 'paged', $paged, $url );
			}
			wp_safe_redirect( $url, 301, 'WP Title Layer' );
			exit;
		}
	}

	/** @param int|\WP_Term $term */
	public static function mode( $term ): string {
		$term_id = self::term_id( $term );
		return $term_id ? Meta::sanitize_mode( get_term_meta( $term_id, Schema::TERM_META_SERIES_MODE, true ) ) : Schema::MODE_UNORDERED;
	}

	/** @param int|\WP_Term $term */
	public static function status( $term ): string {
		$term_id = self::term_id( $term );
		return $term_id ? Meta::sanitize_status( get_term_meta( $term_id, Schema::TERM_META_SERIES_STATUS, true ) ) : '';
	}

	/** @return array<string,string> */
	public static function status_labels(): array {
		return [
			Schema::STATUS_PLANNING  => __( 'Planning', 'wp-title-layer' ),
			Schema::STATUS_ONGOING   => __( 'Ongoing', 'wp-title-layer' ),
			Schema::STATUS_PAUSED    => __( 'Paused', 'wp-title-layer' ),
			Schema::STATUS_COMPLETED => __( 'Completed', 'wp-title-layer' ),
		];
	}

	/** @param int|\WP_Term $term */
	public static function status_label( $term ): string {
		$status = self::status( $term );
		$labels = self::status_labels();
		return $labels[ $status ] ?? '';
	}

	/** @param int|\WP_Term $term */
	public static function structure( $term ): string {
		$term_id = self::term_id( $term );
		return $term_id ? Meta::sanitize_structure( get_term_meta( $term_id, Schema::TERM_META_SERIES_STRUCTURE, true ) ) : Schema::STRUCTURE_FLAT;
	}

	/** @param int|\WP_Term $term */
	public static function navigation_scope( $term ): string {
		$term_id = self::term_id( $term );
		$scope   = $term_id
			? Meta::sanitize_navigation_scope( get_term_meta( $term_id, Schema::TERM_META_NAVIGATION_SCOPE, true ) )
			: Schema::NAVIGATION_SCOPE_SERIES;

		return Schema::NAVIGATION_SCOPE_SEASON === $scope && self::is_seasoned( $term )
			? Schema::NAVIGATION_SCOPE_SEASON
			: Schema::NAVIGATION_SCOPE_SERIES;
	}

	/** @param int|\WP_Term $term */
	public static function archive_sort( $term ): string {
		$term_id = self::term_id( $term );
		return $term_id ? Meta::sanitize_archive_sort( get_term_meta( $term_id, Schema::TERM_META_ARCHIVE_SORT, true ) ) : Schema::ARCHIVE_SORT_DATE_DESC;
	}

	/** @param int|\WP_Term $term */
	public static function parent_category_id( $term ): int {
		$term_id = self::term_id( $term );
		if ( ! $term_id ) {
			return 0;
		}

		$category_id = absint( get_term_meta( $term_id, Schema::TERM_META_PARENT_CATEGORY_ID, true ) );
		$category    = 0 < $category_id ? get_term( $category_id, 'category' ) : null;
		return $category instanceof \WP_Term && 'category' === $category->taxonomy ? $category_id : 0;
	}

	/** @param int|\WP_Term $term */
	public static function parent_category( $term ): ?\WP_Term {
		$category_id = self::parent_category_id( $term );
		$category    = 0 < $category_id ? get_term( $category_id, 'category' ) : null;
		return $category instanceof \WP_Term && 'category' === $category->taxonomy ? $category : null;
	}

	/** @param int|\WP_Term $term */
	public static function archive_layout( $term ): string {
		$term_id = self::term_id( $term );
		return $term_id
			? Meta::sanitize_archive_layout( get_term_meta( $term_id, Schema::TERM_META_ARCHIVE_LAYOUT, true ) )
			: Schema::ARCHIVE_LAYOUT_INHERIT;
	}

	/** @param int|\WP_Term $term */
	public static function archive_subtitles( $term ): string {
		$term_id = self::term_id( $term );
		return $term_id
			? Meta::sanitize_archive_visibility( get_term_meta( $term_id, Schema::TERM_META_ARCHIVE_SUBTITLES, true ) )
			: Schema::ARCHIVE_VISIBILITY_INHERIT;
	}

	/** @param int|\WP_Term $term */
	public static function archive_excerpts( $term ): string {
		$term_id = self::term_id( $term );
		return $term_id
			? Meta::sanitize_archive_visibility( get_term_meta( $term_id, Schema::TERM_META_ARCHIVE_EXCERPTS, true ) )
			: Schema::ARCHIVE_VISIBILITY_INHERIT;
	}

	/** @param int|\WP_Term $term */
	public static function archive_featured_images( $term ): string {
		$term_id = self::term_id( $term );
		return $term_id
			? Meta::sanitize_archive_visibility( get_term_meta( $term_id, Schema::TERM_META_ARCHIVE_FEATURED_IMAGES, true ) )
			: Schema::ARCHIVE_VISIBILITY_INHERIT;
	}

	/** @param int|\WP_Term $term */
	public static function title_icon( $term ): string {
		$term_id = self::term_id( $term );
		return $term_id
			? Meta::sanitize_archive_visibility( get_term_meta( $term_id, Schema::TERM_META_TITLE_ICON, true ) )
			: Schema::ARCHIVE_VISIBILITY_INHERIT;
	}

	/** @param int|\WP_Term $term */
	public static function icon_id( $term ): int {
		$term_id       = self::term_id( $term );
		$attachment_id = $term_id ? absint( get_term_meta( $term_id, Schema::TERM_META_ICON_ID, true ) ) : 0;
		return 0 < $attachment_id && 'attachment' === get_post_type( $attachment_id ) && wp_attachment_is_image( $attachment_id )
			? $attachment_id
			: 0;
	}

	/** @param int|\WP_Term $term */
	public static function variant( $term ): string {
		return self::mode( $term ) . '_' . self::structure( $term );
	}

	/** @param int|\WP_Term $term */
	public static function is_ordered( $term ): bool {
		return Schema::MODE_ORDERED === self::mode( $term );
	}

	/** @param int|\WP_Term $term */
	public static function is_seasoned( $term ): bool {
		return Schema::STRUCTURE_SEASONED === self::structure( $term );
	}

	/**
	 * Return canonical fields relevant to one of the four Series modes.
	 *
	 * @param int|\WP_Term $term Series term.
	 * @return string[]
	 */
	public static function structural_fields( $term ): array {
		$fields = [];
		if ( self::is_seasoned( $term ) ) {
			$fields[] = Schema::META_SEASON_KEY;
		}
		$fields[] = Schema::META_SERIES_ROLE;
		$fields[] = Schema::META_SERIES_SCOPE;
		$fields[] = Schema::META_SEQUENCE_LABEL;
		if ( self::is_ordered( $term ) ) {
			if ( ! Sequence::is_managed( $term ) ) {
				$fields[] = Schema::META_SEQUENCE_POSITION;
			}
		}
		return $fields;
	}

	/** @param int|\WP_Term $term */
	public static function seasons( $term ): array {
		$term_id = self::term_id( $term );
		return $term_id ? Meta::sanitize_seasons( get_term_meta( $term_id, Schema::TERM_META_SEASONS, true ) ) : [];
	}

	/** @return array{key:string,label:string,sort:int,public_id?:int}|null */
	public static function season( $term, string $season_key ): ?array {
		$season_key = sanitize_key( $season_key );
		foreach ( self::seasons( $term ) as $season ) {
			if ( $season_key === $season['key'] ) {
				return $season;
			}
		}

		return null;
	}

	/** @param string[] $query_vars */
	public static function register_public_query_vars( array $query_vars ): array {
		if ( ! in_array( Schema::QUERY_VAR_SEASON, $query_vars, true ) ) {
			$query_vars[] = Schema::QUERY_VAR_SEASON;
		}
		return $query_vars;
	}

	/**
	 * Compute a stable, comparable key without pretending unordered Series have
	 * a reading sequence.
	 *
	 * @return array{mode:string,structure:string,season_rank:int,position:int,archive_sort:string,tie_breaker:int,managed:bool}
	 */
	public static function sort_key( int $post_id, int $term_id = 0 ): array {
		if ( ! $term_id ) {
			$term = self::get_primary_term( $post_id );
			$term_id = $term ? (int) $term->term_id : 0;
		}

		$mode      = self::mode( $term_id );
		$structure = self::structure( $term_id );
		$sequence_key = Sequence::sort_key( $post_id, $term_id );

		return [
			'mode'          => $mode,
			'structure'     => $structure,
			'season_rank'   => (int) $sequence_key['season_rank'],
			'position'      => (int) $sequence_key['rank'],
			'archive_sort'  => self::archive_sort( $term_id ),
			'tie_breaker'   => $post_id,
			'managed'       => (bool) $sequence_key['managed'],
		];
	}

	public static function season_rank( int $term_id, string $season_key ): int {
		foreach ( self::seasons( $term_id ) as $index => $season ) {
			if ( $season['key'] === $season_key ) {
				return (int) $season['sort'];
			}
		}
		return PHP_INT_MAX;
	}

	/**
	 * Programmatic callers can use this before assigning terms.
	 *
	 * @param int[] $term_ids Series term IDs.
	 * @return true|\WP_Error
	 */
	public static function validate_single( array $term_ids ) {
		$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
		if ( 1 < count( $term_ids ) ) {
			return new \WP_Error( 'wptl_multiple_series', __( 'A post can belong to only one primary Series.', 'wp-title-layer' ) );
		}
		return true;
	}

	public static function enforce_single_series( int $object_id, $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		if ( self::$enforcing_single || ! self::is_claimed_taxonomy( $taxonomy ) ) {
			return;
		}

		$term_ids = wp_get_object_terms( $object_id, $taxonomy, [ 'fields' => 'ids', 'orderby' => 'term_id', 'order' => 'ASC' ] );
		if ( is_wp_error( $term_ids ) || 1 >= count( $term_ids ) ) {
			return;
		}

		$keep = (int) reset( $term_ids );
		do_action( 'wptl_multiple_series_corrected', $object_id, $term_ids, $keep );
		self::$enforcing_single = true;
		wp_set_object_terms( $object_id, [ $keep ], $taxonomy, false );
		self::$enforcing_single = false;
	}

	public static function prepare_archive_query( \WP_Query $query ): void {
		$taxonomy = self::$claimed_taxonomy;
		if ( '' === $taxonomy || is_admin() || ! $query->is_main_query() || ! $query->is_tax( $taxonomy ) ) {
			return;
		}

		$term = $query->get_queried_object();
		if ( ! $term instanceof \WP_Term || $taxonomy !== $term->taxonomy ) {
			return;
		}

		$mode           = self::mode( $term );
		$structure      = self::structure( $term );
		$sort           = self::archive_sort( $term );
		$book_structure = BookStructure::is_enabled( $term );
		$season_token   = self::is_seasoned( $term ) ? trim( (string) $query->get( Schema::QUERY_VAR_SEASON ) ) : '';
		$resolved       = '' !== $season_token ? Sequence::resolve_season_token( $term, $season_token ) : null;
		$season_key     = is_array( $resolved ) ? (string) $resolved['season']['key'] : '';
		if ( '' !== $season_token && '' === $season_key ) {
			$season_key = '';
			$query->set( Schema::QUERY_VAR_SEASON, '' );
		}
		$query->set( Schema::QUERY_VAR_RESOLVED_SEASON, $season_key );

		if ( Schema::MODE_UNORDERED === $mode && Schema::STRUCTURE_FLAT === $structure && ! $book_structure ) {
			if ( Schema::ARCHIVE_SORT_TITLE === $sort ) {
				$query->set( 'orderby', 'title' );
				$query->set( 'order', 'ASC' );
			} else {
				$query->set( 'orderby', 'date' );
				$query->set( 'order', Schema::ARCHIVE_SORT_DATE_ASC === $sort ? 'ASC' : 'DESC' );
			}
			return;
		}

		$query->set(
			'wptl_series_ordering',
			[
				'term_id'        => (int) $term->term_id,
				'mode'           => $mode,
				'structure'      => $structure,
				'archive_sort'   => $sort,
				'season_key'     => $season_key,
				'book_structure' => $book_structure,
			]
		);
	}

	public static function order_archive_clauses( array $clauses, \WP_Query $query ): array {
		if ( '' === self::$claimed_taxonomy ) {
			return $clauses;
		}

		$config = $query->get( 'wptl_series_ordering' );
		if ( ! is_array( $config ) || empty( $config['term_id'] ) ) {
			return $clauses;
		}

		global $wpdb;
		$order = [];
		$season_key = sanitize_key( (string) ( $config['season_key'] ?? '' ) );
		if ( '' !== $season_key ) {
			$clauses['where'] .= $wpdb->prepare(
				" AND EXISTS (
					SELECT 1
					FROM {$wpdb->postmeta} AS wptl_season_filter_value
					INNER JOIN (
						SELECT post_id, MIN(meta_id) AS meta_id
						FROM {$wpdb->postmeta}
						WHERE meta_key = %s
						GROUP BY post_id
					) AS wptl_season_filter_first
						ON wptl_season_filter_first.meta_id = wptl_season_filter_value.meta_id
					WHERE wptl_season_filter_value.post_id = {$wpdb->posts}.ID
						AND BINARY wptl_season_filter_value.meta_value = BINARY %s
				)",
				Schema::META_SEASON_KEY,
				$season_key
			);
		}
		if ( ! empty( $config['book_structure'] ) ) {
			return self::order_book_structure_clauses( $clauses, $config, $season_key );
		}

		if ( Schema::STRUCTURE_SEASONED === $config['structure'] ) {
			$clauses['join'] .= $wpdb->prepare(
				" LEFT JOIN (
					SELECT wptl_season_value.post_id, wptl_season_value.meta_value
					FROM {$wpdb->postmeta} AS wptl_season_value
					INNER JOIN (
						SELECT post_id, MIN(meta_id) AS meta_id
						FROM {$wpdb->postmeta}
						WHERE meta_key = %s
						GROUP BY post_id
					) AS wptl_season_first ON wptl_season_first.meta_id = wptl_season_value.meta_id
				) AS wptl_season_pm ON {$wpdb->posts}.ID = wptl_season_pm.post_id",
				Schema::META_SEASON_KEY
			);
			$cases = [];
			foreach ( self::seasons( (int) $config['term_id'] ) as $season ) {
				$cases[] = $wpdb->prepare( 'WHEN %s THEN %d', $season['key'], (int) $season['sort'] );
			}
			$order[] = $cases
				? '(CASE wptl_season_pm.meta_value ' . implode( ' ', $cases ) . ' ELSE 2147483647 END) ASC'
				: 'wptl_season_pm.meta_value ASC';
		}

		if ( Schema::MODE_ORDERED === $config['mode'] ) {
			$managed_order = Sequence::is_managed( (int) $config['term_id'] );
			$rank_meta_key = $managed_order
				? Schema::META_SEQUENCE_RANK
				: Schema::META_SEQUENCE_POSITION;
			$clauses['join'] .= $wpdb->prepare(
				" LEFT JOIN (
					SELECT wptl_position_value.post_id, wptl_position_value.meta_value
					FROM {$wpdb->postmeta} AS wptl_position_value
					INNER JOIN (
						SELECT post_id, MIN(meta_id) AS meta_id
						FROM {$wpdb->postmeta}
						WHERE meta_key = %s
						GROUP BY post_id
					) AS wptl_position_first ON wptl_position_first.meta_id = wptl_position_value.meta_id
				) AS wptl_position_pm ON {$wpdb->posts}.ID = wptl_position_pm.post_id",
				$rank_meta_key
			);
			$order[] = $managed_order
				? "CASE WHEN wptl_position_pm.meta_value IS NULL OR wptl_position_pm.meta_value = '' OR wptl_position_pm.meta_value NOT REGEXP '^[1-9][0-9]*$' THEN 1 ELSE 0 END ASC"
				: "CASE WHEN wptl_position_pm.meta_value IS NULL OR wptl_position_pm.meta_value = '' THEN 1 ELSE 0 END ASC";
			$order[] = $managed_order
				? "CASE WHEN wptl_position_pm.meta_value REGEXP '^[1-9][0-9]*$' THEN CAST(wptl_position_pm.meta_value AS UNSIGNED) ELSE 0 END ASC"
				: 'CAST(wptl_position_pm.meta_value AS SIGNED) ASC';
		} elseif ( Schema::ARCHIVE_SORT_TITLE === $config['archive_sort'] ) {
			$order[] = "{$wpdb->posts}.post_title ASC";
		} else {
			$order[] = "{$wpdb->posts}.post_date " . ( Schema::ARCHIVE_SORT_DATE_ASC === $config['archive_sort'] ? 'ASC' : 'DESC' );
		}

		$order[]            = "{$wpdb->posts}.ID ASC";
		$clauses['orderby'] = implode( ', ', $order );
		return $clauses;
	}

	/** Apply scope/role track order while retaining the Series' body sort mode. */
	private static function order_book_structure_clauses( array $clauses, array $config, string $season_key ): array {
		global $wpdb;
		$clauses['join'] .= self::first_meta_join( 'wptl_book_role_pm', Schema::META_SERIES_ROLE );
		$clauses['join'] .= self::first_meta_join( 'wptl_book_scope_pm', Schema::META_SERIES_SCOPE );
		$clauses['join'] .= self::first_meta_join( 'wptl_book_season_pm', Schema::META_SEASON_KEY );
		$clauses['join'] .= self::first_meta_join( 'wptl_book_rank_pm', Schema::META_SEQUENCE_RANK );
		$role = "COALESCE(NULLIF(wptl_book_role_pm.meta_value, ''), 'article')";
		$scope = "COALESCE(wptl_book_scope_pm.meta_value, '')";
		$track_cases = array();
		if ( Schema::STRUCTURE_SEASONED === (string) $config['structure'] ) {
			$track_cases[] = "WHEN {$scope} = 'series' AND {$role} = 'intro' THEN 10";
			foreach ( self::seasons( (int) $config['term_id'] ) as $index => $season ) {
				$base = ( $index + 1 ) * 100;
				$key = (string) $season['key'];
				foreach ( array( Schema::ROLE_INTRO => 10, Schema::ROLE_ARTICLE => 20, Schema::ROLE_EPILOGUE => 30, Schema::ROLE_APPENDIX => 40 ) as $track_role => $offset ) {
					$track_cases[] = $wpdb->prepare(
						"WHEN BINARY wptl_book_season_pm.meta_value = BINARY %s AND {$role} = %s AND ( {$role} = 'article' OR {$scope} = 'season' ) THEN %d",
						$key,
						$track_role,
						$base + $offset
					);
				}
			}
			$track_cases[] = "WHEN {$scope} = 'series' AND {$role} = 'epilogue' THEN 1000000";
			$track_cases[] = "WHEN {$scope} = 'series' AND {$role} = 'appendix' THEN 1000010";
			if ( '' !== $season_key ) {
				$clauses['where'] .= " AND (wptl_book_scope_pm.meta_value IS NULL OR wptl_book_scope_pm.meta_value <> 'series')";
			}
		} else {
			$track_cases[] = "WHEN {$role} = 'intro' THEN 10";
			$track_cases[] = "WHEN {$role} = 'article' THEN 20";
			$track_cases[] = "WHEN {$role} = 'epilogue' THEN 30";
			$track_cases[] = "WHEN {$role} = 'appendix' THEN 40";
		}

		$order = array( '(CASE ' . implode( ' ', $track_cases ) . ' ELSE 2147483647 END) ASC' );
		$rank_valid = "wptl_book_rank_pm.meta_value REGEXP '^[1-9][0-9]*$'";
		if ( Schema::MODE_ORDERED === (string) $config['mode'] ) {
			$order[] = "CASE WHEN {$rank_valid} THEN 0 ELSE 1 END ASC";
			$order[] = "CASE WHEN {$rank_valid} THEN CAST(wptl_book_rank_pm.meta_value AS UNSIGNED) ELSE 0 END ASC";
		} else {
			$order[] = "CASE WHEN {$role} = 'article' OR {$rank_valid} THEN 0 ELSE 1 END ASC";
			$order[] = "CASE WHEN {$role} <> 'article' AND {$rank_valid} THEN CAST(wptl_book_rank_pm.meta_value AS UNSIGNED) ELSE 0 END ASC";
			if ( Schema::ARCHIVE_SORT_TITLE === (string) $config['archive_sort'] ) {
				$order[] = "CASE WHEN {$role} = 'article' THEN {$wpdb->posts}.post_title ELSE '' END ASC";
			} else {
				$direction = Schema::ARCHIVE_SORT_DATE_ASC === (string) $config['archive_sort'] ? 'ASC' : 'DESC';
				$order[] = "CASE WHEN {$role} = 'article' THEN {$wpdb->posts}.post_date ELSE '0000-00-00 00:00:00' END {$direction}";
			}
		}
		$order[] = "{$wpdb->posts}.ID ASC";
		$clauses['orderby'] = implode( ', ', $order );
		return $clauses;
	}

	private static function first_meta_join( string $alias, string $meta_key ): string {
		global $wpdb;
		$alias = preg_replace( '/[^A-Za-z0-9_]/', '', $alias );
		return $wpdb->prepare(
			" LEFT JOIN (
				SELECT {$alias}_value.post_id, {$alias}_value.meta_value
				FROM {$wpdb->postmeta} AS {$alias}_value
				INNER JOIN (
					SELECT post_id, MIN(meta_id) AS meta_id
					FROM {$wpdb->postmeta}
					WHERE meta_key = %s
					GROUP BY post_id
				) AS {$alias}_first ON {$alias}_first.meta_id = {$alias}_value.meta_id
			) AS {$alias} ON {$wpdb->posts}.ID = {$alias}.post_id",
			$meta_key
		);
	}

	public static function register_rest_validation_hooks(): void {
		if ( '' === self::$claimed_taxonomy ) {
			return;
		}
		if ( ! isset( self::$rest_hooks[ 'term:' . self::$claimed_taxonomy ] ) ) {
			add_filter( 'rest_pre_insert_' . self::$claimed_taxonomy, array( self::class, 'guard_rest_series_definition_meta' ), 10, 2 );
			self::$rest_hooks[ 'term:' . self::$claimed_taxonomy ] = true;
		}

		foreach ( get_post_types( [ 'show_in_rest' => true ], 'names' ) as $post_type ) {
			if ( isset( self::$rest_hooks[ $post_type ] ) ) {
				continue;
			}
			add_filter( "rest_pre_insert_{$post_type}", [ self::class, 'validate_rest_publish' ], 10, 2 );
			self::$rest_hooks[ $post_type ] = true;
		}
	}

	/** Keep stable Season identities owned by the verified Series form/service. */
	public static function guard_rest_series_definition_meta( $prepared_term, \WP_REST_Request $request ) {
		if ( is_wp_error( $prepared_term ) ) {
			return $prepared_term;
		}
		$meta = $request->get_param( 'meta' );
		if ( is_array( $meta ) && array_key_exists( Schema::TERM_META_SEASONS, $meta ) ) {
			return new \WP_Error(
				'wptl_rest_seasons_read_only',
				__( 'Season definitions are read-only in the REST API. Use the Series edit screen so stable public IDs can be preserved.', 'wp-title-layer' ),
				array( 'status' => 400 )
			);
		}
		return $prepared_term;
	}

	/**
	 * Validate the single-Series and ordered-Series contract before REST save.
	 *
	 * WordPress passes a stdClass-like prepared item here, not a WP_Post.
	 *
	 * @param object|\WP_Error  $prepared_post Prepared post data.
	 * @param \WP_REST_Request   $request REST request.
	 * @return object|\WP_Error
	 */
	public static function validate_rest_publish( $prepared_post, \WP_REST_Request $request ) {
		if ( '' === self::$claimed_taxonomy || is_wp_error( $prepared_post ) || ! is_object( $prepared_post ) ) {
			return $prepared_post;
		}

		$post_id       = absint( $request->get_param( 'id' ) );
		$existing_post = $post_id ? get_post( $post_id ) : null;
		$meta = $request->get_param( 'meta' );
		$meta = is_array( $meta ) ? $meta : [];
		$role_requested = array_key_exists( Schema::META_SERIES_ROLE, $meta );
		$requested_role = $role_requested && is_scalar( $meta[ Schema::META_SERIES_ROLE ] )
			? trim( (string) $meta[ Schema::META_SERIES_ROLE ] )
			: '';
		$sanitized_requested_role = $role_requested
			? Meta::sanitize_role( $requested_role )
			: '';
		if ( $role_requested && ( ! is_scalar( $meta[ Schema::META_SERIES_ROLE ] ) || ( '' !== $requested_role && '' === $sanitized_requested_role ) ) ) {
			return new \WP_Error( 'wptl_invalid_series_role', __( 'Series role is not recognized', 'wp-title-layer' ), [ 'status' => 400 ] );
		}
		$prepared_status = isset( $prepared_post->post_status ) ? (string) $prepared_post->post_status : '';
		$status = (string) ( $request->get_param( 'status' ) ?: ( $prepared_status ?: ( $existing_post ? $existing_post->post_status : 'draft' ) ) );
		if ( ! in_array( $status, [ 'publish', 'future', 'private' ], true ) ) {
			return $prepared_post;
		}

		$taxonomy = self::$claimed_taxonomy;
		$tax_obj  = get_taxonomy( $taxonomy );
		if ( ! $tax_obj instanceof \WP_Taxonomy ) {
			return $prepared_post;
		}
		$rest_key = $tax_obj && ! empty( $tax_obj->rest_base ) ? $tax_obj->rest_base : $taxonomy;
		$requested_terms = $request->get_param( $rest_key );

		if ( null === $requested_terms ) {
			$term_ids = $post_id ? wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] ) : [];
			$term_ids = is_wp_error( $term_ids ) ? [] : $term_ids;
		} else {
			$term_ids = is_array( $requested_terms ) ? $requested_terms : [ $requested_terms ];
		}
		$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );

		$single = self::validate_single( $term_ids );
		if ( is_wp_error( $single ) ) {
			$single->add_data( [ 'status' => 400 ] );
			return $single;
		}
		if ( empty( $term_ids ) ) {
			return $prepared_post;
		}

		$term = get_term( $term_ids[0], $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return $prepared_post;
		}

		$season_key = array_key_exists( Schema::META_SEASON_KEY, $meta )
			? Meta::sanitize_key( $meta[ Schema::META_SEASON_KEY ] )
			: ( $post_id ? (string) get_post_meta( $post_id, Schema::META_SEASON_KEY, true ) : '' );
		$role = $role_requested
			? $sanitized_requested_role
			: ( $post_id ? Meta::sanitize_role( get_post_meta( $post_id, Schema::META_SERIES_ROLE, true ) ) : '' );
		$role = '' === $role ? Schema::ROLE_ARTICLE : $role;
		$scope = array_key_exists( Schema::META_SERIES_SCOPE, $meta )
			? Meta::sanitize_content_scope( $meta[ Schema::META_SERIES_SCOPE ] )
			: ( $post_id ? Meta::sanitize_content_scope( get_post_meta( $post_id, Schema::META_SERIES_SCOPE, true ) ) : '' );

		if ( self::is_seasoned( $term ) ) {
			if ( BookStructure::is_enabled( $term ) && Schema::ROLE_ARTICLE !== $role && '' === $scope ) {
				return new \WP_Error( 'wptl_missing_series_scope', __( 'Choose whether this non-main entry belongs to the entire Series or one season.', 'wp-title-layer' ), [ 'status' => 400 ] );
			}
			$series_level = Schema::ROLE_ARTICLE !== $role && Schema::SCOPE_SERIES === $scope;
			if ( Schema::ROLE_ARTICLE === $role && Schema::SCOPE_SERIES === $scope ) {
				return new \WP_Error( 'wptl_main_article_series_scope', __( 'A main article in a seasoned Series must belong to one season.', 'wp-title-layer' ), [ 'status' => 400 ] );
			}
			if ( ! $series_level ) {
				if ( '' === $season_key ) {
					return new \WP_Error( 'wptl_missing_season', __( 'This Series entry requires a season unless its non-main role is explicitly Series-wide.', 'wp-title-layer' ), [ 'status' => 400 ] );
				}
				$valid_keys = wp_list_pluck( self::seasons( $term ), 'key' );
				if ( empty( $valid_keys ) ) {
					return new \WP_Error( 'wptl_undefined_seasons', __( 'This seasoned Series has no seasons defined.', 'wp-title-layer' ), [ 'status' => 400 ] );
				}
				if ( ! in_array( $season_key, $valid_keys, true ) ) {
					return new \WP_Error( 'wptl_invalid_season', __( 'The selected season is not defined for this Series.', 'wp-title-layer' ), [ 'status' => 400 ] );
				}
			}
		} elseif ( Schema::SCOPE_SEASON === $scope ) {
			return new \WP_Error( 'wptl_flat_series_season_scope', __( 'A flat Series has no season scope. Choose Entire Series or clear the scope.', 'wp-title-layer' ), [ 'status' => 400 ] );
		}

		if ( ! self::is_ordered( $term ) ) {
			return $prepared_post;
		}
		if ( Sequence::is_managed( $term ) ) {
			// Rank is private and appended after REST has saved taxonomy and season
			// relationships. Legacy position edits cannot alter managed order.
			return $prepared_post;
		}

		$position = array_key_exists( Schema::META_SEQUENCE_POSITION, $meta )
			? $meta[ Schema::META_SEQUENCE_POSITION ]
			: ( $post_id && metadata_exists( 'post', $post_id, Schema::META_SEQUENCE_POSITION )
				? get_post_meta( $post_id, Schema::META_SEQUENCE_POSITION, true )
				: null );

		if ( null === $position || '' === $position || ! is_numeric( $position ) || 0 > (int) $position ) {
			return new \WP_Error(
				'wptl_missing_sequence_position',
				__( 'Published posts in an ordered Series require a non-negative sequence position.', 'wp-title-layer' ),
				[ 'status' => 400 ]
			);
		}

		if ( self::position_exists( (int) $term->term_id, (int) $position, $season_key, $post_id, self::is_seasoned( $term ) ) ) {
			return new \WP_Error( 'wptl_duplicate_sequence_position', __( 'That sequence position is already used in this Series and season.', 'wp-title-layer' ), [ 'status' => 409 ] );
		}

		return $prepared_post;
	}

	private static function position_exists( int $term_id, int $position, string $season_key, int $exclude_post_id, bool $seasoned ): bool {
		$taxonomy_key = self::$claimed_taxonomy;
		if ( '' === $taxonomy_key ) {
			return false;
		}

		$meta_query = [
			[
				'key'     => Schema::META_SEQUENCE_POSITION,
				'value'   => $position,
				'compare' => '=',
				'type'    => 'NUMERIC',
			],
		];
		if ( $seasoned ) {
			$meta_query[] = [ 'key' => Schema::META_SEASON_KEY, 'value' => $season_key, 'compare' => '=' ];
		}

		$taxonomy = get_taxonomy( $taxonomy_key );
		$query = new \WP_Query(
			[
				'post_type'              => $taxonomy ? $taxonomy->object_type : [ 'post' ],
				'post_status'            => array_values( get_post_stati( [ 'internal' => false ], 'names' ) ),
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'post__not_in'           => $exclude_post_id ? [ $exclude_post_id ] : [],
				'tax_query'              => [ [ 'taxonomy' => $taxonomy_key, 'field' => 'term_id', 'terms' => [ $term_id ] ] ],
				'meta_query'             => $meta_query,
			]
		);
		return ! empty( $query->posts );
	}

	/** @param int|\WP_Term $term */
	private static function term_id( $term ): int {
		return $term instanceof \WP_Term ? (int) $term->term_id : absint( $term );
	}
}
