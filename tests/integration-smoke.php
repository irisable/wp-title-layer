<?php
/**
 * Framework-free WordPress integration smoke tests.
 *
 * The file is executed inside WordPress Playground by bin/test-playground.sh.
 * It deliberately exercises WordPress APIs and the real database instead of
 * replacing them with mocks.
 *
 * @package WPTitleLayer
 */

require_once '/wordpress/wp-load.php';

$wptl_failures = array();
$wptl_checks   = 0;

/**
 * Record one integration assertion.
 *
 * @param bool   $condition Whether the assertion passed.
 * @param string $message   Failure message.
 * @return void
 */
function wptl_test_assert( $condition, $message ) {
	global $wptl_checks, $wptl_failures;

	++$wptl_checks;

	if ( ! $condition ) {
		$wptl_failures[] = $message;
	}
}

/**
 * Compare two values and retain useful diagnostics without exposing content.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Failure message.
 * @return void
 */
function wptl_test_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		$message .= sprintf(
			' (expected %s, got %s)',
			var_export( $expected, true ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			var_export( $actual, true ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		);
	}

	wptl_test_assert( $expected === $actual, $message );
}

/**
 * Assert that a REST validation result is a specific WP_Error.
 *
 * @param string $expected_code Expected error code.
 * @param mixed  $actual        Validation result.
 * @param string $message       Failure message.
 * @return void
 */
function wptl_test_error_code( $expected_code, $actual, $message ) {
	$actual_code = is_wp_error( $actual ) ? $actual->get_error_code() : '';
	wptl_test_same( $expected_code, $actual_code, $message );
}

/**
 * Pass a real request through the registered REST pre-insert filter.
 *
 * @param array<string,mixed> $params Request parameters.
 * @return object|\WP_Error
 */
function wptl_test_validate_rest_post( $params ) {
	$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	$prepared = (object) array(
		'post_status' => isset( $params['status'] ) ? $params['status'] : 'publish',
	);

	return apply_filters( 'rest_pre_insert_post', $prepared, $request );
}

/**
 * Create a post fixture and record creation failures as test failures.
 *
 * @param string $title Post title.
 * @return int
 */
function wptl_test_create_post( $title ) {
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => $title,
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		wptl_test_assert( false, 'Could not create post fixture: ' . $post_id->get_error_message() );
		return 0;
	}

	return (int) $post_id;
}

/**
 * Create a term fixture and record creation failures as test failures.
 *
 * @param string $name     Term name.
 * @param string $taxonomy Taxonomy name.
 * @return int
 */
function wptl_test_create_term( $name, $taxonomy ) {
	$result = wp_insert_term( $name, $taxonomy );
	if ( is_wp_error( $result ) ) {
		wptl_test_assert( false, 'Could not create term fixture: ' . $result->get_error_message() );
		return 0;
	}

	return (int) $result['term_id'];
}

/**
 * Process migration batches until a terminal state is reached.
 *
 * @param \WPTitleLayer\Migration\Migrator $migrator Migrator instance.
 * @param array                              $run      Run state.
 * @return array|\WP_Error
 */
function wptl_test_finish_migration( $migrator, $run ) {
	for ( $attempt = 0; $attempt < 20 && is_array( $run ) && 'complete' !== $run['status']; ++$attempt ) {
		$run = $migrator->process_batch( $run['id'], 2 );
	}

	return $run;
}

/**
 * Process rollback batches until a terminal state is reached.
 *
 * @param \WPTitleLayer\Migration\Rollback $rollback Rollback instance.
 * @param array                              $run      Run state.
 * @return array|\WP_Error
 */
function wptl_test_finish_rollback( $rollback, $run ) {
	for ( $attempt = 0; $attempt < 20 && is_array( $run ) && 'rolled_back' !== $run['status']; ++$attempt ) {
		$run = $rollback->process_batch( $run['id'], 1 );
	}

	return $run;
}

/**
 * Return the class-like identifier for a registered hook callback.
 *
 * @param mixed $callback WordPress hook callback.
 * @return string
 */
function wptl_test_callback_class( $callback ) {
	if ( is_array( $callback ) && isset( $callback[0] ) ) {
		return is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
	}

	if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
		return (string) strstr( $callback, '::', true );
	}

	return '';
}

/**
 * Count callbacks owned by selected WP Title Layer modules.
 *
 * @param string[] $prefixes Fully qualified class-name prefixes.
 * @return int
 */
function wptl_test_module_hook_count( $prefixes ) {
	global $wp_filter;

	$count = 0;
	foreach ( (array) $wp_filter as $hook ) {
		if ( ! $hook instanceof WP_Hook ) {
			continue;
		}
		foreach ( (array) $hook->callbacks as $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				$class = wptl_test_callback_class( $callback['function'] ?? null );
				foreach ( $prefixes as $prefix ) {
					if ( 0 === strpos( $class, $prefix ) ) {
						++$count;
						break;
					}
				}
			}
		}
	}

	return $count;
}

/**
 * Remove selected module callbacks to emulate a fresh migration-only boot.
 *
 * This helper is used only at the end of the integration process, after all
 * normal-mode behavior has already been exercised.
 *
 * @param string[] $prefixes Fully qualified class-name prefixes.
 * @return void
 */
function wptl_test_remove_module_hooks( $prefixes ) {
	global $wp_filter;

	$removals = array();
	foreach ( (array) $wp_filter as $tag => $hook ) {
		if ( ! $hook instanceof WP_Hook ) {
			continue;
		}
		foreach ( (array) $hook->callbacks as $priority => $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				$class    = wptl_test_callback_class( $function );
				foreach ( $prefixes as $prefix ) {
					if ( 0 === strpos( $class, $prefix ) ) {
						$removals[] = array( $tag, $function, $priority );
						break;
					}
				}
			}
		}
	}

	foreach ( $removals as $removal ) {
		remove_filter( $removal[0], $removal[1], $removal[2] );
	}
}

/* Plugin boot and public model registration. */
wptl_test_assert( defined( 'WPTL_VERSION' ), 'The plugin entry file did not load.' );
wptl_test_assert( class_exists( 'WPTitleLayer\\Plugin' ), 'The plugin bootstrap class is unavailable.' );
wptl_test_assert( class_exists( 'WPTitleLayer\\Core\\Schema' ), 'The Core schema class is unavailable.' );
wptl_test_assert( class_exists( 'WPTitleLayer\\Presentation\\Renderer' ), 'The presentation renderer is unavailable.' );
wptl_test_assert( class_exists( 'WPTitleLayer\\Migration\\Migrator' ), 'The migration service is unavailable.' );
wptl_test_assert( class_exists( 'WPTitleLayer\\Migration\\AjaxController' ), 'The automatic migration endpoint is unavailable.' );
wptl_test_assert( taxonomy_exists( 'wptl_series' ), 'The Series taxonomy was not registered.' );
wptl_test_assert( is_object_in_taxonomy( 'post', 'wptl_series' ), 'Series is not attached to posts.' );
wptl_test_assert( function_exists( 'get_secondary_title' ), 'The legacy-compatible subtitle API is unavailable.' );
wptl_test_assert(
	is_callable( array( 'WPTitleLayer\\Migration\\AjaxController', 'process_batch' ) ),
	'The automatic migration AJAX callback is unavailable.'
);
wptl_test_same(
	"'=HYPERLINK(\"https://example.invalid\")",
	\WPTitleLayer\Migration\AdminPage::sanitize_csv_cell( '=HYPERLINK("https://example.invalid")' ),
	'The conflict CSV export did not neutralize spreadsheet formulas.'
);
wptl_test_same(
	"' +cmd|' /C calc'!A0",
	\WPTitleLayer\Migration\AdminPage::sanitize_csv_cell( " +cmd|' /C calc'!A0" ),
	'The conflict CSV export did not neutralize a whitespace-prefixed formula.'
);
wptl_test_same(
	"'@SUM(1,2)",
	\WPTitleLayer\Migration\AdminPage::sanitize_csv_cell( "\t@SUM(1,2)" ),
	'The conflict CSV export did not neutralize a control-prefixed formula.'
);
wptl_test_same(
	'line one line two',
	\WPTitleLayer\Migration\AdminPage::sanitize_csv_cell( "line one\r\nline two" ),
	'The conflict CSV export did not normalize embedded line breaks.'
);

/* Only a safely claimed Series taxonomy is promoted or constrained. */
wptl_test_same(
	PHP_INT_MAX,
	has_action( 'registered_taxonomy', array( \WPTitleLayer\Core\Series::class, 'preserve_rest_registration' ) ),
	'The late Series REST repair is not attached to same-key taxonomy registrations.'
);
wptl_test_same(
	'wptl_series',
	\WPTitleLayer\Core\Series::claimed_taxonomy(),
	'The plugin did not claim its exact public default Series taxonomy.'
);

/* Editor bootstrap uses the real taxonomy slug for the panel and REST base for post edits. */
$wptl_editor_assets_reflection = new ReflectionClass( \WPTitleLayer\Presentation\EditorAssets::class );
$wptl_editor_data_method       = $wptl_editor_assets_reflection->getMethod( 'editorData' );
$wptl_editor_data_method->setAccessible( true );
$wptl_default_editor_data = $wptl_editor_data_method->invoke( null );
wptl_test_same( 'wptl_series', $wptl_default_editor_data['schema']['taxonomy'] ?? '', 'Editor data lost the claimed Series taxonomy slug.' );
wptl_test_same( 'wptl_series', $wptl_default_editor_data['seriesAttribute'] ?? '', 'Editor data did not expose the Series REST post attribute.' );
wptl_test_same( 'taxonomy-panel-wptl_series', $wptl_default_editor_data['nativeSeriesPanel'] ?? '', 'The plugin-owned Series taxonomy did not identify its native Gutenberg panel.' );
wptl_test_assert( in_array( 'post', $wptl_default_editor_data['supportedPostTypes'] ?? array(), true ), 'The post editor was not eligible for the WPTL panel.' );
wptl_test_assert( in_array( 'post', $wptl_default_editor_data['seriesPostTypes'] ?? array(), true ), 'The post editor was not eligible for the Series control.' );

\WPTitleLayer\Presentation\EditorAssets::register();
$wptl_scripts       = wp_scripts();
$wptl_editor_script = $wptl_scripts->registered[ \WPTitleLayer\Presentation\EditorAssets::SCRIPT_HANDLE ] ?? null;
wptl_test_assert( $wptl_editor_script instanceof _WP_Dependency, 'The WPTL editor script was not registered in real WordPress.' );
wptl_test_assert( $wptl_editor_script && in_array( 'wp-data', (array) $wptl_editor_script->deps, true ), 'The editor panel guard did not declare the WordPress data-store dependency.' );
wptl_test_assert( $wptl_editor_script && in_array( 'wp-edit-post', (array) $wptl_editor_script->deps, true ), 'The WPTL replacement panel did not declare the editor UI dependency.' );
wptl_test_same( 'wp-title-layer', $wptl_editor_script ? (string) $wptl_editor_script->textdomain : '', 'The editor script was not bound to the plugin text domain.' );
wptl_test_same( WPTL_PATH . 'languages', $wptl_editor_script ? (string) $wptl_editor_script->translations_path : '', 'The editor script was not bound to the packaged language directory.' );
$wptl_editor_style = wp_styles()->registered[ \WPTitleLayer\Presentation\EditorAssets::STYLE_HANDLE ] ?? null;
$wptl_editor_inline_styles = $wptl_editor_style instanceof _WP_Dependency ? (array) ( $wptl_editor_style->extra['after'] ?? array() ) : array();
wptl_test_assert( false !== strpos( implode( "\n", $wptl_editor_inline_styles ), '.taxonomy-panel-wptl_series{display:none!important;}' ), 'The plugin-owned native Series panel was not hidden before Gutenberg mounted it.' );
$wptl_core_editor_bundle = ABSPATH . WPINC . '/js/dist/editor.js';
$wptl_core_editor_source = is_readable( $wptl_core_editor_bundle ) ? file_get_contents( $wptl_core_editor_bundle ) : '';
wptl_test_assert( false !== strpos( (string) $wptl_core_editor_source, 'getCurrentPostType' ), 'This WordPress build does not expose getCurrentPostType in its editor data store.' );
wptl_test_assert( false !== strpos( (string) $wptl_core_editor_source, 'isEditorPanelRemoved' ), 'This WordPress build does not expose isEditorPanelRemoved in its editor data store.' );
wptl_test_assert( false !== strpos( (string) $wptl_core_editor_source, 'removeEditorPanel' ), 'This WordPress build does not expose removeEditorPanel in its editor data store.' );

$wptl_series_reflection = new ReflectionClass( \WPTitleLayer\Core\Series::class );
$wptl_series_relinquish = $wptl_series_reflection->getMethod( 'relinquish_taxonomy' );
$wptl_series_claim      = $wptl_series_reflection->getMethod( 'claim_taxonomy' );
$wptl_series_relinquish->setAccessible( true );
$wptl_series_claim->setAccessible( true );
$wptl_series_relinquish->invoke( null, 'wptl_series' );

$wptl_private_taxonomy   = 'wptl_private_collision';
$wptl_hidden_taxonomy    = 'wptl_hidden_collision';
$wptl_unclaimed_taxonomy = 'wptl_unclaimed_series';
$wptl_existing_taxonomy  = 'wptl_existing_series';
$wptl_unselected_taxonomy = 'wptl_unselected_series';
$wptl_claimable_taxonomies = array(
	$wptl_private_taxonomy,
	$wptl_hidden_taxonomy,
	$wptl_existing_taxonomy,
);
$wptl_existing_claim_filter = static function ( $claim, $object, $taxonomy, $object_types ) use ( $wptl_claimable_taxonomies ) {
	unset( $claim, $object, $object_types );
	return in_array( $taxonomy, $wptl_claimable_taxonomies, true );
};
add_filter( 'wptl_claim_existing_series_taxonomy', $wptl_existing_claim_filter, 10, 4 );

/* Even an explicit opt-in cannot expose or attach a private collision. */
register_taxonomy(
	$wptl_private_taxonomy,
	array( 'page' ),
	array(
		'public'       => false,
		'show_ui'      => true,
		'show_in_rest' => false,
	)
);
$wptl_private_taxonomy_filter = static function () use ( $wptl_private_taxonomy ) {
	return $wptl_private_taxonomy;
};
add_filter( 'wptl_series_taxonomy_key', $wptl_private_taxonomy_filter );
\WPTitleLayer\Core\Series::register_taxonomy();
\WPTitleLayer\Core\Meta::register();
remove_filter( 'wptl_series_taxonomy_key', $wptl_private_taxonomy_filter );
$wptl_private_taxonomy_object = get_taxonomy( $wptl_private_taxonomy );
wptl_test_same( '', \WPTitleLayer\Core\Series::claimed_taxonomy(), 'A private same-key taxonomy was claimed.' );
wptl_test_assert( $wptl_private_taxonomy_object && empty( $wptl_private_taxonomy_object->show_in_rest ), 'A private same-key taxonomy was exposed to REST.' );
wptl_test_assert(
	$wptl_private_taxonomy_object && ! in_array( 'post', (array) $wptl_private_taxonomy_object->object_type, true ),
	'A private same-key taxonomy was attached to posts.'
);
wptl_test_same(
	array(),
	get_registered_meta_keys( 'term', $wptl_private_taxonomy ),
	'A rejected private taxonomy received plugin-owned Series term metadata.'
);

$wptl_private_page_id = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'Private taxonomy collision fixture',
	)
);
$wptl_private_term_a = wptl_test_create_term( 'Private collision A', $wptl_private_taxonomy );
$wptl_private_term_b = wptl_test_create_term( 'Private collision B', $wptl_private_taxonomy );
wp_set_object_terms( $wptl_private_page_id, array( $wptl_private_term_a, $wptl_private_term_b ), $wptl_private_taxonomy );
$wptl_private_assigned = wp_get_object_terms( $wptl_private_page_id, $wptl_private_taxonomy, array( 'fields' => 'ids' ) );
wptl_test_same( 2, is_wp_error( $wptl_private_assigned ) ? 0 : count( $wptl_private_assigned ), 'The single-Series hook rewrote a rejected private taxonomy.' );
wptl_test_same( array(), \WPTitleLayer\Core\Series::get_terms( (int) $wptl_private_page_id ), 'Core queried terms from a rejected private taxonomy.' );
$wptl_private_prepared = (object) array( 'post_status' => 'publish' );
$wptl_private_validation_request = new WP_REST_Request( 'POST', '/wp/v2/pages' );
$wptl_private_validation_request->set_param( $wptl_private_taxonomy, array( $wptl_private_term_a, $wptl_private_term_b ) );
wptl_test_assert(
	$wptl_private_prepared === \WPTitleLayer\Core\Series::validate_rest_publish( $wptl_private_prepared, $wptl_private_validation_request ),
	'REST publication validation constrained a rejected private taxonomy.'
);
$wptl_private_archive_query = new WP_Query();
$wptl_private_archive_query->set(
	'wptl_series_ordering',
	array( 'term_id' => $wptl_private_term_a, 'mode' => 'ordered', 'structure' => 'flat', 'archive_sort' => 'date_desc' )
);
$wptl_private_clauses = array( 'join' => '', 'orderby' => 'unchanged' );
wptl_test_same(
	$wptl_private_clauses,
	\WPTitleLayer\Core\Series::order_archive_clauses( $wptl_private_clauses, $wptl_private_archive_query ),
	'Archive ordering changed a query while no Series taxonomy was claimed.'
);
$wptl_private_term_object = get_term( $wptl_private_term_a, $wptl_private_taxonomy );
update_term_meta( $wptl_private_term_a, 'wptl_series_mode', 'ordered' );
$wptl_private_navigation = new \WPTitleLayer\Reader\Navigation();
wptl_test_same(
	array(),
	$wptl_private_term_object instanceof WP_Term ? $wptl_private_navigation->orderedPostIds( $wptl_private_term_object ) : array( 1 ),
	'Reader navigation queried a rejected private taxonomy.'
);
$wptl_private_archive_context = $wptl_private_term_object instanceof WP_Term
	? ( new \WPTitleLayer\Reader\Archive() )->context( $wptl_private_term_object, new WP_Query() )
	: array( 'valid' => true );
wptl_test_assert( empty( $wptl_private_archive_context['valid'] ), 'Reader archive context accepted a rejected private taxonomy.' );
wptl_test_same(
	'Original private content',
	( new \WPTitleLayer\Reader\ContentAppender() )->append( 'Original private content' ),
	'Reader content integration ran while no Series taxonomy was claimed.'
);
wptl_test_same(
	null,
	( new \WPTitleLayer\Presentation\TemplateResolver() )->selectedSeries( (int) $wptl_private_page_id ),
	'Presentation resolved a Series from a rejected private taxonomy.'
);

/* A public taxonomy with no UI is also never claimable. */
register_taxonomy(
	$wptl_hidden_taxonomy,
	array( 'page' ),
	array(
		'public'       => true,
		'show_ui'      => false,
		'show_in_rest' => false,
	)
);
$wptl_hidden_taxonomy_filter = static function () use ( $wptl_hidden_taxonomy ) {
	return $wptl_hidden_taxonomy;
};
add_filter( 'wptl_series_taxonomy_key', $wptl_hidden_taxonomy_filter );
\WPTitleLayer\Core\Series::register_taxonomy();
remove_filter( 'wptl_series_taxonomy_key', $wptl_hidden_taxonomy_filter );
$wptl_hidden_taxonomy_object = get_taxonomy( $wptl_hidden_taxonomy );
wptl_test_same( '', \WPTitleLayer\Core\Series::claimed_taxonomy(), 'A UI-hidden same-key taxonomy was claimed.' );
wptl_test_assert( $wptl_hidden_taxonomy_object && empty( $wptl_hidden_taxonomy_object->show_in_rest ), 'A UI-hidden same-key taxonomy was exposed to REST.' );
wptl_test_assert(
	$wptl_hidden_taxonomy_object && ! in_array( 'post', (array) $wptl_hidden_taxonomy_object->object_type, true ),
	'A UI-hidden same-key taxonomy was attached to posts.'
);

/* A custom public collision requires an explicit opt-in. */
register_taxonomy(
	$wptl_unclaimed_taxonomy,
	array( 'page' ),
	array(
		'public'       => true,
		'show_ui'      => true,
		'show_in_rest' => false,
	)
);
$wptl_unclaimed_taxonomy_filter = static function () use ( $wptl_unclaimed_taxonomy ) {
	return $wptl_unclaimed_taxonomy;
};
add_filter( 'wptl_series_taxonomy_key', $wptl_unclaimed_taxonomy_filter );
\WPTitleLayer\Core\Series::register_taxonomy();
remove_filter( 'wptl_series_taxonomy_key', $wptl_unclaimed_taxonomy_filter );
$wptl_unclaimed_taxonomy_object = get_taxonomy( $wptl_unclaimed_taxonomy );
wptl_test_same( '', \WPTitleLayer\Core\Series::claimed_taxonomy(), 'A custom existing taxonomy was claimed without opt-in.' );
wptl_test_assert( $wptl_unclaimed_taxonomy_object && empty( $wptl_unclaimed_taxonomy_object->show_in_rest ), 'An unclaimed custom taxonomy was exposed to REST.' );
wptl_test_assert(
	$wptl_unclaimed_taxonomy_object && ! in_array( 'post', (array) $wptl_unclaimed_taxonomy_object->object_type, true ),
	'An unclaimed custom taxonomy was attached to posts.'
);

/* An explicitly claimed public taxonomy keeps its model and becomes selectable. */
register_taxonomy(
	$wptl_existing_taxonomy,
	array( 'page' ),
	array(
		'public'                => true,
		'show_ui'               => true,
		'show_in_rest'          => false,
		'rest_base'             => 'wptl-existing-series-rest',
		'rest_namespace'        => 'wp/v2',
		'rest_controller_class' => 'WP_REST_Terms_Controller',
		'capabilities'          => array(
			'manage_terms' => 'manage_categories',
			'edit_terms'   => 'manage_categories',
			'delete_terms' => 'manage_categories',
			'assign_terms' => 'edit_posts',
		),
	)
);
$wptl_existing_taxonomy_before = get_taxonomy( $wptl_existing_taxonomy );
$wptl_existing_caps_before = $wptl_existing_taxonomy_before ? get_object_vars( $wptl_existing_taxonomy_before->cap ) : array();
$wptl_existing_taxonomy_filter = static function () use ( $wptl_existing_taxonomy ) {
	return $wptl_existing_taxonomy;
};
add_filter( 'wptl_series_taxonomy_key', $wptl_existing_taxonomy_filter );
\WPTitleLayer\Core\Series::register_taxonomy();
\WPTitleLayer\Core\Meta::register();
$wptl_existing_taxonomy_object = get_taxonomy( $wptl_existing_taxonomy );
wptl_test_same( $wptl_existing_taxonomy, \WPTitleLayer\Core\Series::claimed_taxonomy(), 'An explicitly opted-in public Series taxonomy was not claimed.' );
wptl_test_assert( $wptl_existing_taxonomy_object && ! empty( $wptl_existing_taxonomy_object->show_in_rest ), 'An explicitly claimed public Series taxonomy remained invisible to Gutenberg.' );
wptl_test_same( 'wptl-existing-series-rest', (string) ( $wptl_existing_taxonomy_object->rest_base ?? '' ), 'Claiming a public taxonomy replaced its REST base.' );
wptl_test_same( 'wp/v2', (string) ( $wptl_existing_taxonomy_object->rest_namespace ?? '' ), 'Claiming a public taxonomy replaced its REST namespace.' );
wptl_test_same( 'WP_REST_Terms_Controller', (string) ( $wptl_existing_taxonomy_object->rest_controller_class ?? '' ), 'Claiming a public taxonomy replaced its REST controller.' );
wptl_test_same( $wptl_existing_caps_before, get_object_vars( $wptl_existing_taxonomy_object->cap ), 'Claiming a public taxonomy replaced its capabilities.' );
wptl_test_assert( in_array( 'page', (array) $wptl_existing_taxonomy_object->object_type, true ), 'Claiming a public taxonomy discarded its original object type.' );
wptl_test_assert( in_array( 'post', (array) $wptl_existing_taxonomy_object->object_type, true ), 'Claiming a public taxonomy did not append the configured post type.' );
wptl_test_assert(
	isset( get_registered_meta_keys( 'term', $wptl_existing_taxonomy )['wptl_series_mode'] ),
	'An explicitly claimed taxonomy did not receive Series term metadata.'
);
$wptl_reused_editor_data = $wptl_editor_data_method->invoke( null );
wptl_test_same( $wptl_existing_taxonomy, $wptl_reused_editor_data['schema']['taxonomy'] ?? '', 'A reused taxonomy lost its registered slug in editor data.' );
wptl_test_same( 'wptl-existing-series-rest', $wptl_reused_editor_data['seriesAttribute'] ?? '', 'The editor would write a reused taxonomy to its slug instead of its REST post attribute.' );
wptl_test_same( '', $wptl_reused_editor_data['nativeSeriesPanel'] ?? '', 'WPTL hid a reused taxonomy panel without explicit opt-in.' );
$wptl_hide_reused_panel = static function () {
	return true;
};
add_filter( 'wptl_hide_native_series_panel', $wptl_hide_reused_panel );
$wptl_opted_in_editor_data = $wptl_editor_data_method->invoke( null );
remove_filter( 'wptl_hide_native_series_panel', $wptl_hide_reused_panel );
wptl_test_same(
	'taxonomy-panel-' . $wptl_existing_taxonomy,
	$wptl_opted_in_editor_data['nativeSeriesPanel'] ?? '',
	'An explicitly opted-in reused taxonomy did not use its taxonomy slug for the native panel name.'
);

/* A later ACF/theme registration of the same key cannot hide it again. */
register_taxonomy(
	$wptl_existing_taxonomy,
	array( 'page' ),
	array(
		'public'                => true,
		'show_ui'               => true,
		'show_in_rest'          => false,
		'rest_base'             => 'wptl-existing-series-rest',
		'rest_namespace'        => 'wp/v2',
		'rest_controller_class' => 'WP_REST_Terms_Controller',
		'capabilities'          => array(
			'manage_terms' => 'manage_categories',
			'edit_terms'   => 'manage_categories',
			'delete_terms' => 'manage_categories',
			'assign_terms' => 'edit_posts',
		),
	)
);
remove_filter( 'wptl_series_taxonomy_key', $wptl_existing_taxonomy_filter );
$wptl_unselected_taxonomy_before = register_taxonomy(
	$wptl_unselected_taxonomy,
	array( 'page' ),
	array(
		'public'       => true,
		'show_ui'      => true,
		'show_in_rest' => false,
	)
);
$wptl_existing_taxonomy_object = get_taxonomy( $wptl_existing_taxonomy );
wptl_test_assert(
	$wptl_existing_taxonomy_object && ! empty( $wptl_existing_taxonomy_object->show_in_rest ),
	'A later same-key taxonomy registration hid Series from Gutenberg.'
);
wptl_test_assert(
	in_array( 'post', (array) $wptl_existing_taxonomy_object->object_type, true ),
	'The late Series REST repair did not preserve the post relationship.'
);
wptl_test_assert(
	$wptl_unselected_taxonomy_before instanceof WP_Taxonomy && empty( $wptl_unselected_taxonomy_before->show_in_rest ),
	'Registering an unselected taxonomy exposed it to REST.'
);
wptl_test_assert(
	$wptl_unselected_taxonomy_before instanceof WP_Taxonomy && ! in_array( 'post', (array) $wptl_unselected_taxonomy_before->object_type, true ),
	'Registering an unselected taxonomy attached it to posts.'
);

$wptl_existing_term_id = wptl_test_create_term( 'Existing REST Series', $wptl_existing_taxonomy );
$wptl_existing_request = new WP_REST_Request( 'GET', '/wp/v2/wptl-existing-series-rest' );
$wptl_existing_request->set_param( 'context', 'view' );
$wptl_existing_request->set_param( 'hide_empty', false );
$wptl_existing_response = rest_do_request( $wptl_existing_request );
wptl_test_same( 200, $wptl_existing_response->get_status(), 'The reused Series taxonomy did not register its terms route.' );
wptl_test_assert(
	in_array( $wptl_existing_term_id, array_map( 'absint', wp_list_pluck( (array) $wptl_existing_response->get_data(), 'id' ) ), true ),
	'The reused Series terms route omitted an unassigned Series.'
);

/* Rejected and unselected taxonomies never gain a REST route. */
$wptl_collision_user = get_current_user_id();
wp_set_current_user( 0 );
foreach ( array( $wptl_private_taxonomy, $wptl_hidden_taxonomy, $wptl_unclaimed_taxonomy, $wptl_unselected_taxonomy ) as $wptl_closed_taxonomy ) {
	$wptl_closed_response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . $wptl_closed_taxonomy ) );
	wptl_test_same( 404, $wptl_closed_response->get_status(), 'A rejected or unselected taxonomy gained a public REST route: ' . $wptl_closed_taxonomy );
}
wp_set_current_user( $wptl_collision_user );

/* Assign-only authors may select an existing Series but cannot create one. */
$wptl_claim_author_id = wp_insert_user(
	array(
		'user_login' => 'wptl_claim_author',
		'user_pass'  => wp_generate_password( 24, true, true ),
		'user_email' => 'wptl-claim-author@example.invalid',
		'role'       => 'author',
	)
);
wptl_test_assert( ! is_wp_error( $wptl_claim_author_id ), 'Could not create the claimed-taxonomy author fixture.' );
if ( ! is_wp_error( $wptl_claim_author_id ) ) {
	wp_set_current_user( (int) $wptl_claim_author_id );
	$wptl_claimed_object = get_taxonomy( $wptl_existing_taxonomy );
	wptl_test_assert( $wptl_claimed_object && current_user_can( $wptl_claimed_object->cap->assign_terms ), 'The claimed-taxonomy author cannot assign existing Series terms.' );
	wptl_test_assert( $wptl_claimed_object && ! current_user_can( $wptl_claimed_object->cap->edit_terms ), 'The claimed-taxonomy author unexpectedly edits Series definitions.' );
	$wptl_claim_author_get = rest_do_request( $wptl_existing_request );
	wptl_test_same( 200, $wptl_claim_author_get->get_status(), 'An assign-only author could not load an explicitly claimed public Series.' );
	$wptl_claim_author_post = new WP_REST_Request( 'POST', '/wp/v2/wptl-existing-series-rest' );
	$wptl_claim_author_post->set_param( 'name', 'Forbidden author-created Series' );
	$wptl_claim_author_response = rest_do_request( $wptl_claim_author_post );
	wptl_test_same( 403, $wptl_claim_author_response->get_status(), 'An assign-only author could create a Series term through REST.' );
	wptl_test_assert( ! term_exists( 'Forbidden author-created Series', $wptl_existing_taxonomy ), 'The denied author write still created a Series term.' );
	wp_set_current_user( $wptl_collision_user );
}

$wptl_series_relinquish->invoke( null, $wptl_existing_taxonomy );
$wptl_series_claim->invoke( null, 'wptl_series' );
wptl_test_same( 'wptl_series', \WPTitleLayer\Core\Series::claimed_taxonomy(), 'The default Series claim was not restored after collision fixtures.' );

wp_delete_post( (int) $wptl_private_page_id, true );
wp_delete_term( $wptl_private_term_a, $wptl_private_taxonomy );
wp_delete_term( $wptl_private_term_b, $wptl_private_taxonomy );
wp_delete_term( $wptl_existing_term_id, $wptl_existing_taxonomy );
unregister_taxonomy( $wptl_existing_taxonomy );
unregister_taxonomy( $wptl_private_taxonomy );
unregister_taxonomy( $wptl_hidden_taxonomy );
unregister_taxonomy( $wptl_unclaimed_taxonomy );
unregister_taxonomy( $wptl_unselected_taxonomy );
remove_filter( 'wptl_claim_existing_series_taxonomy', $wptl_existing_claim_filter, 10 );

$wptl_series_taxonomy = get_taxonomy( 'wptl_series' );
wptl_test_assert(
	$wptl_series_taxonomy && ! empty( $wptl_series_taxonomy->show_in_rest ),
	'The Series taxonomy is not exposed to the block editor REST API.'
);

$wptl_post_meta = get_registered_meta_keys( 'post', '' );
foreach (
	array(
		'wptl_subtitle',
		'wptl_season_key',
		'wptl_sequence_position',
		'wptl_sequence_label',
		'wptl_series_role',
		'wptl_series_scope',
		'wptl_template_override',
		'wptl_kicker_override',
	) as $wptl_meta_key
) {
	wptl_test_assert( isset( $wptl_post_meta[ $wptl_meta_key ] ), 'Post meta was not registered: ' . $wptl_meta_key );
	wptl_test_assert(
		isset( $wptl_post_meta[ $wptl_meta_key ] ) && ! empty( $wptl_post_meta[ $wptl_meta_key ]['show_in_rest'] ),
		'Post meta is not available to the editor REST API: ' . $wptl_meta_key
	);
}

$wptl_term_meta = get_registered_meta_keys( 'term', 'wptl_series' );
foreach (
	array(
		'wptl_series_mode',
		'wptl_series_status',
		'wptl_series_structure',
		'wptl_navigation_scope',
		'wptl_archive_sort',
		'wptl_seasons',
		'wptl_default_template',
		'wptl_short_label',
		'wptl_cover_id',
		'wptl_parent_category_id',
		'wptl_archive_layout',
		'wptl_archive_subtitles',
		'wptl_archive_featured_images',
	) as $wptl_meta_key
) {
	wptl_test_assert( isset( $wptl_term_meta[ $wptl_meta_key ] ), 'Series term meta was not registered: ' . $wptl_meta_key );
}

ob_start();
\WPTitleLayer\Core\Series::render_add_term_fields();
$wptl_series_form = (string) ob_get_clean();
wptl_test_assert(
	false !== strpos( $wptl_series_form, 'name="wptl_term[wptl_default_template]"' ),
	'The native Series form did not render the default-template selector.'
);
wptl_test_assert(
	false !== strpos( $wptl_series_form, 'value="editorial"' ),
	'The Series template selector did not expose the named presentation presets.'
);
wptl_test_assert(
	false !== strpos( $wptl_series_form, 'name="wptl_term[wptl_series_status]"' )
	&& false !== strpos( $wptl_series_form, 'value="planning"' )
	&& false !== strpos( $wptl_series_form, 'value="ongoing"' )
	&& false !== strpos( $wptl_series_form, 'value="paused"' )
	&& false !== strpos( $wptl_series_form, 'value="completed"' ),
	'The native Series form did not expose the explicit lifecycle statuses.'
);
wptl_test_assert(
	false !== strpos( $wptl_series_form, 'Descriptive only' ),
	'The Series form did not explain that lifecycle status has no publishing side effects.'
);
wptl_test_assert(
	false !== strpos( $wptl_series_form, 'name="wptl_term[wptl_navigation_scope]"' )
	&& false !== strpos( $wptl_series_form, 'Entire Series' )
	&& false !== strpos( $wptl_series_form, 'Current Season' ),
	'The native Series form did not expose the reading-navigation boundary.'
);
wptl_test_same( 'series', \WPTitleLayer\Core\Meta::sanitize_navigation_scope( 'sideways' ), 'An invalid navigation scope did not fail back to the compatible full-Series default.' );
wptl_test_assert(
	false !== strpos( $wptl_series_form, 'Season name' ) && false === strpos( $wptl_series_form, '<textarea' ),
	'The native Series form did not provide the non-technical season rows.'
);
wptl_test_same( 4, substr_count( $wptl_series_form, 'class="wptl-season-row"' ), 'A new Series did not start with four available season rows.' );
wptl_test_assert( false !== strpos( $wptl_series_form, 'wptl-add-season-row' ), 'The new-Series form did not offer an add-season control.' );
wptl_test_assert(
	false !== strpos( $wptl_series_form, 'data-wptl-cover-control' )
	&& false !== strpos( $wptl_series_form, 'wptl-select-cover' )
	&& false !== strpos( $wptl_series_form, 'name="wptl_term[wptl_cover_id]"' ),
	'The Series form did not expose a progressive media-library cover control.'
);
wptl_test_assert(
	false !== strpos( $wptl_series_form, 'wptl_parent_category_id' )
	&& false !== strpos( $wptl_series_form, 'No parent Category' ),
	'The Series form did not expose its optional parent Category relationship.'
);
wptl_test_assert(
	false !== strpos( $wptl_series_form, 'name="wptl_term[wptl_archive_layout]"' )
	&& false !== strpos( $wptl_series_form, 'name="wptl_term[wptl_archive_subtitles]"' )
	&& false !== strpos( $wptl_series_form, 'name="wptl_term[wptl_archive_featured_images]"' ),
	'The Series form did not expose per-Series archive presentation overrides.'
);

$wptl_series_columns = apply_filters(
	'manage_edit-wptl_series_columns',
	array(
		'cb'   => 'Select',
		'name' => 'Name',
		'slug' => 'Slug',
	)
);
wptl_test_same( 'Status', $wptl_series_columns['wptl_series_status'] ?? '', 'The Series list did not register its lifecycle Status column.' );
$wptl_unset_status_term_id = wptl_test_create_term( 'WPTL Unset Lifecycle Series', 'wptl_series' );
wptl_test_same(
	'Not set',
	apply_filters( 'manage_wptl_series_custom_column', '', 'wptl_series_status', $wptl_unset_status_term_id ),
	'An existing Series without an explicit lifecycle status was inferred instead of remaining unset.'
);
wptl_test_same( '', \WPTitleLayer\Core\Meta::sanitize_status( 'retired' ), 'An unknown Series lifecycle status was accepted.' );
wp_delete_term( $wptl_unset_status_term_id, 'wptl_series' );

/* The post list exposes Subtitle as a normal Screen Options column. */
$wptl_post_columns = apply_filters(
	'manage_post_posts_columns',
	array(
		'cb'    => 'Select',
		'title' => 'Title',
		'date'  => 'Date',
	)
);
wptl_test_same( 'Subtitle', $wptl_post_columns['wptl_subtitle'] ?? '', 'The Posts list did not register its Subtitle column.' );
$wptl_column_keys = array_keys( $wptl_post_columns );
wptl_test_same( 2, array_search( 'wptl_subtitle', $wptl_column_keys, true ), 'The Subtitle column was not placed beside the primary title.' );
$wptl_column_post_id = wptl_test_create_post( 'Post-list subtitle fixture' );
update_post_meta( $wptl_column_post_id, 'wptl_subtitle', 'Visible list subtitle' );
ob_start();
do_action( 'manage_post_posts_custom_column', 'wptl_subtitle', $wptl_column_post_id );
$wptl_column_html = (string) ob_get_clean();
wptl_test_assert( false !== strpos( $wptl_column_html, 'Visible list subtitle' ), 'The Posts list Subtitle column did not render canonical data.' );
wp_delete_post( $wptl_column_post_id, true );

/* Registered metadata callbacks must reject users who cannot edit the object. */
$wptl_auth_post_id = wptl_test_create_post( 'Metadata authorization' );
$wptl_auth_term_id = wptl_test_create_term( 'Metadata authorization Series', 'wptl_series' );
$wptl_original_user_id = get_current_user_id();
$wptl_subscriber_id = wp_insert_user(
	array(
		'user_login' => 'wptl_integration_subscriber',
		'user_pass'  => wp_generate_password( 24, true, true ),
		'user_email' => 'wptl-integration-subscriber@example.invalid',
		'role'       => 'subscriber',
	)
);
wptl_test_assert( ! is_wp_error( $wptl_subscriber_id ), 'Could not create the restricted-user fixture.' );

if ( ! is_wp_error( $wptl_subscriber_id ) ) {
	wp_set_current_user( (int) $wptl_subscriber_id );
	$wptl_post_auth = $wptl_post_meta['wptl_subtitle']['auth_callback'];
	$wptl_term_auth = $wptl_term_meta['wptl_series_mode']['auth_callback'];
	wptl_test_assert(
		! call_user_func( $wptl_post_auth, null, 'wptl_subtitle', $wptl_auth_post_id ),
		'The registered post-meta callback allowed a user without edit_post permission.'
	);
	wptl_test_assert(
		! call_user_func( $wptl_term_auth, null, 'wptl_series_mode', $wptl_auth_term_id ),
		'The registered term-meta callback allowed a user without edit_term permission.'
	);
	wp_set_current_user( $wptl_original_user_id );
}

/* Native season rows save stable hidden keys while editors enter only labels. */
$wptl_admin_id = wp_insert_user(
	array(
		'user_login' => 'wptl_integration_admin',
		'user_pass'  => wp_generate_password( 24, true, true ),
		'user_email' => 'wptl-integration-admin@example.invalid',
		'role'       => 'administrator',
	)
);
wptl_test_assert( ! is_wp_error( $wptl_admin_id ), 'Could not create the administrator fixture.' );
if ( ! is_wp_error( $wptl_admin_id ) ) {
	wp_set_current_user( (int) $wptl_admin_id );
}

/* The Control Center is a real capability-gated top-level admin page. */
if ( ! function_exists( 'set_current_screen' ) || ! function_exists( 'add_menu_page' ) ) {
	require_once ABSPATH . 'wp-admin/includes/admin.php';
}
$wptl_control_old_screen = $GLOBALS['current_screen'] ?? null;
set_current_screen( 'dashboard' );
\WPTitleLayer\Admin\ControlCenter::register( false );
wptl_test_assert(
	false !== has_action( 'admin_menu', array( 'WPTitleLayer\\Admin\\ControlCenter', 'addPage' ) ),
	'The Control Center did not register its admin-menu callback in normal mode.'
);

if ( ! did_action( 'admin_menu' ) ) {
	do_action( 'admin_menu' );
} else {
	\WPTitleLayer\Admin\ControlCenter::addPage();
}

$wptl_control_menu_item = null;
foreach ( (array) ( $GLOBALS['menu'] ?? array() ) as $wptl_menu_item ) {
	if ( isset( $wptl_menu_item[2] ) && \WPTitleLayer\Admin\ControlCenter::PAGE_SLUG === $wptl_menu_item[2] ) {
		$wptl_control_menu_item = $wptl_menu_item;
		break;
	}
}
wptl_test_assert( is_array( $wptl_control_menu_item ), 'The Control Center top-level menu item was not created.' );
wptl_test_same( 'manage_options', $wptl_control_menu_item[1] ?? '', 'The Control Center menu used the wrong capability.' );
$wptl_control_icon = (string) ( $wptl_control_menu_item[6] ?? '' );
wptl_test_assert( 0 === strpos( $wptl_control_icon, 'data:image/svg+xml;base64,' ), 'The Control Center did not register its custom SVG menu icon.' );
$wptl_control_icon_svg = base64_decode( substr( $wptl_control_icon, strlen( 'data:image/svg+xml;base64,' ) ), true );
wptl_test_assert(
	is_string( $wptl_control_icon_svg )
	&& false !== strpos( $wptl_control_icon_svg, '<svg' )
	&& false !== strpos( $wptl_control_icon_svg, 'fill="#a7aaad"' )
	&& false === stripos( $wptl_control_icon_svg, '<script' ),
	'The Control Center menu icon was not a safe monochrome SVG.'
);
wptl_test_assert( current_user_can( 'manage_options' ), 'The administrator fixture could not access the Control Center.' );

$wptl_control_die_filter = static function () {
	return static function ( $message ) {
		throw new RuntimeException( wp_strip_all_tags( (string) $message ) );
	};
};
$wptl_control_blocked = false;
if ( ! is_wp_error( $wptl_subscriber_id ) ) {
	wp_set_current_user( (int) $wptl_subscriber_id );
	add_filter( 'wp_die_handler', $wptl_control_die_filter );
	try {
		\WPTitleLayer\Admin\ControlCenter::render();
	} catch ( RuntimeException $error ) {
		$wptl_control_blocked = false !== strpos( $error->getMessage(), 'not allowed' );
	}
	remove_filter( 'wp_die_handler', $wptl_control_die_filter );
	wp_set_current_user( (int) $wptl_admin_id );
}
wptl_test_assert( $wptl_control_blocked, 'A user without manage_options could render the Control Center.' );

wp_dequeue_style( 'wptl-control-center' );
wp_deregister_style( 'wptl-control-center' );
\WPTitleLayer\Admin\ControlCenter::enqueueAssets( 'dashboard' );
wptl_test_assert( ! wp_style_is( 'wptl-control-center', 'enqueued' ), 'Control Center CSS loaded on an unrelated admin page.' );
\WPTitleLayer\Admin\ControlCenter::enqueueAssets( 'toplevel_page_' . \WPTitleLayer\Admin\ControlCenter::PAGE_SLUG );
wptl_test_assert( wp_style_is( 'wptl-control-center', 'enqueued' ), 'Control Center CSS did not load on its own page.' );
$wptl_control_style = wp_styles()->registered['wptl-control-center'] ?? null;
wptl_test_assert(
	$wptl_control_style && false !== strpos( (string) $wptl_control_style->src, 'assets/admin.css' ),
	'The Control Center registered an unexpected stylesheet URL.'
);

$wptl_control_snapshot = \WPTitleLayer\Admin\ControlCenter::snapshot();
wptl_test_assert( is_array( $wptl_control_snapshot ), 'The Control Center snapshot did not return an array.' );
wptl_test_assert( empty( $wptl_control_snapshot['legacy_active'] ), 'Normal mode was misidentified as legacy migration-only mode.' );
wptl_test_assert(
	! empty( $wptl_control_snapshot['presentation']['available'] ) && ! empty( $wptl_control_snapshot['reader']['available'] ),
	'Normal-mode Presentation or Reader status was unavailable in the Control Center.'
);
foreach ( array( 'migration', 'settings', 'series', 'series_health', 'channel_health', 'articles' ) as $wptl_control_url_key ) {
	wptl_test_assert(
		! empty( $wptl_control_snapshot['urls'][ $wptl_control_url_key ] ),
		'The Control Center snapshot omitted its ' . $wptl_control_url_key . ' link.'
	);
}

ob_start();
\WPTitleLayer\Admin\ControlCenter::render();
$wptl_control_html = (string) ob_get_clean();
wptl_test_assert(
	false !== strpos( $wptl_control_html, 'wptl-control-center' ) && false !== strpos( $wptl_control_html, 'page=wp-title-layer-migration' ),
	'The Control Center page did not render its shell and migration link.'
);
wptl_test_assert(
	false !== strpos( $wptl_control_html, 'page=wp-title-layer' ) && false !== strpos( $wptl_control_html, 'taxonomy=wptl_series' ),
	'The Control Center page did not render its settings and Series links.'
);
wptl_test_assert(
	false !== strpos( $wptl_control_html, 'page=wp-title-layer-series-health' ),
	'The Control Center did not expose Series health after a Series was created.'
);
wptl_test_assert(
	false !== strpos( $wptl_control_html, 'page=wp-title-layer-channel-health' )
	&& false !== strpos( $wptl_control_html, 'Landing pages and channels' ),
	'The Control Center did not expose the landing-page and channel report.'
);
$wptl_control_action_links = \WPTitleLayer\Admin\ControlCenter::pluginActionLinks( array( '<a href="#">Existing</a>' ) );
wptl_test_assert(
	isset( $wptl_control_action_links[0] ) && false !== strpos( $wptl_control_action_links[0], \WPTitleLayer\Admin\ControlCenter::PAGE_SLUG ),
	'The plugin row did not expose the Control Center link.'
);
wp_dequeue_style( 'wptl-control-center' );
$GLOBALS['current_screen'] = $wptl_control_old_screen;

/* Classic Editor receives canonical controls only when Gutenberg is disabled. */
wptl_test_assert(
	false !== has_action( 'add_meta_boxes', array( 'WPTitleLayer\\Admin\\ClassicEditor', 'addMetaBox' ) )
	&& false !== has_action( 'save_post', array( 'WPTitleLayer\\Admin\\ClassicEditor', 'savePost' ) ),
	'The Classic Editor compatibility layer did not register in normal mode.'
);
$wptl_classic_post_id = wptl_test_create_post( 'Classic editor Title Layer fixture' );
$wptl_classic_term_id = wptl_test_create_term( 'Classic editor Series', 'wptl_series' );
update_term_meta( $wptl_classic_term_id, 'wptl_series_mode', 'ordered' );
update_term_meta( $wptl_classic_term_id, 'wptl_series_structure', 'seasoned' );
update_term_meta( $wptl_classic_term_id, 'wptl_seasons', array( array( 'key' => 'classic-season', 'label' => 'Classic season', 'sort' => 10 ) ) );
$wptl_force_classic_editor = static function () { return false; };
add_filter( 'use_block_editor_for_post', $wptl_force_classic_editor, PHP_INT_MAX );
wptl_test_assert( \WPTitleLayer\Admin\ClassicEditor::shouldRender( get_post( $wptl_classic_post_id ) ), 'The compatibility meta box did not appear when the Classic Editor was active.' );
ob_start();
\WPTitleLayer\Admin\ClassicEditor::renderMetaBox( get_post( $wptl_classic_post_id ) );
$wptl_classic_html = (string) ob_get_clean();
wptl_test_assert(
	false !== strpos( $wptl_classic_html, 'name="wptl_classic[subtitle]"' )
	&& false !== strpos( $wptl_classic_html, 'name="wptl_classic[series_id]"' )
	&& false !== strpos( $wptl_classic_html, 'Classic editor Series' )
	&& false === strpos( $wptl_classic_html, 'Add New Series' ),
	'The Classic Editor did not expose the canonical fields without a duplicate term-creation control.'
);
$wptl_classic_previous_post = $_POST;
$_POST = array(
	'wptl_classic' => array( '_present' => 1, 'subtitle' => 'Missing nonce write' ),
);
\WPTitleLayer\Admin\ClassicEditor::savePost( $wptl_classic_post_id, get_post( $wptl_classic_post_id ), true );
wptl_test_assert( ! metadata_exists( 'post', $wptl_classic_post_id, 'wptl_subtitle' ), 'Classic Editor accepted a write without its nonce.' );
if ( ! is_wp_error( $wptl_subscriber_id ) ) {
	wp_set_current_user( (int) $wptl_subscriber_id );
	$_POST = array(
		'wptl_classic_editor_nonce' => wp_create_nonce( 'wptl_save_classic_editor' ),
		'wptl_classic'              => array( '_present' => 1, 'subtitle' => 'Unauthorized write' ),
	);
	\WPTitleLayer\Admin\ClassicEditor::savePost( $wptl_classic_post_id, get_post( $wptl_classic_post_id ), true );
	wptl_test_assert( ! metadata_exists( 'post', $wptl_classic_post_id, 'wptl_subtitle' ), 'A user unable to edit the post changed Classic Editor fields.' );
	wp_set_current_user( (int) $wptl_admin_id );
}
$_POST = array(
	'wptl_classic_editor_nonce' => wp_create_nonce( 'wptl_save_classic_editor' ),
	'wptl_classic'              => array(
		'_present'          => 1,
		'subtitle'          => 'Classic subtitle',
		'series_id'         => $wptl_classic_term_id,
		'season_key'        => 'classic-season',
		'sequence_position' => '0',
		'sequence_label'    => 'Introduction',
		'series_role'       => 'intro',
		'kicker_override'   => 'Classic kicker',
		'template_override' => 'inline',
	),
);
\WPTitleLayer\Admin\ClassicEditor::savePost( $wptl_classic_post_id, get_post( $wptl_classic_post_id ), true );
wptl_test_same( 'Classic subtitle', get_post_meta( $wptl_classic_post_id, 'wptl_subtitle', true ), 'Classic Editor did not save the canonical Subtitle.' );
$wptl_classic_saved_term = \WPTitleLayer\Core\Series::get_primary_term( $wptl_classic_post_id );
wptl_test_same( $wptl_classic_term_id, $wptl_classic_saved_term instanceof WP_Term ? (int) $wptl_classic_saved_term->term_id : 0, 'Classic Editor did not save the single Series relationship.' );
wptl_test_same( 'classic-season', get_post_meta( $wptl_classic_post_id, 'wptl_season_key', true ), 'Classic Editor did not save a defined season.' );
wptl_test_same( '0', (string) get_post_meta( $wptl_classic_post_id, 'wptl_sequence_position', true ), 'Classic Editor lost the valid zero sequence position.' );
wptl_test_same( 'inline', get_post_meta( $wptl_classic_post_id, 'wptl_template_override', true ), 'Classic Editor did not save the template override.' );
$_POST['wptl_classic']['subtitle'] = '';
\WPTitleLayer\Admin\ClassicEditor::savePost( $wptl_classic_post_id, get_post( $wptl_classic_post_id ), true );
wptl_test_assert(
	metadata_exists( 'post', $wptl_classic_post_id, 'wptl_subtitle' )
	&& '' === get_post_meta( $wptl_classic_post_id, 'wptl_subtitle', true ),
	'Clearing Subtitle in Classic Editor allowed a retained legacy value to reappear.'
);
$_POST = $wptl_classic_previous_post;
remove_filter( 'use_block_editor_for_post', $wptl_force_classic_editor, PHP_INT_MAX );
$wptl_force_block_editor = static function () { return true; };
add_filter( 'use_block_editor_for_post', $wptl_force_block_editor, PHP_INT_MAX );
wptl_test_assert( ! \WPTitleLayer\Admin\ClassicEditor::shouldRender( get_post( $wptl_classic_post_id ) ), 'Classic controls duplicated the Gutenberg document panel.' );
remove_filter( 'use_block_editor_for_post', $wptl_force_block_editor, PHP_INT_MAX );
wp_delete_post( $wptl_classic_post_id, true );
wp_delete_term( $wptl_classic_term_id, 'wptl_series' );

$wptl_series_parent_category_id = wptl_test_create_term( 'WPTL Series Parent Category', 'category' );
$wptl_series_child_category_id  = wptl_test_create_term( 'WPTL Series Child Category', 'category' );
wp_update_term( $wptl_series_child_category_id, 'category', array( 'parent' => $wptl_series_parent_category_id ) );
$wptl_previous_post = $_POST;
$_POST = array(
	'wptl_series_term_nonce' => wp_create_nonce( 'wptl_save_series_term' ),
	'wptl_term'              => array(
		'wptl_series_status'    => 'completed',
		'wptl_series_structure' => 'seasoned',
		'wptl_navigation_scope' => 'season',
		'wptl_parent_category_id' => $wptl_series_parent_category_id,
		'wptl_archive_layout'     => 'structured',
		'wptl_archive_subtitles'  => 'hide',
		'wptl_archive_excerpts'   => 'show',
		'wptl_archive_featured_images' => 'show',
		'wptl_title_icon'         => 'hide',
		'wptl_icon_id'            => '999999',
		'wptl_seasons'          => array(
			array( 'key' => '', 'label' => '第一季', 'sort' => '10' ),
			array( 'key' => '', 'label' => '第二季', 'sort' => '20' ),
		),
	),
);
\WPTitleLayer\Core\Series::save_term_fields( $wptl_auth_term_id );
$wptl_saved_status = \WPTitleLayer\Core\Series::status( $wptl_auth_term_id );
$wptl_saved_status_label = apply_filters( 'manage_wptl_series_custom_column', '', 'wptl_series_status', $wptl_auth_term_id );
$wptl_saved_navigation_scope = \WPTitleLayer\Core\Series::navigation_scope( $wptl_auth_term_id );
$wptl_saved_parent_category  = \WPTitleLayer\Core\Series::parent_category_id( $wptl_auth_term_id );
$_POST = array(
	'wptl_series_term_nonce' => wp_create_nonce( 'wptl_save_series_term' ),
	'wptl_term'              => array( 'wptl_series_status' => '' ),
);
\WPTitleLayer\Core\Series::save_term_fields( $wptl_auth_term_id );
$_POST = $wptl_previous_post;
wp_set_current_user( $wptl_original_user_id );
$wptl_saved_seasons = get_term_meta( $wptl_auth_term_id, 'wptl_seasons', true );
wptl_test_same( 'completed', $wptl_saved_status, 'The native Series form did not save the lifecycle status.' );
wptl_test_same( 'Completed', $wptl_saved_status_label, 'The Series list did not render the saved lifecycle status.' );
wptl_test_same( 'season', $wptl_saved_navigation_scope, 'The native Series form did not save the current-season navigation boundary.' );
wptl_test_same( 'season', \WPTitleLayer\Core\Series::navigation_scope( $wptl_auth_term_id ), 'A later partial Series form save silently cleared the navigation boundary.' );
wptl_test_same( $wptl_series_parent_category_id, $wptl_saved_parent_category, 'The native Series form did not save the parent Category.' );
wptl_test_same( $wptl_series_parent_category_id, \WPTitleLayer\Core\Series::parent_category_id( $wptl_auth_term_id ), 'A partial Series save silently cleared its parent Category.' );
wptl_test_same( 'structured', \WPTitleLayer\Core\Series::archive_layout( $wptl_auth_term_id ), 'A partial Series save silently cleared its archive layout override.' );
wptl_test_same( 'hide', \WPTitleLayer\Core\Series::archive_subtitles( $wptl_auth_term_id ), 'A partial Series save silently cleared its archive Subtitle override.' );
wptl_test_same( 'show', \WPTitleLayer\Core\Series::archive_excerpts( $wptl_auth_term_id ), 'A partial Series save silently cleared its archive Excerpt override.' );
wptl_test_same( 'show', \WPTitleLayer\Core\Series::archive_featured_images( $wptl_auth_term_id ), 'A partial Series save silently cleared its featured-image override.' );
wptl_test_same( 'hide', \WPTitleLayer\Core\Series::title_icon( $wptl_auth_term_id ), 'A partial Series save silently cleared its title-icon override.' );
wptl_test_same( 999999, (int) get_term_meta( $wptl_auth_term_id, 'wptl_icon_id', true ), 'The Series form did not retain its canonical icon attachment ID.' );
wptl_test_same( 0, \WPTitleLayer\Core\Series::icon_id( $wptl_auth_term_id ), 'A missing/non-image attachment was exposed as a public Series icon.' );
wptl_test_assert( ! metadata_exists( 'term', $wptl_auth_term_id, 'wptl_series_status' ), 'Clearing a Series lifecycle status left a misleading empty meta row.' );
wptl_test_same( '', \WPTitleLayer\Core\Series::status_label( $wptl_auth_term_id ), 'A cleared Series lifecycle status still had a public label.' );
wptl_test_same( '第一季', $wptl_saved_seasons[0]['label'] ?? '', 'The native Series form did not save the first season label.' );
wptl_test_same( 'season', $wptl_saved_seasons[0]['key'] ?? '', 'The native Series form did not generate a stable season key.' );
wptl_test_same( 'season-2', $wptl_saved_seasons[1]['key'] ?? '', 'Duplicate generated season keys were not made unique.' );

/* Series content health is read-only, status-aware, and bounded. */
wp_set_current_user( (int) $wptl_admin_id );
$wptl_health_term_id = wptl_test_create_term( 'WPTL Health Ordered Series', 'wptl_series' );
update_term_meta( $wptl_health_term_id, 'wptl_series_mode', 'ordered' );
update_term_meta( $wptl_health_term_id, 'wptl_series_structure', 'seasoned' );
update_term_meta( $wptl_health_term_id, 'wptl_series_status', 'ongoing' );
update_term_meta(
	$wptl_health_term_id,
	'wptl_seasons',
	array(
		array( 'key' => 'arrival', 'label' => 'Arrival', 'sort' => 10 ),
		array( 'key' => 'return', 'label' => 'Return', 'sort' => 20 ),
	)
);

$wptl_health_specs = array(
	array( 'title' => 'Health published duplicate', 'status' => 'publish', 'season' => 'arrival', 'position' => 10 ),
	array( 'title' => 'Health published missing season', 'status' => 'publish', 'position' => 20 ),
	array( 'title' => 'Health draft missing position', 'status' => 'draft', 'season' => 'arrival' ),
	array( 'title' => 'Health scheduled duplicate', 'status' => 'future', 'season' => 'arrival', 'position' => 10 ),
	array( 'title' => 'Health private invalid season', 'status' => 'private', 'season' => 'unknown', 'position' => 30 ),
	array( 'title' => 'Health pending valid', 'status' => 'pending', 'season' => 'return', 'position' => 40 ),
	array( 'title' => 'Health password valid', 'status' => 'publish', 'season' => 'return', 'position' => 50, 'password' => 'health-secret' ),
	array( 'title' => 'Health trashed incomplete', 'status' => 'publish', 'trash' => true ),
);
$wptl_health_post_ids = array();
foreach ( $wptl_health_specs as $wptl_health_spec ) {
	$wptl_health_post_data = array(
		'post_type'   => 'post',
		'post_status' => $wptl_health_spec['status'],
		'post_title'  => $wptl_health_spec['title'],
		'post_content'=> 'Series health fixture.',
	);
	if ( 'future' === $wptl_health_spec['status'] ) {
		$wptl_health_post_data['post_date']     = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$wptl_health_post_data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
	}
	if ( ! empty( $wptl_health_spec['password'] ) ) {
		$wptl_health_post_data['post_password'] = $wptl_health_spec['password'];
	}
	$wptl_health_post_id = wp_insert_post( $wptl_health_post_data, true );
	wptl_test_assert( ! is_wp_error( $wptl_health_post_id ), 'Could not create a Series health article fixture.' );
	if ( is_wp_error( $wptl_health_post_id ) ) {
		continue;
	}
	$wptl_health_post_id = (int) $wptl_health_post_id;
	$wptl_health_post_ids[] = $wptl_health_post_id;
	wp_set_object_terms( $wptl_health_post_id, array( $wptl_health_term_id ), 'wptl_series' );
	if ( isset( $wptl_health_spec['season'] ) ) {
		update_post_meta( $wptl_health_post_id, 'wptl_season_key', $wptl_health_spec['season'] );
	}
	if ( isset( $wptl_health_spec['position'] ) ) {
		update_post_meta( $wptl_health_post_id, 'wptl_sequence_position', $wptl_health_spec['position'] );
	}
	if ( ! empty( $wptl_health_spec['trash'] ) ) {
		wp_trash_post( $wptl_health_post_id );
	}
}

$wptl_health_snapshot = static function () use ( $wptl_health_term_id, $wptl_health_post_ids ) {
	$posts = array();
	foreach ( $wptl_health_post_ids as $post_id ) {
		$posts[ $post_id ] = array(
			'post'     => get_post( $post_id )->post_status,
			'season'   => get_post_meta( $post_id, 'wptl_season_key', false ),
			'position' => get_post_meta( $post_id, 'wptl_sequence_position', false ),
			'terms'    => wp_get_object_terms( $post_id, 'wptl_series', array( 'fields' => 'ids' ) ),
		);
	}
	return maybe_serialize(
		array(
			'term'  => get_term_meta( $wptl_health_term_id ),
			'posts' => $posts,
		)
	);
};

$wptl_health_before  = $wptl_health_snapshot();
$wptl_health_service = new \WPTitleLayer\Admin\SeriesHealthReport();
$wptl_health_report  = $wptl_health_service->report( $wptl_health_term_id, 'issues', 1, 2 );
$wptl_health_summary = $wptl_health_report['summary'];
wptl_test_assert( ! empty( $wptl_health_report['valid'] ), 'The Series health service rejected a valid claimed Series.' );
wptl_test_same( 'ordered', $wptl_health_report['mode'], 'The Series health report lost the ordered reading model.' );
wptl_test_same( 'seasoned', $wptl_health_report['structure'], 'The Series health report lost the seasoned structure.' );
wptl_test_same( 'ongoing', $wptl_health_report['status'], 'The Series health report lost the lifecycle status.' );
wptl_test_same( 8, $wptl_health_summary['members'], 'The Series health report counted the wrong number of members.' );
wptl_test_same( 3, $wptl_health_summary['published'], 'The Series health report counted published articles incorrectly.' );
wptl_test_same( 1, $wptl_health_summary['draft'], 'The Series health report did not distinguish drafts.' );
wptl_test_same( 1, $wptl_health_summary['scheduled'], 'The Series health report did not distinguish scheduled articles.' );
wptl_test_same( 1, $wptl_health_summary['private'], 'The Series health report did not distinguish private articles.' );
wptl_test_same( 1, $wptl_health_summary['pending'], 'The Series health report did not distinguish pending articles.' );
wptl_test_same( 1, $wptl_health_summary['trash'], 'The Series health report did not distinguish trashed articles.' );
wptl_test_same( 1, $wptl_health_summary['password_protected'], 'The Series health report did not distinguish password protection.' );
wptl_test_same( 2, $wptl_health_summary['published_without_password'], 'The open-publish count included a password-protected article.' );
wptl_test_same( 1, $wptl_health_summary['missing_positions'], 'The Series health report missed an absent position.' );
wptl_test_same( 0, $wptl_health_summary['invalid_positions'], 'The Series health report invented an invalid position.' );
wptl_test_same( 1, $wptl_health_summary['missing_seasons'], 'The Series health report missed an absent season.' );
wptl_test_same( 1, $wptl_health_summary['invalid_seasons'], 'The Series health report missed an undefined season.' );
wptl_test_same( 2, $wptl_health_summary['duplicate_positions'], 'The Series health report did not flag both sides of a duplicate position.' );
wptl_test_same( 5, $wptl_health_summary['structural_issues'], 'The Series health report counted unique structural issues incorrectly.' );
wptl_test_same( 4, $wptl_health_summary['required_status_issues'], 'Published-state structural issues were not separated from work in progress.' );
wptl_test_same( 1, $wptl_health_summary['work_in_progress_issues'], 'Draft-stage structural gaps were not separated from published-state issues.' );
wptl_test_same( 5, $wptl_health_report['total'], 'The issues view did not use the aggregate issue count.' );
wptl_test_same( 2, count( $wptl_health_report['rows'] ), 'The issues view did not honor its bounded page size.' );
wptl_test_same( 3, $wptl_health_report['total_pages'], 'The issues view calculated pagination incorrectly.' );

foreach (
	array(
		'missing_position'   => 1,
		'season'             => 2,
		'duplicate_position' => 2,
		'not_public'         => 6,
	) as $wptl_health_view => $wptl_health_expected
) {
	$wptl_health_filtered = $wptl_health_service->report( $wptl_health_term_id, $wptl_health_view, 1, 50 );
	wptl_test_same( $wptl_health_expected, $wptl_health_filtered['total'], 'A Series health filter returned the wrong total: ' . $wptl_health_view );
}

$wptl_health_all_page = $wptl_health_service->report( $wptl_health_term_id, 'all', 2, 2 );
wptl_test_same( 8, $wptl_health_all_page['total'], 'The all-members report did not retain the whole-Series total.' );
wptl_test_same( 2, count( $wptl_health_all_page['rows'] ), 'The all-members report did not paginate its SQL result.' );
wptl_test_same( 2, $wptl_health_all_page['page'], 'The all-members report ignored the requested page.' );
wptl_test_same( $wptl_health_before, $wptl_health_snapshot(), 'Reading the Series health report changed Series or article data.' );

$wptl_unordered_health_term_id = wptl_test_create_term( 'WPTL Health Unordered Flat', 'wptl_series' );
$wptl_unordered_health_post_id = wptl_test_create_post( 'Health unordered without structural metadata' );
wp_set_object_terms( $wptl_unordered_health_post_id, array( $wptl_unordered_health_term_id ), 'wptl_series' );
$wptl_unordered_health = $wptl_health_service->report( $wptl_unordered_health_term_id );
wptl_test_same( 0, $wptl_unordered_health['summary']['structural_issues'], 'An unordered flat Series was falsely reported as missing a reading position.' );
wptl_test_same( 0, $wptl_unordered_health['summary']['position_issues'], 'An unordered Series received position issues.' );

/* Parent Category means the same Category branch, not exact-term equality. */
update_term_meta( $wptl_unordered_health_term_id, 'wptl_parent_category_id', $wptl_series_parent_category_id );
wp_set_post_categories( $wptl_unordered_health_post_id, array( $wptl_series_child_category_id ) );
$wptl_category_consistent = $wptl_health_service->report( $wptl_unordered_health_term_id, 'category', 1, 50 );
wptl_test_same( 0, $wptl_category_consistent['total'], 'A child Category was falsely treated as outside the Series Category branch.' );
$wptl_category_mismatch_post_id = wptl_test_create_post( 'Health Category mismatch' );
$wptl_unrelated_category_id = wptl_test_create_term( 'WPTL Unrelated Category', 'category' );
wp_set_object_terms( $wptl_category_mismatch_post_id, array( $wptl_unordered_health_term_id ), 'wptl_series' );
wp_set_post_categories( $wptl_category_mismatch_post_id, array( $wptl_unrelated_category_id ) );
$wptl_category_mismatch = $wptl_health_service->report( $wptl_unordered_health_term_id, 'category', 1, 50 );
wptl_test_same( 1, $wptl_category_mismatch['total'], 'The Series health report missed an article outside its Category branch.' );
wptl_test_assert(
	in_array( 'category_mismatch', (array) ( $wptl_category_mismatch['rows'][0]['issues'] ?? array() ), true ),
	'The Category mismatch row did not explain its issue.'
);

$wptl_seasoned_health_term_id = wptl_test_create_term( 'WPTL Health Unordered Seasoned', 'wptl_series' );
update_term_meta( $wptl_seasoned_health_term_id, 'wptl_series_structure', 'seasoned' );
update_term_meta( $wptl_seasoned_health_term_id, 'wptl_seasons', array( array( 'key' => 'only', 'label' => 'Only', 'sort' => 10 ) ) );
$wptl_seasoned_health_post_id = wptl_test_create_post( 'Health unordered missing season' );
wp_set_object_terms( $wptl_seasoned_health_post_id, array( $wptl_seasoned_health_term_id ), 'wptl_series' );
$wptl_seasoned_health = $wptl_health_service->report( $wptl_seasoned_health_term_id );
wptl_test_same( 1, $wptl_seasoned_health['summary']['season_issues'], 'A seasoned unordered Series did not require its season assignment.' );
wptl_test_same( 0, $wptl_seasoned_health['summary']['position_issues'], 'A seasoned unordered Series was incorrectly required to have positions.' );
wptl_test_same( 0, $wptl_seasoned_health['summary']['duplicate_positions'], 'An unordered Series received duplicate-position errors.' );

$wptl_health_terms_page = $wptl_health_service->terms_page( 1, 1 );
wptl_test_same( 1, count( $wptl_health_terms_page['terms'] ), 'The Series health overview did not honor its term page size.' );
wptl_test_assert( 2 <= $wptl_health_terms_page['total_pages'], 'The Series health overview did not paginate multiple Series.' );
$wptl_health_last_terms_page = $wptl_health_service->terms_page( 999999, 1 );
wptl_test_same( $wptl_health_last_terms_page['total_pages'], $wptl_health_last_terms_page['page'], 'The Series health overview did not clamp an out-of-range page.' );
wptl_test_same( 1, count( $wptl_health_last_terms_page['terms'] ), 'The clamped Series overview page was unexpectedly empty.' );
$wptl_health_search_page = $wptl_health_service->terms_page( 1, 20, 'WPTL Health Unordered Seasoned' );
wptl_test_same( 'WPTL Health Unordered Seasoned', $wptl_health_search_page['search'], 'The Series health overview did not preserve its sanitized search.' );
wptl_test_same( 1, $wptl_health_search_page['total'], 'The Series health overview search did not narrow the term count.' );
wptl_test_same( $wptl_seasoned_health_term_id, (int) $wptl_health_search_page['terms'][0]->term_id, 'The Series health overview search returned the wrong Series.' );
$wptl_health_empty_search_page = $wptl_health_service->terms_page( 999999, 20, 'No Series Uses This Exact Search Phrase' );
wptl_test_same( 0, $wptl_health_empty_search_page['total'], 'The Series health overview returned terms for an unmatched search.' );
wptl_test_same( 1, $wptl_health_empty_search_page['page'], 'An unmatched Series health search did not return to the first page.' );
wptl_test_assert( empty( $wptl_health_service->report( 99999999 )['valid'] ), 'The Series health service accepted a missing Series.' );

$wptl_health_old_screen = $GLOBALS['current_screen'] ?? null;
set_current_screen( 'dashboard' );
\WPTitleLayer\Admin\SeriesHealth::register();
wptl_test_assert(
	false !== has_action( 'admin_menu', array( 'WPTitleLayer\\Admin\\SeriesHealth', 'add_page' ) ),
	'The Series health page did not register its admin-menu callback.'
);
\WPTitleLayer\Admin\SeriesHealth::add_page();
$wptl_health_reflection = new ReflectionClass( \WPTitleLayer\Admin\SeriesHealth::class );
$wptl_health_hook_property = $wptl_health_reflection->getProperty( 'hook_suffix' );
$wptl_health_hook_property->setAccessible( true );
$wptl_health_hook = (string) $wptl_health_hook_property->getValue();
wptl_test_assert( '' !== $wptl_health_hook, 'The Series health submenu was not created.' );
wp_dequeue_style( 'wptl-series-health' );
wp_deregister_style( 'wptl-series-health' );
\WPTitleLayer\Admin\SeriesHealth::enqueue_assets( 'dashboard' );
wptl_test_assert( ! wp_style_is( 'wptl-series-health', 'enqueued' ), 'Series health CSS loaded outside its page.' );
\WPTitleLayer\Admin\SeriesHealth::enqueue_assets( $wptl_health_hook );
wptl_test_assert( wp_style_is( 'wptl-series-health', 'enqueued' ), 'Series health CSS did not load on its own page.' );

$wptl_health_blocked = false;
if ( ! is_wp_error( $wptl_subscriber_id ) ) {
	wp_set_current_user( (int) $wptl_subscriber_id );
	add_filter( 'wp_die_handler', $wptl_control_die_filter );
	try {
		\WPTitleLayer\Admin\SeriesHealth::render();
	} catch ( RuntimeException $error ) {
		$wptl_health_blocked = false !== strpos( $error->getMessage(), 'not allowed' );
	}
	remove_filter( 'wp_die_handler', $wptl_control_die_filter );
}
wptl_test_assert( $wptl_health_blocked, 'A user without manage_options could render Series health.' );
wp_set_current_user( (int) $wptl_admin_id );
$wptl_health_previous_get = $_GET;
$_GET = array(
	'page'        => \WPTitleLayer\Admin\SeriesHealth::PAGE_SLUG,
	'series_id'   => (string) $wptl_health_term_id,
	'health_view' => 'issues',
	'health_page' => '1',
);
ob_start();
\WPTitleLayer\Admin\SeriesHealth::render();
$wptl_health_html = (string) ob_get_clean();
$_GET = $wptl_health_previous_get;
wptl_test_assert(
	false !== strpos( $wptl_health_html, 'Read-only report' )
	&& false !== strpos( $wptl_health_html, 'WPTL Health Ordered Series' ),
	'The Series health page did not render its read-only selected-Series report.'
);
wptl_test_assert(
	false !== strpos( $wptl_health_html, 'Position duplicated' )
	&& false !== strpos( $wptl_health_html, 'Season missing' )
	&& false !== strpos( $wptl_health_html, 'post.php?post=' ),
	'The Series health page did not expose actionable article-level findings.'
);
wptl_test_assert( false === stripos( $wptl_health_html, '<form' ), 'The read-only Series health page unexpectedly rendered a mutation form.' );
wptl_test_assert( false === strpos( $wptl_health_html, 'health-secret' ), 'The Series health page exposed a post password.' );
$wptl_health_previous_get = $_GET;
$_GET = array(
	'page'          => \WPTitleLayer\Admin\SeriesHealth::PAGE_SLUG,
	'series_search' => 'WPTL Health Unordered Seasoned',
);
ob_start();
\WPTitleLayer\Admin\SeriesHealth::render();
$wptl_health_overview_html = (string) ob_get_clean();
$_GET = $wptl_health_previous_get;
wptl_test_assert(
	false !== strpos( $wptl_health_overview_html, 'name="series_search"' )
	&& false !== strpos( $wptl_health_overview_html, 'WPTL Health Unordered Seasoned' )
	&& false === strpos( $wptl_health_overview_html, 'WPTL Health Ordered Series' ),
	'The Series health overview did not render a read-only filtered result.'
);
wptl_test_assert( false === stripos( $wptl_health_overview_html, 'method="post"' ), 'The Series health search introduced a mutation form.' );
wp_dequeue_style( 'wptl-series-health' );
$GLOBALS['current_screen'] = $wptl_health_old_screen;
wp_set_current_user( $wptl_original_user_id );

/* Landing-page and title-channel governance is factual, bounded, and read-only. */
wp_set_current_user( (int) $wptl_admin_id );
$wptl_channel_term_id = wptl_test_create_term( 'WPTL Channel Series', 'wptl_series' );
$wptl_channel_exact_id = wptl_test_create_term( 'WPTL Exact Category', 'category' );
$wptl_channel_partial_id = wptl_test_create_term( 'WPTL Partial Category', 'category' );
$wptl_channel_posts = array();
for ( $wptl_channel_index = 1; $wptl_channel_index <= 3; ++$wptl_channel_index ) {
	$wptl_channel_post_id = wptl_test_create_post( 'Channel public ' . $wptl_channel_index );
	$wptl_channel_posts[] = $wptl_channel_post_id;
	wp_set_object_terms( $wptl_channel_post_id, array( $wptl_channel_term_id ), 'wptl_series' );
	wp_set_object_terms( $wptl_channel_post_id, array( $wptl_channel_exact_id ), 'category' );
	if ( $wptl_channel_index <= 2 ) {
		wp_set_object_terms( $wptl_channel_post_id, array( $wptl_channel_partial_id ), 'category', true );
	}
}
$wptl_channel_partial_extra = wptl_test_create_post( 'Channel Category-only public article' );
wp_set_object_terms( $wptl_channel_partial_extra, array( $wptl_channel_partial_id ), 'category' );
$wptl_channel_draft = wp_insert_post(
	array(
		'post_type'   => 'post',
		'post_status' => 'draft',
		'post_title'  => 'Channel draft sentinel',
	),
	true
);
$wptl_channel_password = wp_insert_post(
	array(
		'post_type'     => 'post',
		'post_status'   => 'publish',
		'post_title'    => 'Channel password sentinel',
		'post_password' => 'channel-secret',
	),
	true
);
foreach ( array( $wptl_channel_draft, $wptl_channel_password ) as $wptl_channel_hidden_post ) {
	if ( ! is_wp_error( $wptl_channel_hidden_post ) ) {
		wp_set_object_terms( (int) $wptl_channel_hidden_post, array( $wptl_channel_term_id ), 'wptl_series' );
		wp_set_object_terms( (int) $wptl_channel_hidden_post, array( $wptl_channel_exact_id ), 'category' );
	}
}

$wptl_old_rank_titles  = get_option( 'rank-math-options-titles', false );
$wptl_old_rank_sitemap = get_option( 'rank-math-options-sitemap', false );
$wptl_old_rank_modules = get_option( 'rank_math_modules', false );
update_option(
	'rank-math-options-titles',
	array(
		'tax_wptl_series_title'         => '%wptl_series% %sep% %sitename%',
		'tax_wptl_series_description'   => '%term_description%',
		'tax_wptl_series_custom_robots' => 'on',
		'tax_wptl_series_robots'        => array( 'index', 'follow' ),
		'robots_global'                  => array( 'index' ),
		'pt_post_title'                  => '%wptl_title_with_subtitle% %sep% %sitename%',
	)
);
update_option(
	'rank-math-options-sitemap',
	array(
		'tax_wptl_series_sitemap' => 'on',
		'exclude_terms'            => '',
	)
);
update_option( 'rank_math_modules', array( 'sitemap' ) );
update_term_meta( $wptl_channel_term_id, 'rank_math_title', '%wptl_series% Channel landing' );
update_term_meta( $wptl_channel_term_id, 'rank_math_description', 'Channel description override' );
update_term_meta( $wptl_channel_term_id, 'rank_math_robots', array( 'noindex', 'follow' ) );
update_term_meta( $wptl_channel_term_id, 'rank_math_canonical_url', 'https://example.org/preferred-series/' );
update_term_meta( $wptl_channel_term_id, 'rank_math_facebook_title', '%wptl_series% on Facebook' );
update_post_meta( $wptl_channel_posts[0], 'rank_math_title', '%wptl_title_with_subtitle%' );
update_post_meta( $wptl_channel_posts[0], 'rank_math_facebook_title', 'Plain Facebook override' );
update_post_meta( $wptl_channel_posts[1], 'rank_math_twitter_title', '%wptl_subtitle%' );

$wptl_channel_snapshot = static function () use ( $wptl_channel_term_id, $wptl_channel_posts, $wptl_channel_exact_id, $wptl_channel_partial_id ) {
	$posts = array();
	foreach ( $wptl_channel_posts as $post_id ) {
		$posts[ $post_id ] = array(
			'meta'       => get_post_meta( $post_id ),
			'series'     => wp_get_object_terms( $post_id, 'wptl_series', array( 'fields' => 'ids' ) ),
			'categories' => wp_get_object_terms( $post_id, 'category', array( 'fields' => 'ids' ) ),
		);
	}
	return maybe_serialize(
		array(
			'term_meta' => get_term_meta( $wptl_channel_term_id ),
			'posts'     => $posts,
			'categories'=> array( get_term( $wptl_channel_exact_id, 'category' ), get_term( $wptl_channel_partial_id, 'category' ) ),
			'options'   => array(
				get_option( 'rank-math-options-titles', array() ),
				get_option( 'rank-math-options-sitemap', array() ),
				get_option( 'rank_math_modules', array() ),
			),
		)
	);
};

$wptl_channel_service = new \WPTitleLayer\Admin\ChannelHealthReport();
$wptl_channel_stale_overview = $wptl_channel_service->overview();
wptl_test_same( 'none', $wptl_channel_stale_overview['provider']['state'], 'Stored Rank Math options were mistaken for an active SEO provider.' );
wptl_test_assert( empty( $wptl_channel_stale_overview['rank_math']['active'] ), 'Inactive Rank Math options were treated as authoritative.' );

$wptl_force_rank_math = static function () {
	return array( 'rank_math' => 'Rank Math' );
};
add_filter( 'wptl_channel_health_providers', $wptl_force_rank_math );
$wptl_channel_before = $wptl_channel_snapshot();
$wptl_channel_overview = $wptl_channel_service->overview();
$wptl_channel_report = $wptl_channel_service->report( $wptl_channel_term_id, 1, 1 );
wptl_test_same( 'rank_math', $wptl_channel_overview['provider']['state'], 'The supported provider state did not identify Rank Math.' );
$wptl_channel_presentation = \WPTitleLayer\Presentation\SettingsPage::presentationSettings();
wptl_test_same( $wptl_channel_presentation['display_mode'], $wptl_channel_overview['visible']['mode'], 'The channel report lost the visible title-display mode.' );
wptl_test_same( 'taxonomy_option', $wptl_channel_overview['rank_math']['taxonomy_title']['source'], 'An explicit Rank Math taxonomy title was reported as a provider default.' );
wptl_test_same( 'title_with_subtitle', $wptl_channel_overview['rank_math']['article_title']['policy'], 'The article SEO-title template did not recognize the conditional subtitle variable.' );
wptl_test_assert( ! empty( $wptl_channel_overview['rank_math']['sitemap']['module_active'] ) && ! empty( $wptl_channel_overview['rank_math']['sitemap']['enabled'] ), 'The Rank Math sitemap module and Series-taxonomy switches were not interpreted independently.' );
wptl_test_assert( ! empty( $wptl_channel_report['valid'] ), 'The channel report rejected a valid Series.' );
wptl_test_same( 3, $wptl_channel_report['series_count'], 'Draft or password-protected articles polluted the public Series landing set.' );
wptl_test_same( 2, $wptl_channel_report['overlap_total'], 'The report counted the wrong number of overlapping Categories.' );
wptl_test_same( 2, $wptl_channel_report['total_pages'], 'Category overlaps did not honor bounded pagination.' );
wptl_test_same( 1, count( $wptl_channel_report['overlaps'] ), 'Category overlaps ignored the requested page size.' );
wptl_test_same( 'exact', $wptl_channel_report['overlaps'][0]['state'], 'An identical public Category set was not classified as an exact duplicate entry point.' );
wptl_test_same( 3, $wptl_channel_report['overlaps'][0]['shared_count'], 'The exact Category shared count was wrong.' );
wptl_test_same( 3, $wptl_channel_report['overlaps'][0]['category_count'], 'The exact Category public total was wrong.' );
wptl_test_same( 'self', $wptl_channel_report['overlaps'][0]['canonical_state'], 'An exact Category without an override was not reported as self-canonical under Rank Math.' );
wptl_test_same( 'canonical_review', $wptl_channel_report['overlaps'][0]['risk'], 'Two exact entry points with no consolidation direction did not receive a canonical review prompt.' );
$wptl_channel_partial_page = $wptl_channel_service->report( $wptl_channel_term_id, 2, 1 );
wptl_test_same( 'partial', $wptl_channel_partial_page['overlaps'][0]['state'], 'A partially overlapping Category was reported as an exact duplicate.' );
wptl_test_same( 2, $wptl_channel_partial_page['overlaps'][0]['shared_count'], 'The partial Category shared count was wrong.' );
wptl_test_same( 3, $wptl_channel_partial_page['overlaps'][0]['category_count'], 'The partial Category total was wrong.' );
wptl_test_same( 'term_override', $wptl_channel_report['channels']['search_title']['source'], 'The term-level Rank Math title did not outrank the taxonomy template.' );
wptl_test_same( 'series_term', $wptl_channel_report['channels']['search_title']['policy'], 'A Series search-title variable was treated as an article subtitle policy.' );
wptl_test_same( 'explicit', $wptl_channel_report['channels']['canonical']['state'], 'The explicit Rank Math canonical was not reported.' );
wptl_test_same( 'https://example.org/preferred-series/', $wptl_channel_report['channels']['canonical']['value'], 'The safe canonical URL changed during inspection.' );
wptl_test_assert( empty( $wptl_channel_report['channels']['robots']['indexable'] ), 'The term-level noindex override did not outrank taxonomy robots.' );
wptl_test_assert( ! empty( $wptl_channel_report['channels']['sitemap']['included'] ), 'A sitemap-enabled, non-excluded Series was reported as absent.' );
wptl_test_same( 'term_override', $wptl_channel_report['channels']['facebook']['source'], 'The Facebook term override was not recognized.' );
wptl_test_same( 'inherits_search_title', $wptl_channel_report['channels']['twitter']['source'], 'A missing Twitter title did not inherit the SEO title.' );
wptl_test_same( 1, $wptl_channel_report['article_overrides']['seo_titles'], 'The report counted article SEO-title overrides incorrectly.' );
wptl_test_same( 1, $wptl_channel_report['article_overrides']['seo_titles_with_wptl'], 'The report missed an article SEO-title variable.' );
wptl_test_same( 1, $wptl_channel_report['article_overrides']['facebook_titles'], 'The report counted article Facebook-title overrides incorrectly.' );
wptl_test_same( 0, $wptl_channel_report['article_overrides']['facebook_titles_with_wptl'], 'A plain Facebook title was mistaken for a WP Title Layer variable.' );
wptl_test_same( 1, $wptl_channel_report['article_overrides']['twitter_titles_with_wptl'], 'The report missed an article Twitter-title variable.' );
wptl_test_same( $wptl_channel_before, $wptl_channel_snapshot(), 'Reading landing-page and channel facts changed options, metadata, or taxonomy relationships.' );

$wptl_channel_series_url = \WPTitleLayer\Core\Series::public_archive_url( get_term( $wptl_channel_term_id, 'wptl_series' ) );
update_term_meta( $wptl_channel_exact_id, 'rank_math_canonical_url', $wptl_channel_series_url );
$wptl_channel_consolidated = $wptl_channel_service->report( $wptl_channel_term_id, 1, 1 );
wptl_test_same( 'points_to_series', $wptl_channel_consolidated['overlaps'][0]['canonical_state'], 'A Category canonical pointing to the Series was not recognized.' );
wptl_test_same( 'category_points_to_series', $wptl_channel_consolidated['overlaps'][0]['risk'], 'A configured Category-to-Series consolidation was still reported as parallel canonicals.' );

update_option( 'rank-math-options-sitemap', array( 'tax_wptl_series_sitemap' => 'on', 'exclude_terms' => (string) $wptl_channel_term_id ) );
$wptl_channel_excluded = $wptl_channel_service->report( $wptl_channel_term_id );
wptl_test_assert( ! empty( $wptl_channel_excluded['channels']['sitemap']['term_excluded'] ) && empty( $wptl_channel_excluded['channels']['sitemap']['included'] ), 'A Rank Math term-level sitemap exclusion was ignored.' );

remove_filter( 'wptl_channel_health_providers', $wptl_force_rank_math );
$wptl_force_other_provider = static function () {
	return array( 'yoast' => 'Yoast SEO' );
};
add_filter( 'wptl_channel_health_providers', $wptl_force_other_provider );
wptl_test_same( 'other_provider', $wptl_channel_service->overview()['provider']['state'], 'Another provider was not separated from supported Rank Math inspection.' );
remove_filter( 'wptl_channel_health_providers', $wptl_force_other_provider );
$wptl_force_multiple_providers = static function () {
	return array( 'rank_math' => 'Rank Math', 'yoast' => 'Yoast SEO' );
};
add_filter( 'wptl_channel_health_providers', $wptl_force_multiple_providers );
wptl_test_same( 'multiple_providers', $wptl_channel_service->overview()['provider']['state'], 'Multiple SEO providers were not reported as an ownership conflict.' );

$wptl_channel_terms_page = $wptl_channel_service->terms_page( 1, 1 );
wptl_test_same( 1, count( $wptl_channel_terms_page['terms'] ), 'The channel Series chooser did not honor its bounded page size.' );
wptl_test_assert( 2 <= $wptl_channel_terms_page['total_pages'], 'The channel Series chooser did not paginate.' );
wptl_test_assert( empty( $wptl_channel_service->report( 99999999 )['valid'] ), 'The channel report accepted a missing Series.' );

$wptl_channel_old_screen = $GLOBALS['current_screen'] ?? null;
set_current_screen( 'dashboard' );
\WPTitleLayer\Admin\ChannelHealth::register();
wptl_test_assert( false !== has_action( 'admin_menu', array( 'WPTitleLayer\\Admin\\ChannelHealth', 'add_page' ) ), 'The channel report did not register its admin-menu callback.' );
\WPTitleLayer\Admin\ChannelHealth::add_page();
$wptl_channel_reflection = new ReflectionClass( \WPTitleLayer\Admin\ChannelHealth::class );
$wptl_channel_hook_property = $wptl_channel_reflection->getProperty( 'hook_suffix' );
$wptl_channel_hook_property->setAccessible( true );
$wptl_channel_hook = (string) $wptl_channel_hook_property->getValue();
wptl_test_assert( '' !== $wptl_channel_hook, 'The channel report submenu was not created.' );
wp_dequeue_style( 'wptl-channel-health' );
wp_deregister_style( 'wptl-channel-health' );
\WPTitleLayer\Admin\ChannelHealth::enqueue_assets( 'dashboard' );
wptl_test_assert( ! wp_style_is( 'wptl-channel-health', 'enqueued' ), 'Channel report CSS loaded outside its own page.' );
\WPTitleLayer\Admin\ChannelHealth::enqueue_assets( $wptl_channel_hook );
wptl_test_assert( wp_style_is( 'wptl-channel-health', 'enqueued' ), 'Channel report CSS did not load on its own page.' );

$wptl_channel_blocked = false;
if ( ! is_wp_error( $wptl_subscriber_id ) ) {
	wp_set_current_user( (int) $wptl_subscriber_id );
	add_filter( 'wp_die_handler', $wptl_control_die_filter );
	try {
		\WPTitleLayer\Admin\ChannelHealth::render();
	} catch ( RuntimeException $error ) {
		$wptl_channel_blocked = false !== strpos( $error->getMessage(), 'not allowed' );
	}
	remove_filter( 'wp_die_handler', $wptl_control_die_filter );
}
wptl_test_assert( $wptl_channel_blocked, 'A user without manage_options could render the channel report.' );
wp_set_current_user( (int) $wptl_admin_id );
$wptl_channel_previous_get = $_GET;
$_GET = array( 'page' => \WPTitleLayer\Admin\ChannelHealth::PAGE_SLUG, 'series_id' => (string) $wptl_channel_term_id, 'channel_page' => '1' );
ob_start();
\WPTitleLayer\Admin\ChannelHealth::render();
$wptl_channel_html = (string) ob_get_clean();
$_GET = $wptl_channel_previous_get;
wptl_test_assert( false !== strpos( $wptl_channel_html, 'Read-only governance report' ) && false !== strpos( $wptl_channel_html, 'WPTL Channel Series' ), 'The selected-Series channel report did not render.' );
wptl_test_assert( false !== strpos( $wptl_channel_html, 'Exact public set' ) && false !== strpos( $wptl_channel_html, 'Partial overlap' ), 'The report did not explain exact and partial public entry points.' );
wptl_test_assert( false !== strpos( $wptl_channel_html, 'Multiple providers detected' ), 'The rendered report did not expose the current multi-provider ownership warning.' );
wptl_test_assert( false === stripos( $wptl_channel_html, '<form' ), 'The read-only channel report rendered a mutation form.' );
wptl_test_assert( false === strpos( $wptl_channel_html, 'channel-secret' ), 'The channel report exposed a post password.' );
wp_dequeue_style( 'wptl-channel-health' );
$GLOBALS['current_screen'] = $wptl_channel_old_screen;
remove_filter( 'wptl_channel_health_providers', $wptl_force_multiple_providers );

foreach ( array_merge( $wptl_channel_posts, array( $wptl_channel_partial_extra, $wptl_channel_draft, $wptl_channel_password ) ) as $wptl_channel_cleanup_post ) {
	if ( ! is_wp_error( $wptl_channel_cleanup_post ) && (int) $wptl_channel_cleanup_post > 0 ) {
		wp_delete_post( (int) $wptl_channel_cleanup_post, true );
	}
}
wp_delete_term( $wptl_channel_term_id, 'wptl_series' );
wp_delete_term( $wptl_channel_exact_id, 'category' );
wp_delete_term( $wptl_channel_partial_id, 'category' );
false === $wptl_old_rank_titles ? delete_option( 'rank-math-options-titles' ) : update_option( 'rank-math-options-titles', $wptl_old_rank_titles );
false === $wptl_old_rank_sitemap ? delete_option( 'rank-math-options-sitemap' ) : update_option( 'rank-math-options-sitemap', $wptl_old_rank_sitemap );
false === $wptl_old_rank_modules ? delete_option( 'rank_math_modules' ) : update_option( 'rank_math_modules', $wptl_old_rank_modules );
wp_set_current_user( $wptl_original_user_id );

/* Authors who can assign but not manage terms must still load public Series. */
update_term_meta( $wptl_auth_term_id, 'wptl_series_status', 'ongoing' );
$wptl_author_id = wp_insert_user(
	array(
		'user_login' => 'wptl_integration_author',
		'user_pass'  => wp_generate_password( 24, true, true ),
		'user_email' => 'wptl-integration-author@example.invalid',
		'role'       => 'author',
	)
);
wptl_test_assert( ! is_wp_error( $wptl_author_id ), 'Could not create the author fixture.' );
if ( ! is_wp_error( $wptl_author_id ) ) {
	wp_set_current_user( (int) $wptl_author_id );
	$wptl_author_taxonomy = get_taxonomy( 'wptl_series' );
	wptl_test_assert( $wptl_author_taxonomy && current_user_can( $wptl_author_taxonomy->cap->assign_terms ), 'The author fixture cannot assign Series terms.' );
	wptl_test_assert( $wptl_author_taxonomy && ! current_user_can( $wptl_author_taxonomy->cap->manage_terms ), 'The author fixture unexpectedly manages Series terms.' );
	$wptl_terms_request = new WP_REST_Request( 'GET', '/wp/v2/wptl_series' );
	$wptl_terms_request->set_param( 'context', 'view' );
	$wptl_terms_request->set_param( 'hide_empty', false );
	$wptl_terms_response = rest_do_request( $wptl_terms_request );
	wptl_test_same( 200, $wptl_terms_response->get_status(), 'An assign-only author could not load Series terms for the editor.' );
	$wptl_author_terms = (array) $wptl_terms_response->get_data();
	wptl_test_assert(
		in_array( $wptl_auth_term_id, array_map( 'absint', wp_list_pluck( $wptl_author_terms, 'id' ) ), true ),
		'The author-facing Series response omitted an existing unassigned term.'
	);
	$wptl_author_term_record = null;
	foreach ( $wptl_author_terms as $wptl_author_term ) {
		if ( $wptl_auth_term_id === (int) ( $wptl_author_term['id'] ?? 0 ) ) {
			$wptl_author_term_record = $wptl_author_term;
			break;
		}
	}
	wptl_test_same(
		'ongoing',
		(string) ( $wptl_author_term_record['meta']['wptl_series_status'] ?? '' ),
		'The public Series REST record did not expose its registered lifecycle status.'
	);
	wp_set_current_user( $wptl_original_user_id );
}

/* REST publication invariants for the ordered/seasoned Series mode. */
\WPTitleLayer\Core\Series::register_rest_validation_hooks();
$wptl_ordered_series_id = wptl_test_create_term( 'WPTL Ordered Seasoned Series', 'wptl_series' );
$wptl_second_series_id  = wptl_test_create_term( 'WPTL Second Series', 'wptl_series' );
update_term_meta( $wptl_ordered_series_id, 'wptl_series_mode', 'ordered' );
update_term_meta( $wptl_ordered_series_id, 'wptl_series_structure', 'seasoned' );
update_term_meta(
	$wptl_ordered_series_id,
	'wptl_seasons',
	array(
		array(
			'key'   => 'season-one',
			'label' => 'Season One',
			'sort'  => 10,
		),
	)
);

$wptl_validation = wptl_test_validate_rest_post(
	array(
		'status'      => 'draft',
		'wptl_series' => array( $wptl_ordered_series_id ),
		'meta'        => array(
			'wptl_series_role' => 'preface',
		),
	)
);
wptl_test_error_code(
	'wptl_invalid_series_role',
	$wptl_validation,
	'A draft REST request with an explicitly invalid non-empty Series role was not rejected.'
);

$wptl_validation = wptl_test_validate_rest_post(
	array(
		'status'      => 'publish',
		'wptl_series' => array( $wptl_ordered_series_id ),
		'meta'        => array(
			'wptl_sequence_position' => 1,
			'wptl_season_key'        => 'season-one',
			'wptl_series_role'       => '',
		),
	)
);
wptl_test_assert( ! is_wp_error( $wptl_validation ), 'Publishing with the legal empty main-article role was rejected.' );

$wptl_validation = wptl_test_validate_rest_post(
	array(
		'status'      => 'publish',
		'wptl_series' => array( $wptl_ordered_series_id ),
		'meta'        => array( 'wptl_season_key' => 'season-one' ),
	)
);
wptl_test_error_code(
	'wptl_missing_sequence_position',
	$wptl_validation,
	'Publishing in an ordered Series without a position was not rejected.'
);

$wptl_validation = wptl_test_validate_rest_post(
	array(
		'status'      => 'publish',
		'wptl_series' => array( $wptl_ordered_series_id ),
		'meta'        => array( 'wptl_sequence_position' => 1 ),
	)
);
wptl_test_error_code(
	'wptl_missing_season',
	$wptl_validation,
	'Publishing in a seasoned Series without a season was not rejected.'
);

$wptl_validation = wptl_test_validate_rest_post(
	array(
		'status'      => 'publish',
		'wptl_series' => array( $wptl_ordered_series_id ),
		'meta'        => array(
			'wptl_sequence_position' => 1,
			'wptl_season_key'        => 'undefined-season',
		),
	)
);
wptl_test_error_code(
	'wptl_invalid_season',
	$wptl_validation,
	'Publishing with an undefined season was not rejected.'
);

$wptl_validation = wptl_test_validate_rest_post(
	array(
		'status'      => 'publish',
		'wptl_series' => array( $wptl_ordered_series_id, $wptl_second_series_id ),
		'meta'        => array(),
	)
);
wptl_test_error_code(
	'wptl_multiple_series',
	$wptl_validation,
	'Publishing with more than one Series was not rejected.'
);

$wptl_position_owner_id = wptl_test_create_post( 'Existing Series position' );
wp_set_object_terms( $wptl_position_owner_id, array( $wptl_ordered_series_id ), 'wptl_series' );
update_post_meta( $wptl_position_owner_id, 'wptl_sequence_position', 7 );
update_post_meta( $wptl_position_owner_id, 'wptl_season_key', 'season-one' );
$wptl_validation = wptl_test_validate_rest_post(
	array(
		'status'      => 'publish',
		'wptl_series' => array( $wptl_ordered_series_id ),
		'meta'        => array(
			'wptl_sequence_position' => 7,
			'wptl_season_key'        => 'season-one',
		),
	)
);
wptl_test_error_code(
	'wptl_duplicate_sequence_position',
	$wptl_validation,
	'A duplicate position in the same Series and season was not rejected.'
);

$wptl_legacy_role_post_id = wptl_test_create_post( 'Legacy invalid Series role compatibility' );
wp_set_object_terms( $wptl_legacy_role_post_id, array( $wptl_ordered_series_id ), 'wptl_series' );
update_post_meta( $wptl_legacy_role_post_id, 'wptl_sequence_position', 8 );
update_post_meta( $wptl_legacy_role_post_id, 'wptl_season_key', 'season-one' );
$wpdb->insert(
	$wpdb->postmeta,
	array(
		'post_id'    => $wptl_legacy_role_post_id,
		'meta_key'   => 'wptl_series_role',
		'meta_value' => 'legacy-preface',
	),
	array( '%d', '%s', '%s' )
);
$wptl_validation = wptl_test_validate_rest_post(
	array(
		'id'     => $wptl_legacy_role_post_id,
		'status' => 'publish',
	)
);
wptl_test_assert( ! is_wp_error( $wptl_validation ), 'A REST update that omitted role stopped treating legacy invalid role data compatibly.' );
wp_delete_post( $wptl_legacy_role_post_id, true );

$wptl_sequence_context = ( new \WPTitleLayer\Presentation\Renderer() )->context( $wptl_position_owner_id );
wptl_test_same( 'season-one', $wptl_sequence_context['season_key'], 'The stable season key was not retained in presentation context.' );
wptl_test_same( 'Season One', $wptl_sequence_context['season'], 'The configured season label was not exposed to presentation.' );
wptl_test_same( 'Season One · 07', $wptl_sequence_context['sequence_display'], 'The public sequence display did not use the season label.' );
wptl_test_same( esc_url_raw( (string) get_term_link( $wptl_ordered_series_id, 'wptl_series' ) ), $wptl_sequence_context['series_url'], 'Presentation context did not expose the public Series archive URL.' );
$wptl_ordered_series_term = get_term( $wptl_ordered_series_id, 'wptl_series' );
$wptl_season_archive_url = add_query_arg(
	'wptl_season',
	'season-one',
	esc_url_raw( (string) get_term_link( $wptl_ordered_series_term ), array( 'http', 'https' ) )
);
wptl_test_same( $wptl_season_archive_url, $wptl_sequence_context['season_url'], 'Presentation context did not expose the filtered season archive URL.' );
wptl_test_same(
	$wptl_season_archive_url,
	\WPTitleLayer\Core\Series::public_season_archive_url( $wptl_ordered_series_term, 'season-one' ),
	'The shared season archive helper produced the wrong URL.'
);
$wptl_sequence_html = ( new \WPTitleLayer\Presentation\Renderer() )->render( $wptl_position_owner_id, array( 'preset' => 'standard' ) );
wptl_test_assert(
	false !== strpos( $wptl_sequence_html, '<a class="wptl-season-link" href="' . esc_url( $wptl_season_archive_url ) . '" rel="tag">Season One</a> · 07' ),
	'The single title context did not link only the season label to its filtered archive.'
);

/* Series icons are decorative title-context media with global and term-level controls. */
$wptl_icon_settings_before = get_option( 'wptl_settings', array() );
$wptl_icon_settings        = is_array( $wptl_icon_settings_before ) ? $wptl_icon_settings_before : array();
$wptl_icon_settings['presentation'] = isset( $wptl_icon_settings['presentation'] ) && is_array( $wptl_icon_settings['presentation'] )
	? $wptl_icon_settings['presentation']
	: array();
$wptl_icon_settings['presentation']['series_icons_enabled'] = false;
update_option( 'wptl_settings', $wptl_icon_settings );
$wptl_icon_upload = wp_upload_bits(
	'wptl-series-icon.png',
	null,
	base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true )
);
$wptl_icon_attachment_id = ! empty( $wptl_icon_upload['error'] )
	? new WP_Error( 'wptl_test_icon_upload_failed', (string) $wptl_icon_upload['error'] )
	: wp_insert_attachment(
		array(
			'post_title'     => 'WPTL decorative Series icon',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/png',
		),
		(string) $wptl_icon_upload['file'],
		$wptl_position_owner_id,
		true
	);
wptl_test_assert( ! is_wp_error( $wptl_icon_attachment_id ), 'Could not create the decorative Series icon fixture.' );
$wptl_icon_attachment_id = is_wp_error( $wptl_icon_attachment_id ) ? 0 : (int) $wptl_icon_attachment_id;
$wptl_icon_source_url = 'https://example.test/wptl-series-icon.png';
$wptl_icon_downsize = static function ( $downsize, $attachment_id ) use ( $wptl_icon_attachment_id, &$wptl_icon_source_url ) {
	return $wptl_icon_attachment_id === (int) $attachment_id
		? array( $wptl_icon_source_url, 32, 32, true )
		: $downsize;
};
add_filter( 'image_downsize', $wptl_icon_downsize, 10, 2 );
update_term_meta( $wptl_ordered_series_id, 'wptl_icon_id', $wptl_icon_attachment_id );
$wptl_ordered_series_term = get_term( $wptl_ordered_series_id, 'wptl_series' );
wptl_test_assert( ! \WPTitleLayer\Presentation\SettingsPage::seriesIconEnabled( $wptl_ordered_series_term ), 'Series icons were not off by default.' );
wptl_test_assert(
	false === strpos( ( new \WPTitleLayer\Presentation\Renderer() )->render( $wptl_position_owner_id, array( 'preset' => 'standard' ) ), 'wptl-series-icon' ),
	'An inherited Series icon appeared while the global setting was off.'
);
update_term_meta( $wptl_ordered_series_id, 'wptl_title_icon', 'show' );
$wptl_icon_renderer = new \WPTitleLayer\Presentation\Renderer();
$wptl_icon_html     = $wptl_icon_renderer->render( $wptl_position_owner_id, array( 'preset' => 'standard' ) );
wptl_test_assert(
	false !== strpos( $wptl_icon_html, '<span class="wptl-series-icon" aria-hidden="true"><img class="wptl-series-icon__image"' )
	&& false !== strpos( $wptl_icon_html, 'alt=""' ),
	'A per-Series show override did not render one decorative, empty-alt icon.'
);
wptl_test_assert( 1 === preg_match( '/<h1\b[^>]*>(.*?)<\/h1>/is', $wptl_icon_html, $wptl_icon_heading ), 'The icon fixture lost its primary heading.' );
wptl_test_assert( false === strpos( $wptl_icon_heading[1], '<img' ), 'The decorative Series icon entered the article H1.' );
$wptl_icon_fragments = $wptl_icon_renderer->themeTitleFragments( $wptl_position_owner_id );
wptl_test_assert( false !== strpos( $wptl_icon_fragments['before'], 'wptl-series-icon' ), 'Classic theme-title takeover lost the decorative Series icon.' );
wptl_test_assert( false === strpos( $wptl_icon_fragments['title'], '<img' ), 'Classic theme-title takeover placed the Series icon inside the native heading.' );
wptl_test_same( 'Existing Series position', $wptl_icon_renderer->value( 'title', $wptl_position_owner_id ), 'The decorative icon changed the semantic article title.' );
wptl_test_same( 'WPTL Ordered Seasoned Series', \WPTitleLayer\Integrations\RankMath::series( array(), get_post( $wptl_position_owner_id ) ), 'The decorative icon entered the Rank Math Series variable.' );
$wptl_icon_source_url = 'javascript:alert(1)';
$wptl_unsafe_icon_html = $wptl_icon_renderer->render( $wptl_position_owner_id, array( 'preset' => 'standard' ) );
wptl_test_assert( false === strpos( $wptl_unsafe_icon_html, 'wptl-series-icon' ) && false === stripos( $wptl_unsafe_icon_html, 'javascript:' ), 'A filtered non-HTTP(S) Series icon URL survived rendering.' );
$wptl_icon_source_url = 'https://example.test/wptl-series-icon.png';

update_term_meta( $wptl_ordered_series_id, 'wptl_title_icon', 'inherit' );
$wptl_icon_settings['presentation']['series_icons_enabled'] = true;
update_option( 'wptl_settings', $wptl_icon_settings );
wptl_test_assert(
	false !== strpos( $wptl_icon_renderer->render( $wptl_position_owner_id, array( 'preset' => 'standard' ) ), 'wptl-series-icon' ),
	'An inherited Series icon ignored the enabled global setting.'
);
update_term_meta( $wptl_ordered_series_id, 'wptl_title_icon', 'hide' );
wptl_test_assert(
	false === strpos( $wptl_icon_renderer->render( $wptl_position_owner_id, array( 'preset' => 'standard' ) ), 'wptl-series-icon' ),
	'A per-Series hide override did not suppress the globally enabled icon.'
);
remove_filter( 'image_downsize', $wptl_icon_downsize, 10 );
delete_term_meta( $wptl_ordered_series_id, 'wptl_icon_id' );
delete_term_meta( $wptl_ordered_series_id, 'wptl_title_icon' );
if ( 0 < $wptl_icon_attachment_id ) {
	wp_delete_attachment( $wptl_icon_attachment_id, true );
}
update_option( 'wptl_settings', $wptl_icon_settings_before );
wptl_test_same(
	esc_url_raw( (string) get_term_link( $wptl_ordered_series_term ), array( 'http', 'https' ) ),
	\WPTitleLayer\Core\Series::public_archive_url( $wptl_ordered_series_term ),
	'The shared Series archive helper rejected a public claimed term.'
);

$wptl_private_link_taxonomy = 'wptl_private_link_test';
register_taxonomy(
	$wptl_private_link_taxonomy,
	array( 'post' ),
	array(
		'public'             => false,
		'publicly_queryable' => false,
	)
);
$wptl_private_link_term_id = wptl_test_create_term( 'Private link Series', $wptl_private_link_taxonomy );
$wptl_private_link_term    = get_term( $wptl_private_link_term_id, $wptl_private_link_taxonomy );
$wptl_private_link_context = $wptl_private_link_term instanceof WP_Term
	? ( new \WPTitleLayer\Presentation\Renderer() )->context( $wptl_position_owner_id, array( 'series_term' => $wptl_private_link_term ) )
	: array( 'series' => '', 'series_url' => 'unexpected' );
wptl_test_same( 'Private link Series', $wptl_private_link_context['series'], 'A private Series term lost its plain-text presentation context.' );
wptl_test_same( '', $wptl_private_link_context['series_url'], 'A non-public Series taxonomy received a front-end archive link.' );
wptl_test_same( '', \WPTitleLayer\Core\Series::public_archive_url( $wptl_private_link_term ), 'An unclaimed private taxonomy received a shared Series archive URL.' );
wp_delete_term( $wptl_private_link_term_id, $wptl_private_link_taxonomy );
unregister_taxonomy( $wptl_private_link_taxonomy );

/* Presentation precedence: global < category < Series < article override. */
$wptl_category_id = wptl_test_create_term( 'WPTL Presentation Category', 'category' );
$wptl_series_id   = wptl_test_create_term( 'WPTL Presentation Series', 'wptl_series' );
$wptl_post_id     = wptl_test_create_post( 'Layered title' );

update_option(
	'wptl_settings',
	array(
		'presentation' => array(
			'default_template' => 'minimal',
			'category_rules'   => array(
				$wptl_category_id => 'inline',
			),
		),
	)
);

$wptl_resolver = new \WPTitleLayer\Presentation\TemplateResolver();
$wptl_renderer = new \WPTitleLayer\Presentation\Renderer( $wptl_resolver );

$wptl_resolution = $wptl_resolver->resolve( $wptl_post_id );
wptl_test_same( 'minimal', $wptl_resolution['preset'], 'The global template was not used as the base fallback.' );
wptl_test_same( 'global', $wptl_resolution['source'], 'The global resolution source was not reported.' );

wp_set_post_categories( $wptl_post_id, array( $wptl_category_id ) );
$wptl_resolution = $wptl_resolver->resolve( $wptl_post_id );
wptl_test_same( 'inline', $wptl_resolution['preset'], 'The category rule did not override the global template.' );
wptl_test_same( 'category', $wptl_resolution['source'], 'The category resolution source was not reported.' );

wp_set_object_terms( $wptl_post_id, array( $wptl_series_id ), 'wptl_series' );
update_term_meta( $wptl_series_id, 'wptl_default_template', 'editorial' );
$wptl_resolution = $wptl_resolver->resolve( $wptl_post_id );
wptl_test_same( 'editorial', $wptl_resolution['preset'], 'The Series default did not override the category rule.' );
wptl_test_same( 'series', $wptl_resolution['source'], 'The Series resolution source was not reported.' );

update_post_meta( $wptl_post_id, 'wptl_template_override', 'standard' );
$wptl_series_link_url    = esc_url( (string) get_term_link( $wptl_series_id, 'wptl_series' ) );
$wptl_series_link_markup = '<a class="wptl-series-link" href="' . $wptl_series_link_url . '" rel="tag">WPTL Presentation Series</a>';
update_term_meta( $wptl_series_id, 'wptl_series_mode', 'ordered' );
update_post_meta( $wptl_post_id, 'wptl_sequence_position', 5 );
$wptl_resolution = $wptl_resolver->resolve( $wptl_post_id );
wptl_test_same( 'standard', $wptl_resolution['preset'], 'The article template did not override the Series default.' );
wptl_test_same( 'post', $wptl_resolution['source'], 'The article resolution source was not reported.' );
$wptl_linked_render = $wptl_renderer->render( $wptl_post_id );
wptl_test_assert(
	false !== strpos( $wptl_linked_render, 'wptl-title-layer--standard' ),
	'The renderer did not use the resolved article template.'
);
wptl_test_assert( false !== strpos( $wptl_linked_render, $wptl_series_link_markup . ' · 05' ), 'The full renderer did not link only the Series label before its sequence text.' );
wptl_test_assert( 1 === preg_match( '/<h1\b[^>]*>(.*?)<\/h1>/is', $wptl_linked_render, $wptl_linked_heading ), 'The linked Series fixture lost its primary heading.' );
wptl_test_assert( false === strpos( $wptl_linked_heading[1], '<a ' ), 'The Series archive link was placed inside the primary title heading.' );

$wptl_shortcode_linked = ( new \WPTitleLayer\Presentation\Shortcodes( $wptl_renderer ) )->titleLayer( array( 'post_id' => $wptl_post_id ) );
wptl_test_assert( false !== strpos( $wptl_shortcode_linked, $wptl_series_link_markup . ' · 05' ), 'The Title Layer shortcode did not use the shared Series archive link rendering.' );
$wptl_block_linked = ( new \WPTitleLayer\Presentation\DynamicBlock( $wptl_renderer ) )->render( array( 'postId' => $wptl_post_id ) );
wptl_test_assert( false !== strpos( $wptl_block_linked, $wptl_series_link_markup . ' · 05' ), 'The Title Layer block did not use the shared Series archive link rendering.' );

$wptl_custom_eyebrow_filter = static function ( $context, $post_id ) use ( $wptl_post_id ) {
	if ( $wptl_post_id === (int) $post_id ) {
		$context['eyebrow'] = 'Custom filtered context';
	}
	return $context;
};
add_filter( 'wptl_title_context', $wptl_custom_eyebrow_filter, 10, 2 );
$wptl_custom_eyebrow_html = $wptl_renderer->render( $wptl_post_id );
remove_filter( 'wptl_title_context', $wptl_custom_eyebrow_filter, 10 );
wptl_test_assert( false !== strpos( $wptl_custom_eyebrow_html, 'Custom filtered context' ), 'A filtered custom eyebrow was not retained.' );
wptl_test_assert( false === strpos( $wptl_custom_eyebrow_html, 'wptl-series-link' ), 'A filtered custom eyebrow was incorrectly split into a Series archive link.' );

update_post_meta( $wptl_post_id, 'wptl_kicker_override', 'Article-specific kicker' );
$wptl_override_kicker_context = $wptl_renderer->context( $wptl_post_id, $wptl_resolution );
$wptl_override_kicker_html    = $wptl_renderer->render( $wptl_post_id );
wptl_test_same( 'override', $wptl_override_kicker_context['kicker_source'], 'The article kicker override did not retain its non-Series source.' );
wptl_test_assert( false !== strpos( $wptl_override_kicker_html, 'Article-specific kicker · 05' ), 'The article kicker override lost its sequence context.' );
wptl_test_assert( false === strpos( $wptl_override_kicker_html, 'wptl-series-link' ), 'An article-specific kicker was incorrectly linked to the Series archive.' );
delete_post_meta( $wptl_post_id, 'wptl_kicker_override' );

$wptl_non_http_context_link = static function ( $context, $post_id ) use ( $wptl_post_id ) {
	if ( $wptl_post_id === (int) $post_id ) {
		$context['series_url'] = 'ftp://example.test/not-a-series-archive';
	}
	return $context;
};
add_filter( 'wptl_title_context', $wptl_non_http_context_link, 10, 2 );
$wptl_non_http_context = $wptl_renderer->context( $wptl_post_id, $wptl_resolution );
$wptl_non_http_html    = $wptl_renderer->render( $wptl_post_id );
remove_filter( 'wptl_title_context', $wptl_non_http_context_link, 10 );
wptl_test_same( '', $wptl_non_http_context['series_url'], 'A non-HTTP(S) filtered Series context URL survived normalization.' );
wptl_test_assert( false === strpos( $wptl_non_http_html, 'wptl-series-link' ), 'A non-HTTP(S) filtered Series context URL produced a front-end link.' );

$wptl_hostile_term_link_filter = static function ( $url, $term ) use ( $wptl_series_id ) {
	return $term instanceof WP_Term && $wptl_series_id === (int) $term->term_id ? 'javascript:alert(1)' : $url;
};
add_filter( 'term_link', $wptl_hostile_term_link_filter, 10, 2 );
$wptl_hostile_link_context = $wptl_renderer->context( $wptl_post_id, $wptl_resolution );
$wptl_hostile_link_html    = $wptl_renderer->render( $wptl_post_id );
remove_filter( 'term_link', $wptl_hostile_term_link_filter, 10 );
wptl_test_same( '', $wptl_hostile_link_context['series_url'], 'An unsafe filtered term URL survived context normalization.' );
wptl_test_assert( false === stripos( $wptl_hostile_link_html, 'javascript:' ) && false === strpos( $wptl_hostile_link_html, 'wptl-series-link' ), 'An invalid Series term URL produced a front-end link.' );
wptl_test_assert( false !== strpos( $wptl_hostile_link_html, 'WPTL Presentation Series · 05' ), 'An invalid Series term URL removed the plain-text Series context.' );

update_post_meta( $wptl_post_id, 'wptl_template_override', 'disabled' );
$wptl_resolution = $wptl_resolver->resolve( $wptl_post_id );
wptl_test_assert( ! empty( $wptl_resolution['disabled'] ), 'The article-level disabled state was not resolved.' );
wptl_test_same( '', $wptl_renderer->render( $wptl_post_id ), 'Disabled article output should be empty.' );

/* Built-in template customization is structured, sanitized, and semantic. */
$wptl_customization_settings_before = get_option( 'wptl_settings', array() );
$wptl_customized_post_id = wptl_test_create_post( 'Customized primary title' );
$wptl_customized_series_id = wptl_test_create_term( 'Hidden customized Series', 'wptl_series' );
wp_set_object_terms( $wptl_customized_post_id, array( $wptl_customized_series_id ), 'wptl_series' );
update_post_meta( $wptl_customized_post_id, 'wptl_subtitle', 'Customized subtitle' );

/* Inline keeps semantic Series context while placing a short subtitle beside the title. */
update_option(
	'wptl_settings',
	array(
		'presentation' => array(
			'default_template' => 'inline',
		),
	)
);
$wptl_inline_default_renderer = new \WPTitleLayer\Presentation\Renderer();
$wptl_inline_default_html = $wptl_inline_default_renderer->render( $wptl_customized_post_id );
wptl_test_assert( false !== strpos( $wptl_inline_default_html, 'Hidden customized Series' ), 'Inline default removed Series context from a Series article.' );
wptl_test_assert( false !== strpos( $wptl_inline_default_html, '</h1><span class="wptl-title-separator">: </span><span class="wptl-subtitle">Customized subtitle</span>' ), 'Inline default did not use the English-locale separator beside the title.' );

/* With no recipe override, Standard keeps the established 0.2.1 structure. */
update_option(
	'wptl_settings',
	array(
		'presentation' => array(
			'default_template' => 'standard',
			'preset_customizations' => array(
				'inline' => array(
					'series_position'   => 'above',
					'subtitle_position' => 'hidden',
					'separator_style'   => 'pipe',
					'custom_separator'  => '',
				),
			),
		),
	)
);
$wptl_customized_renderer = new \WPTitleLayer\Presentation\Renderer();
$wptl_baseline_html = $wptl_customized_renderer->render( $wptl_customized_post_id );
wptl_test_assert( false !== strpos( $wptl_baseline_html, '<header class="wptl-title-layer wptl-title-layer--standard">' ), 'Standard baseline lost its established wrapper.' );
wptl_test_assert( false !== strpos( $wptl_baseline_html, '<h1 class="wptl-title wptl-title-heading">Customized primary title</h1>' ), 'Standard baseline changed its established title markup.' );
wptl_test_assert( false !== strpos( $wptl_baseline_html, '<p class="wptl-subtitle">Customized subtitle</p>' ), 'Standard baseline changed its stacked subtitle markup.' );

$wptl_customized_settings = \WPTitleLayer\Presentation\SettingsPage::sanitizeSettings(
	array(
		'presentation' => array(
			'default_template' => 'standard',
			'preset_customizations' => array(
				'standard' => array(
					'series_position'   => 'hidden',
					'subtitle_position' => 'inline',
					'separator_style'  => 'custom',
					'custom_separator' => '123456789',
				),
			),
		),
	)
);
$wptl_standard_recipe = $wptl_customized_settings['presentation']['preset_customizations']['standard'];
wptl_test_same( 1, (int) $wptl_customized_settings['presentation']['preset_schema_version'], 'Template recipe schema version was not frozen.' );
wptl_test_same( 'hidden', $wptl_standard_recipe['series_position'], 'A hidden Series recipe was not saved.' );
wptl_test_same( 'inline', $wptl_standard_recipe['subtitle_position'], 'An inline subtitle recipe was not saved.' );
wptl_test_same( '12345678', $wptl_standard_recipe['custom_separator'], 'A custom separator was not limited to eight plain-text characters.' );
wptl_test_same( 'hidden', $wptl_customized_settings['presentation']['preset_customizations']['inline']['subtitle_position'], 'A partial recipe save erased another preset override.' );
wptl_test_same(
	\WPTitleLayer\Presentation\Presets::defaultRecipe( 'standard' ),
	\WPTitleLayer\Presentation\Presets::normalizeRecipe(
		'standard',
		array(
			'series_position'   => 'sideways',
			'subtitle_position' => 'inline',
			'separator_style'   => 'custom',
			'custom_separator'  => 'unsafe',
		)
	),
	'One invalid recipe enum did not reset the complete record to defaults.'
);
update_option( 'wptl_settings', $wptl_customized_settings );

$wptl_truncated_recipe_save = \WPTitleLayer\Presentation\SettingsPage::sanitizeSettings(
	array(
		'presentation' => array(
			'default_template' => 'standard',
			'preset_customizations' => array(
				'standard' => array( 'separator_style' => 'pipe' ),
			),
		),
	)
);
wptl_test_same( 'hidden', $wptl_truncated_recipe_save['presentation']['preset_customizations']['standard']['series_position'], 'A truncated recipe request erased the stored Series choice.' );
wptl_test_same( 'inline', $wptl_truncated_recipe_save['presentation']['preset_customizations']['standard']['subtitle_position'], 'A truncated recipe request erased the stored subtitle choice.' );
wptl_test_same( $wptl_customized_settings['presentation']['preset_customizations']['standard'], $wptl_truncated_recipe_save['presentation']['preset_customizations']['standard'], 'A truncated recipe request changed part of the stored recipe.' );
$wptl_settings_reset_recipe = \WPTitleLayer\Presentation\SettingsPage::sanitizeSettings(
	array(
		'presentation' => array(
			'default_template' => 'standard',
			'preset_customizations' => array(
				'standard' => array( '_reset' => 1 ),
			),
		),
	)
);
wptl_test_assert( ! isset( $wptl_settings_reset_recipe['presentation']['preset_customizations']['standard'] ), 'The real settings save path did not reset Standard.' );
wptl_test_same( $wptl_customized_settings['presentation']['preset_customizations']['inline'], $wptl_settings_reset_recipe['presentation']['preset_customizations']['inline'], 'Resetting Standard changed the saved Inline recipe.' );

$wptl_customized_html = $wptl_customized_renderer->render( $wptl_customized_post_id );
wptl_test_assert( false === strpos( $wptl_customized_html, 'Hidden customized Series' ), 'A hidden Series line remained visible.' );
wptl_test_same( 1, substr_count( strtolower( $wptl_customized_html ), '<h1' ), 'Template customization created more than one primary heading.' );
wptl_test_assert( 1 === preg_match( '/<h1\b[^>]*>(.*?)<\/h1>/is', $wptl_customized_html, $wptl_customized_heading ), 'Customized output lost its primary heading.' );
wptl_test_assert( false !== strpos( $wptl_customized_heading[1], 'Customized primary title' ), 'The primary heading lost the WordPress title.' );
wptl_test_assert( false === strpos( $wptl_customized_heading[1], 'Customized subtitle' ), 'An inline subtitle was placed inside the primary heading.' );
wptl_test_assert( false !== strpos( $wptl_customized_html, '</h1><span class="wptl-title-separator">12345678</span>' ), 'The safe custom separator was not rendered beside the heading.' );
wptl_test_assert( false === stripos( $wptl_customized_html, '<script' ), 'Template customization rendered submitted markup.' );

$wptl_customized_fragments = $wptl_customized_renderer->themeTitleFragments( $wptl_customized_post_id );
wptl_test_assert( false === strpos( $wptl_customized_fragments['title'], 'Customized subtitle' ), 'Classic-title customization placed the subtitle inside the native heading.' );
wptl_test_assert( false !== strpos( $wptl_customized_fragments['after'], '12345678' ), 'Classic-title customization lost its inline separator.' );
wptl_test_assert( false !== strpos( $wptl_customized_fragments['after'], 'Customized subtitle' ), 'Classic-title customization lost its subtitle sibling.' );

$wptl_reset_customizations = \WPTitleLayer\Presentation\Presets::sanitizeRecipeOverrides(
	array(
		'standard' => array( '_reset' => 1 ),
		'inline'   => array(
			'series_position'   => 'above',
			'subtitle_position' => 'hidden',
			'separator_style'   => 'pipe',
			'custom_separator'  => '',
		),
	)
);
wptl_test_assert( ! isset( $wptl_reset_customizations['standard'] ), 'Restoring Standard left a stored override behind.' );
wptl_test_same( 'hidden', $wptl_reset_customizations['inline']['subtitle_position'], 'Restoring one preset overwrote another preset recipe.' );

update_option(
	'wptl_settings',
	array(
		'presentation' => array(
			'default_template'      => 'standard',
			'preset_schema_version' => 999,
			'preset_customizations' => array(
				'standard' => array(
					'series_position'   => 'hidden',
					'subtitle_position' => 'hidden',
					'separator_style'   => 'pipe',
					'custom_separator'  => '',
				),
			),
		),
	)
);
$wptl_unknown_recipe_html = $wptl_customized_renderer->render( $wptl_customized_post_id );
wptl_test_assert( false !== strpos( $wptl_unknown_recipe_html, 'Hidden customized Series' ), 'An unknown template recipe version hid the default Series context.' );
wptl_test_assert( false !== strpos( $wptl_unknown_recipe_html, 'Customized subtitle' ), 'An unknown template recipe version hid the default subtitle.' );
$wptl_future_recipe_settings = get_option( 'wptl_settings', array() );
$wptl_future_recipe_resave = \WPTitleLayer\Presentation\SettingsPage::sanitizeSettings(
	array(
		'presentation' => array(
			'default_template' => 'editorial',
			'preset_customizations' => array(
				'standard' => \WPTitleLayer\Presentation\Presets::defaultRecipe( 'standard' ),
			),
		),
	)
);
wptl_test_same( 999, $wptl_future_recipe_resave['presentation']['preset_schema_version'], 'An unrelated settings save overwrote a future recipe schema version.' );
wptl_test_same( $wptl_future_recipe_settings['presentation']['preset_customizations'], $wptl_future_recipe_resave['presentation']['preset_customizations'], 'An unrelated settings save erased future-version recipe data.' );
wptl_test_assert(
	! \WPTitleLayer\Presentation\Presets::hasSupportedRecipeVersion( array( 'preset_schema_version' => array( 1 ) ) ),
	'A malformed recipe version was accepted as the current schema.'
);
wptl_test_same(
	\WPTitleLayer\Presentation\Presets::defaultRecipe( 'standard' ),
	\WPTitleLayer\Presentation\Presets::normalizeRecipe(
		'standard',
		array(
			'series_position'   => 'above',
			'subtitle_position' => 'inline',
			'separator_style'   => 'custom',
			'custom_separator'  => '<script>alert(1)</script>',
		)
	),
	'A marked-up custom separator did not fail closed to the template defaults.'
);

wp_delete_post( $wptl_customized_post_id, true );
wp_delete_term( $wptl_customized_series_id, 'wptl_series' );
update_option( 'wptl_settings', $wptl_customization_settings_before );

/* Legacy read-through is allowed only until the canonical key exists. */
$wptl_legacy_post_id = wptl_test_create_post( 'Legacy read-through' );
update_post_meta( $wptl_legacy_post_id, '_secondary_title', 'Legacy subtitle' );
wptl_test_same(
	'Legacy subtitle',
	$wptl_renderer->value( 'subtitle', $wptl_legacy_post_id ),
	'The renderer did not fall back to legacy subtitle data.'
);
wptl_test_same(
	'[Legacy subtitle]',
	get_secondary_title( $wptl_legacy_post_id, '[', ']' ),
	'The compatibility API did not use legacy data or preserve affixes.'
);

add_post_meta( $wptl_legacy_post_id, 'wptl_subtitle', '', true );
wptl_test_same(
	'',
	$wptl_renderer->value( 'subtitle', $wptl_legacy_post_id ),
	'An intentionally empty canonical subtitle did not remain authoritative.'
);
wptl_test_same(
	'',
	get_secondary_title( $wptl_legacy_post_id ),
	'The compatibility API revived legacy data after the canonical key existed.'
);
wp_delete_post( $wptl_legacy_post_id, true );

/* Explicit IDs must not expose unpublished content to signed-out visitors. */
$wptl_draft_id   = wptl_test_create_post( 'Hidden draft title' );
$wptl_private_id = wptl_test_create_post( 'Hidden private title' );
$wptl_password_id = wptl_test_create_post( 'Hidden password title' );
wp_update_post( array( 'ID' => $wptl_draft_id, 'post_status' => 'draft' ) );
wp_update_post( array( 'ID' => $wptl_private_id, 'post_status' => 'private' ) );
wp_update_post( array( 'ID' => $wptl_password_id, 'post_password' => 'secret' ) );
update_post_meta( $wptl_draft_id, 'wptl_subtitle', 'Hidden draft subtitle' );
update_post_meta( $wptl_private_id, 'wptl_subtitle', 'Hidden private subtitle' );
update_post_meta( $wptl_password_id, 'wptl_subtitle', 'Hidden password subtitle' );

$wptl_before_visitor = get_current_user_id();
wp_set_current_user( 0 );
foreach ( array( $wptl_draft_id, $wptl_private_id, $wptl_password_id ) as $wptl_hidden_post_id ) {
	wptl_test_same(
		'',
		do_shortcode( '[wp_title_layer post_id="' . $wptl_hidden_post_id . '"]' ),
		'The title-layer shortcode exposed an unpublished post to a visitor.'
	);
	wptl_test_same(
		'',
		do_shortcode( '[wptl_subtitle post_id="' . $wptl_hidden_post_id . '"]' ),
		'The subtitle shortcode exposed an unpublished post to a visitor.'
	);
}
wp_set_current_user( $wptl_before_visitor );

/* An ACF field-key reference in the legacy meta key is never public content. */
$wptl_acf_collision_id = wptl_test_create_post( 'ACF collision title' );
update_post_meta( $wptl_acf_collision_id, '_secondary_title', 'field_not_public_content' );
wptl_test_same( '', $wptl_renderer->value( 'subtitle', $wptl_acf_collision_id ), 'Renderer exposed an ACF field-key reference as a subtitle.' );
wptl_test_same( '', do_shortcode( '[wptl_subtitle post_id="' . $wptl_acf_collision_id . '"]' ), 'Subtitle shortcode exposed an ACF field-key reference.' );
delete_post_meta( $wptl_acf_collision_id, '_secondary_title' );

/* Opt-in automatic placement is singular-only, non-H1, and duplicate-safe. */
$wptl_auto_post_id = wptl_test_create_post( 'Automatic title layer' );
update_post_meta( $wptl_auto_post_id, 'wptl_subtitle', 'Automatic subtitle' );
$wptl_auto_old_settings = get_option( 'wptl_settings', array() );
update_option(
	'wptl_settings',
	array(
		'presentation' => array(
			'default_template'          => 'standard',
			'display_mode'              => 'auto-prepend',
			'auto_post_types'           => array( 'post' ),
			'auto_heading_tag'          => 'div',
			'auto_theme_title_disabled' => true,
		),
	)
);
$wptl_auto_old_query     = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;
$wptl_auto_old_the_query = isset( $GLOBALS['wp_the_query'] ) ? $GLOBALS['wp_the_query'] : null;
$wptl_auto_old_post      = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
$wptl_auto_query         = new WP_Query();
$wptl_auto_query->is_singular       = true;
$wptl_auto_query->is_single         = true;
$wptl_auto_query->in_the_loop       = true;
$wptl_auto_query->queried_object    = get_post( $wptl_auto_post_id );
$wptl_auto_query->queried_object_id = $wptl_auto_post_id;
$GLOBALS['wp_query']                 = $wptl_auto_query;
$GLOBALS['wp_the_query']             = $wptl_auto_query;
$GLOBALS['post']                     = get_post( $wptl_auto_post_id );

$wptl_auto = new \WPTitleLayer\Presentation\AutoDisplay();
wptl_test_assert( $wptl_auto->shouldInject( $wptl_auto_post_id ), 'Automatic placement rejected an eligible singular main-loop post.' );

$wptl_future_id = wp_insert_post(
	array(
		'post_title'    => 'Hidden future auto title',
		'post_content'  => 'Future body',
		'post_status'   => 'future',
		'post_date'     => wp_date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
		'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
	)
);
wptl_test_assert( is_int( $wptl_future_id ) && $wptl_future_id > 0, 'Could not create the future-post automatic-placement fixture.' );
foreach ( array( $wptl_draft_id, $wptl_private_id, $wptl_future_id ) as $wptl_unpublished_auto_id ) {
	$wptl_auto_query->queried_object    = get_post( $wptl_unpublished_auto_id );
	$wptl_auto_query->queried_object_id = $wptl_unpublished_auto_id;
	$GLOBALS['post']                     = get_post( $wptl_unpublished_auto_id );
	wptl_test_assert( ! $wptl_auto->shouldInject( $wptl_unpublished_auto_id ), 'Automatic placement appeared in an unpublished post preview.' );
}
$wptl_auto_query->queried_object    = get_post( $wptl_auto_post_id );
$wptl_auto_query->queried_object_id = $wptl_auto_post_id;
$GLOBALS['post']                     = get_post( $wptl_auto_post_id );

$wptl_auto_html = $wptl_auto->prependToContent( '<p>Article body</p>' );
wptl_test_assert( false !== strpos( $wptl_auto_html, 'wptl-auto-prepend' ), 'Automatic placement did not prepend a rendered title layer.' );
wptl_test_assert( false === stripos( $wptl_auto_html, '<h1' ), 'Automatic placement introduced an H1.' );
wptl_test_same( '<p>Article body</p>', $wptl_auto->prependToContent( '<p>Article body</p>' ), 'Automatic placement injected the same post twice in one request.' );
foreach ( array( '[wp_title_layer]', '[wptl_subtitle]', '[secondary_title]' ) as $wptl_manual_markup ) {
	wptl_test_assert(
		\WPTitleLayer\Presentation\AutoDisplay::containsManualPlacement( $wptl_manual_markup ),
		'Automatic placement did not yield to an explicit Title Layer shortcode.'
	);
}
$wptl_auto_query->is_feed = true;
wptl_test_assert( ! $wptl_auto->shouldInject( $wptl_auto_post_id ), 'Automatic placement leaked into a feed request.' );
$wptl_auto_query->is_feed = false;
wp_update_post( array( 'ID' => $wptl_auto_post_id, 'post_password' => 'secret' ) );
wptl_test_assert( ! $wptl_auto->shouldInject( $wptl_auto_post_id ), 'Automatic placement exposed a password-protected post.' );

$GLOBALS['wp_query']     = $wptl_auto_old_query;
$GLOBALS['wp_the_query'] = $wptl_auto_old_the_query;
$GLOBALS['post']         = $wptl_auto_old_post;
update_option( 'wptl_settings', $wptl_auto_old_settings );

/* Theme-title takeover replaces an exact title slot and shows Series context. */
$wptl_takeover_old_settings = get_option( 'wptl_settings', array() );
$wptl_takeover_post_id      = wptl_test_create_post( 'Takeover primary title' );
$wptl_takeover_term_id      = wptl_test_create_term( 'A Long Series Name', 'wptl_series' );
wp_set_object_terms( $wptl_takeover_post_id, array( $wptl_takeover_term_id ), 'wptl_series' );
update_term_meta( $wptl_takeover_term_id, 'wptl_short_label', 'Short Series' );
update_term_meta( $wptl_takeover_term_id, 'wptl_series_mode', 'ordered' );
update_post_meta( $wptl_takeover_post_id, 'wptl_sequence_position', 3 );
update_post_meta( $wptl_takeover_post_id, 'wptl_subtitle', 'Takeover subtitle' );
update_option(
	'wptl_settings',
	array(
		'presentation' => array(
			'default_template'          => 'standard',
			'display_mode'              => 'replace-theme-title',
			'auto_post_types'           => array( 'post' ),
			'auto_heading_tag'          => 'div',
			'auto_theme_title_disabled' => false,
		),
	)
);

$wptl_takeover_old_query     = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;
$wptl_takeover_old_the_query = isset( $GLOBALS['wp_the_query'] ) ? $GLOBALS['wp_the_query'] : null;
$wptl_takeover_old_post      = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
$wptl_takeover_query         = new WP_Query();
$wptl_takeover_query->is_singular       = true;
$wptl_takeover_query->is_single         = true;
$wptl_takeover_query->queried_object    = get_post( $wptl_takeover_post_id );
$wptl_takeover_query->queried_object_id = $wptl_takeover_post_id;
$GLOBALS['wp_query']                     = $wptl_takeover_query;
$GLOBALS['wp_the_query']                 = $wptl_takeover_query;
$GLOBALS['post']                         = get_post( $wptl_takeover_post_id );

$wptl_theme_title = new \WPTitleLayer\Presentation\ThemeTitleIntegration();
wptl_test_assert( $wptl_theme_title->isEligible( $wptl_takeover_post_id ), 'Theme-title takeover rejected an eligible Series article.' );
$wptl_takeover_block = array(
	'blockName'    => 'core/post-title',
	'attrs'        => array( 'level' => 1 ),
	'innerBlocks'  => array(),
	'innerHTML'    => '',
	'innerContent' => array(),
);
$wptl_takeover_instance = new WP_Block(
	$wptl_takeover_block,
	array(
		'postId'   => $wptl_takeover_post_id,
		'postType' => 'post',
		'queryId'  => 0,
	)
);
$wptl_original_title_block = '<h1 id="takeover-title" class="wp-block-post-title has-large-font-size" style="text-align:center">Original title</h1>';
$wptl_takeover_html = $wptl_theme_title->replaceBlockTitle( $wptl_original_title_block, $wptl_takeover_block, $wptl_takeover_instance );
wptl_test_assert( false !== strpos( $wptl_takeover_html, 'wptl-theme-title-replacement' ), 'The queried Post Title block was not replaced in place.' );
wptl_test_assert( false !== strpos( $wptl_takeover_html, 'Short Series' ), 'The Series short label was not displayed in the title layer.' );
wptl_test_assert( false !== strpos( $wptl_takeover_html, '03' ), 'The ordered Series position was not displayed in the title layer.' );
wptl_test_same( 1, substr_count( strtolower( $wptl_takeover_html ), '<h1' ), 'Theme-title takeover changed the number of H1 headings in its slot.' );
wptl_test_assert( false !== strpos( $wptl_takeover_html, 'id="takeover-title"' ), 'Theme-title takeover did not preserve the safe block anchor.' );
wptl_test_assert( false !== strpos( $wptl_takeover_html, 'has-large-font-size' ), 'Theme-title takeover did not preserve the safe block classes.' );
$wptl_takeover_processor = new WP_HTML_Tag_Processor( $wptl_takeover_html );
$wptl_title_heading_found = $wptl_takeover_processor->next_tag( array( 'class_name' => 'wptl-title-heading' ) );
wptl_test_assert( $wptl_title_heading_found, 'The preserved title heading marker was not found.' );
wptl_test_same( 'H1', $wptl_takeover_processor->get_tag(), 'The theme title anchor moved away from the inherited heading.' );
wptl_test_same( 'takeover-title', $wptl_takeover_processor->get_attribute( 'id' ), 'The theme title anchor moved away from the inherited heading.' );
wptl_test_assert( $wptl_takeover_processor->has_class( 'wp-block-post-title' ), 'The theme Post Title class moved away from the inherited heading.' );

$wptl_invalid_takeover_filter = static function (): string {
	return '<header class="wptl-title-layer"><h2 class="wptl-title-heading">Takeover primary title</h2></header>';
};
add_filter( 'wptl_rendered_title_layer', $wptl_invalid_takeover_filter );
wptl_test_same(
	$wptl_original_title_block,
	$wptl_theme_title->replaceBlockTitle( $wptl_original_title_block, $wptl_takeover_block, $wptl_takeover_instance ),
	'Theme-title takeover removed the native title for an invalid extension rendering.'
);
remove_filter( 'wptl_rendered_title_layer', $wptl_invalid_takeover_filter );

foreach ( array( 'standard', 'editorial', 'inline', 'minimal' ) as $wptl_preset_id ) {
	update_post_meta( $wptl_takeover_post_id, 'wptl_template_override', $wptl_preset_id );
	$wptl_paragraph_block = '<p id="takeover-' . $wptl_preset_id . '" class="wp-block-post-title preset-' . $wptl_preset_id . '" style="text-align:right" aria-label="Theme title">Original title</p>';
	$wptl_preset_html = $wptl_theme_title->replaceBlockTitle( $wptl_paragraph_block, $wptl_takeover_block, $wptl_takeover_instance );
	$wptl_preset_processor = new WP_HTML_Tag_Processor( $wptl_preset_html );
	$wptl_preset_found = $wptl_preset_processor->next_tag( array( 'class_name' => 'wptl-title-heading' ) );
	wptl_test_assert( $wptl_preset_found, 'Theme title marker was missing for preset ' . $wptl_preset_id . '.' );
	wptl_test_same( 'P', $wptl_preset_processor->get_tag(), 'Preset ' . $wptl_preset_id . ' did not inherit a paragraph title element.' );
	wptl_test_same( 'takeover-' . $wptl_preset_id, $wptl_preset_processor->get_attribute( 'id' ), 'Preset ' . $wptl_preset_id . ' moved the theme anchor off the title element.' );
	wptl_test_assert( $wptl_preset_processor->has_class( 'wp-block-post-title' ), 'Preset ' . $wptl_preset_id . ' lost the theme title class.' );
	wptl_test_same( 'text-align:right', $wptl_preset_processor->get_attribute( 'style' ), 'Preset ' . $wptl_preset_id . ' lost the theme title style.' );
	wptl_test_same( 'Theme title', $wptl_preset_processor->get_attribute( 'aria-label' ), 'Preset ' . $wptl_preset_id . ' lost the theme title accessible label.' );
}
delete_post_meta( $wptl_takeover_post_id, 'wptl_template_override' );

/* A real core/query can drop queryId from its descendant Post Title context. */
$wptl_theme_title->register();
$wptl_body_post_title = apply_filters( 'the_content', '<!-- wp:post-title {"level":2} /-->' );
wptl_test_assert( false !== strpos( $wptl_body_post_title, 'Takeover primary title' ), 'A Post Title block in article content did not render its native title.' );
wptl_test_assert( false === strpos( $wptl_body_post_title, 'wptl-theme-title-replacement' ), 'Theme-title takeover replaced a Post Title block inside article content.' );
wptl_test_assert( false === strpos( $wptl_body_post_title, 'Takeover subtitle' ), 'Theme-title takeover inserted the title layer into article content.' );
wptl_test_assert(
	false !== strpos( $wptl_theme_title->replaceBlockTitle( $wptl_original_title_block, $wptl_takeover_block, $wptl_takeover_instance ), 'wptl-theme-title-replacement' ),
	'Theme-title takeover did not recover after leaving the article-content filter.'
);
$wptl_query_markup = '<!-- wp:query {"className":"wptl-query-fixture","query":{"perPage":1,"postType":"post"}} -->'
	. '<div class="wp-block-query"><!-- wp:post-template -->'
	. '<!-- wp:post-title /-->'
	. '<!-- /wp:post-template --></div><!-- /wp:query -->';
$wptl_force_query_fixture = static function ( $query_args, $block ) use ( $wptl_takeover_post_id ) {
	if ( $block instanceof WP_Block && false !== strpos( (string) ( $block->attributes['className'] ?? '' ), 'wptl-query-fixture' ) ) {
		$query_args['post__in'] = array( $wptl_takeover_post_id );
		$query_args['orderby']  = 'post__in';
	}
	return $query_args;
};
add_filter( 'query_loop_block_query_vars', $wptl_force_query_fixture, 10, 2 );
$wptl_query_title_context = array();
$wptl_capture_query_context = static function ( $html, $block, $instance ) use ( &$wptl_query_title_context ): string {
	if ( $instance instanceof WP_Block ) {
		$wptl_query_title_context = $instance->context;
	}
	return (string) $html;
};
add_filter( 'render_block_core/post-title', $wptl_capture_query_context, 1, 3 );
$wptl_query_html = do_blocks( $wptl_query_markup );
remove_filter( 'render_block_core/post-title', $wptl_capture_query_context, 1 );
remove_filter( 'query_loop_block_query_vars', $wptl_force_query_fixture, 10 );
wptl_test_assert( false !== strpos( $wptl_query_html, 'Takeover primary title' ), 'The real Query Loop did not render its post title fixture.' );
wptl_test_same( $wptl_takeover_post_id, (int) ( $wptl_query_title_context['postId'] ?? 0 ), 'The real Query Loop did not expose the current fixture post.' );
wptl_test_assert( empty( $wptl_query_title_context['queryId'] ), 'The Query Loop fixture did not exercise core dropping queryId.' );
wptl_test_assert( false === strpos( $wptl_query_html, 'Takeover subtitle' ), 'Theme-title takeover changed a real Query Loop title after core dropped queryId.' );

$wptl_loop_instance = new WP_Block(
	$wptl_takeover_block,
	array(
		'postId'   => $wptl_takeover_post_id,
		'postType' => 'post',
		'queryId'  => 27,
	)
);
wptl_test_same( $wptl_original_title_block, $wptl_theme_title->replaceBlockTitle( $wptl_original_title_block, $wptl_takeover_block, $wptl_loop_instance ), 'Theme-title takeover changed a Query Loop title.' );
$wptl_linked_block          = $wptl_takeover_block;
$wptl_linked_block['attrs'] = array( 'level' => 1, 'isLink' => true );
wptl_test_same( $wptl_original_title_block, $wptl_theme_title->replaceBlockTitle( $wptl_original_title_block, $wptl_linked_block, $wptl_takeover_instance ), 'Theme-title takeover removed a linked title contract it does not implement.' );

$wptl_manual_before_slot = ( new \WPTitleLayer\Presentation\DynamicBlock() )->render( array( 'postId' => $wptl_takeover_post_id ) );
wptl_test_same( '', $wptl_manual_before_slot, 'A full manual layer rendered before a verified takeover slot.' );
wptl_test_assert(
	false !== strpos( $wptl_theme_title->replaceBlockTitle( $wptl_original_title_block, $wptl_takeover_block, $wptl_takeover_instance ), 'wptl-theme-title-replacement' ),
	'Theme title takeover failed after suppressing a manual layer that appeared earlier.'
);

update_post_meta( $wptl_takeover_post_id, 'wptl_template_override', 'disabled' );
wptl_test_same( $wptl_original_title_block, $wptl_theme_title->replaceBlockTitle( $wptl_original_title_block, $wptl_takeover_block, $wptl_takeover_instance ), 'A disabled post override removed the native theme title.' );
\WPTitleLayer\Presentation\AutoDisplay::markManualPlacement( $wptl_takeover_post_id );
wptl_test_same( '', ( new \WPTitleLayer\Presentation\DynamicBlock() )->render( array( 'postId' => $wptl_takeover_post_id ) ), 'A disabled manual Title Layer unexpectedly rendered.' );
wptl_test_same( $wptl_original_title_block, $wptl_theme_title->replaceBlockTitle( $wptl_original_title_block, $wptl_takeover_block, $wptl_takeover_instance ), 'An empty manual Title Layer removed the native theme title.' );
delete_post_meta( $wptl_takeover_post_id, 'wptl_template_override' );
wp_update_post( array( 'ID' => $wptl_takeover_post_id, 'post_password' => 'secret' ) );
wptl_test_assert( ! $wptl_theme_title->isEligible( $wptl_takeover_post_id ), 'Theme-title takeover exposed a password-protected title layer.' );
wp_update_post( array( 'ID' => $wptl_takeover_post_id, 'post_password' => '' ) );

$wptl_kadence_post_id = wptl_test_create_post( 'Kadence primary title' );
$wptl_kadence_term_id = wptl_test_create_term( 'Kadence Series', 'wptl_series' );
wp_set_object_terms( $wptl_kadence_post_id, array( $wptl_kadence_term_id ), 'wptl_series' );
update_post_meta( $wptl_kadence_post_id, 'wptl_subtitle', 'Kadence subtitle' );
$wptl_takeover_query->queried_object    = get_post( $wptl_kadence_post_id );
$wptl_takeover_query->queried_object_id = $wptl_kadence_post_id;
$GLOBALS['post']                         = get_post( $wptl_kadence_post_id );
$wptl_template_filter = static function (): string { return 'kadence'; };
add_filter( 'template', $wptl_template_filter );
ob_start();
do_action( 'kadence_single_before_entry_title' );
$wptl_kadence_title = apply_filters( 'the_title', 'Kadence primary title', $wptl_kadence_post_id );
echo '<h1 class="entry-title">' . $wptl_kadence_title . '</h1>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixture output.
do_action( 'kadence_single_after_entry_title' );
$wptl_kadence_html = ob_get_clean();
remove_filter( 'template', $wptl_template_filter );
wptl_test_assert( false !== strpos( $wptl_kadence_html, 'Kadence Series' ), 'The Kadence adapter did not render Series context above its native title.' );
wptl_test_assert(
	false !== strpos(
		$wptl_kadence_html,
		'<a class="wptl-series-link" href="' . esc_url( (string) get_term_link( $wptl_kadence_term_id, 'wptl_series' ) ) . '" rel="tag">Kadence Series</a>'
	),
	'The Kadence adapter did not preserve the Series archive link in its context fragment.'
);
wptl_test_assert( false !== strpos( $wptl_kadence_html, 'Kadence subtitle' ), 'The Kadence adapter did not render the subtitle below its native title.' );
wptl_test_same( 1, substr_count( strtolower( $wptl_kadence_html ), '<h1' ), 'The Kadence adapter created a second H1.' );
wptl_test_assert( 1 === preg_match( '/<h1\b[^>]*>(.*?)<\/h1>/is', $wptl_kadence_html, $wptl_kadence_heading ), 'The Kadence fixture lost its native title heading.' );
wptl_test_assert( false === strpos( $wptl_kadence_heading[1], '<a ' ), 'The Kadence Series archive link entered the native title heading.' );
wptl_test_same( 'Unarmed title', apply_filters( 'the_title', 'Unarmed title', $wptl_kadence_post_id ), 'The Kadence adapter changed an unarmed the_title call.' );

$wptl_hostile_fragments = static function ( $fragments ) {
	$fragments['before'] = '<h1>Bad context heading</h1><p class="safe-context"><a class="safe-link" href="https://example.test/series" rel="tag">Context</a><a href="javascript:alert(1)">Bad URL</a></p><script>alert(1)</script>';
	$fragments['title']  = '<div>Bad wrapper</div><span class="safe-title">Safe title</span><a href="https://example.test/inside-title">Bad title link</a><h2>Bad nested heading</h2>';
	$fragments['after']  = '<div>Bad after wrapper</div><p class="safe-after">After</p>';
	return $fragments;
};
add_filter( 'wptl_theme_title_fragments', $wptl_hostile_fragments );
$wptl_hostile_fragments_result = ( new \WPTitleLayer\Presentation\Renderer() )->themeTitleFragments( $wptl_kadence_post_id );
remove_filter( 'wptl_theme_title_fragments', $wptl_hostile_fragments );
wptl_test_assert( false === stripos( $wptl_hostile_fragments_result['title'], '<h' ) && false === stripos( $wptl_hostile_fragments_result['title'], '<div' ), 'Kadence title fragment allowed block or heading markup inside the native H1.' );
wptl_test_assert( false === stripos( $wptl_hostile_fragments_result['before'], '<h1' ) && false === stripos( $wptl_hostile_fragments_result['before'], '<script' ), 'Kadence context fragment allowed unsafe heading or script markup.' );
wptl_test_assert( false !== strpos( $wptl_hostile_fragments_result['title'], 'safe-title' ), 'Kadence title fragment removed allowed phrasing markup.' );
wptl_test_assert( false === strpos( $wptl_hostile_fragments_result['title'], '<a ' ), 'Kadence title fragment allowed a link inside the native H1.' );
wptl_test_assert( false !== strpos( $wptl_hostile_fragments_result['before'], 'href="https://example.test/series"' ), 'Kadence context fragment removed a safe archive link.' );
wptl_test_assert( false === stripos( $wptl_hostile_fragments_result['before'], 'javascript:' ), 'Kadence context fragment retained an unsafe link protocol.' );

$wptl_renderer_reflection = new ReflectionClass( \WPTitleLayer\Presentation\Renderer::class );
$wptl_classic_title_allowlist = $wptl_renderer_reflection->getMethod( 'classicTitleHtml' );
$wptl_classic_context_allowlist = $wptl_renderer_reflection->getMethod( 'classicContextHtml' );
$wptl_classic_title_allowlist->setAccessible( true );
$wptl_classic_context_allowlist->setAccessible( true );
$wptl_classic_title_tags   = $wptl_classic_title_allowlist->invoke( null );
$wptl_classic_context_tags = $wptl_classic_context_allowlist->invoke( null );
wptl_test_assert( ! isset( $wptl_classic_title_tags['a'] ), 'The classic native-title allow-list permits links inside the H1.' );
wptl_test_same(
	array( 'class' => true, 'href' => true, 'rel' => true, 'aria-label' => true ),
	$wptl_classic_context_tags['a'] ?? array(),
	'The classic context link allow-list is broader than the archive-link contract.'
);

$GLOBALS['wp_query']     = $wptl_takeover_old_query;
$GLOBALS['wp_the_query'] = $wptl_takeover_old_the_query;
$GLOBALS['post']         = $wptl_takeover_old_post;
update_option( 'wptl_settings', $wptl_takeover_old_settings );

/* SEO and social channels receive optional Rank Math variables, never forced titles. */
$wptl_rank_math_variables = array();
if ( ! function_exists( 'rank_math_register_var_replacement' ) ) {
	/** Test double for Rank Math's public custom-variable registration API. */
	function rank_math_register_var_replacement( $id, $args = array(), $callback = false ) {
		global $wptl_rank_math_variables;
		$wptl_rank_math_variables[ (string) $id ] = array(
			'args'     => $args,
			'callback' => $callback,
		);
		return true;
	}
}

wptl_test_assert(
	false !== has_action( 'rank_math/vars/register_extra_replacements', array( \WPTitleLayer\Integrations\RankMath::class, 'registerVariables' ) ),
	'The optional Rank Math variable integration was not registered.'
);
do_action( 'rank_math/vars/register_extra_replacements' );
foreach ( array( 'wptl_subtitle', 'wptl_series', 'wptl_title_with_subtitle' ) as $wptl_rank_math_variable ) {
	wptl_test_assert(
		isset( $wptl_rank_math_variables[ $wptl_rank_math_variable ] ) && is_callable( $wptl_rank_math_variables[ $wptl_rank_math_variable ]['callback'] ),
		'Rank Math did not receive the ' . $wptl_rank_math_variable . ' variable.'
	);
	wptl_test_assert(
		! empty( $wptl_rank_math_variables[ $wptl_rank_math_variable ]['args']['nocache'] ),
		'The context-dependent Rank Math variable ' . $wptl_rank_math_variable . ' was incorrectly cacheable.'
	);
}

$wptl_rank_math_post_id = wptl_test_create_post( 'SEO primary title' );
$wptl_rank_math_term_id = wptl_test_create_term( 'SEO Series name', 'wptl_series' );
update_post_meta( $wptl_rank_math_post_id, 'wptl_subtitle', 'SEO subtitle' );
wp_set_object_terms( $wptl_rank_math_post_id, array( $wptl_rank_math_term_id ), 'wptl_series' );
$wptl_rank_math_post = get_post( $wptl_rank_math_post_id );
wptl_test_same( 'SEO subtitle', \WPTitleLayer\Integrations\RankMath::subtitle( array(), $wptl_rank_math_post ), 'The Rank Math subtitle variable did not use canonical subtitle data.' );
wptl_test_same( 'SEO Series name', \WPTitleLayer\Integrations\RankMath::series( array(), $wptl_rank_math_post ), 'The Rank Math Series variable did not use the full Series name.' );
wptl_test_same( 'SEO primary title: SEO subtitle', \WPTitleLayer\Integrations\RankMath::titleWithSubtitle( array(), $wptl_rank_math_post ), 'The conditional Rank Math title variable did not use English punctuation.' );
$wptl_rank_math_old_query = $GLOBALS['wp_query'];
$wptl_rank_math_old_post  = $GLOBALS['post'] ?? null;
$wptl_rank_math_term_query = new WP_Query();
$wptl_rank_math_term_query->queried_object    = get_term( $wptl_rank_math_term_id, 'wptl_series' );
$wptl_rank_math_term_query->queried_object_id = $wptl_rank_math_term_id;
$wptl_rank_math_term_query->is_tax             = true;
$GLOBALS['wp_query'] = $wptl_rank_math_term_query;
$GLOBALS['post']     = $wptl_rank_math_post;
wptl_test_same( 'SEO Series name', \WPTitleLayer\Integrations\RankMath::series(), 'The Rank Math Series variable used the first loop post instead of the Series archive term.' );
wptl_test_same( 'SEO Series name', \WPTitleLayer\Integrations\RankMath::titleWithSubtitle(), 'The combined Rank Math title variable used an article title on the Series archive.' );
wptl_test_same( '', \WPTitleLayer\Integrations\RankMath::subtitle(), 'The Rank Math subtitle variable leaked a loop article subtitle onto the Series archive.' );
wptl_test_same( 'SEO Series name', \WPTitleLayer\Integrations\RankMath::series( array(), $wptl_rank_math_post ), 'An explicit loop post overrode the Series archive term.' );
wptl_test_same( 'SEO Series name', \WPTitleLayer\Integrations\RankMath::titleWithSubtitle( array(), $wptl_rank_math_post ), 'An explicit loop post title leaked onto the Series archive.' );
wptl_test_same( '', \WPTitleLayer\Integrations\RankMath::subtitle( array(), $wptl_rank_math_post ), 'An explicit loop post subtitle leaked onto the Series archive.' );
$GLOBALS['wp_query'] = $wptl_rank_math_old_query;
$GLOBALS['post']     = $wptl_rank_math_old_post;
$wptl_rank_math_separator = static function (): string {
	return ' | ';
};
add_filter( 'wptl_rank_math_title_separator', $wptl_rank_math_separator );
wptl_test_same( 'SEO primary title | SEO subtitle', \WPTitleLayer\Integrations\RankMath::titleWithSubtitle( array(), $wptl_rank_math_post ), 'The Rank Math title separator filter was ignored.' );
remove_filter( 'wptl_rank_math_title_separator', $wptl_rank_math_separator );

/* Packaged Simplified Chinese translations cover PHP, editor data, and locale punctuation. */
$wptl_i18n_settings_before  = get_option( 'wptl_settings', array() );
$wptl_core_locale_marker    = WP_LANG_DIR . '/zh_CN.mo';
$wptl_created_locale_marker = false;
if ( ! is_file( $wptl_core_locale_marker ) ) {
	wp_mkdir_p( WP_LANG_DIR );
	$wptl_created_locale_marker = copy( WPTL_PATH . 'languages/wp-title-layer-zh_CN.mo', $wptl_core_locale_marker );
}
$wptl_locale_switcher = $GLOBALS['wp_locale_switcher'] ?? null;
$wptl_locale_languages_property = null;
$wptl_locale_languages_before   = array();
if ( $wptl_locale_switcher instanceof WP_Locale_Switcher ) {
	$wptl_locale_switcher_reflection = new ReflectionClass( $wptl_locale_switcher );
	$wptl_locale_languages_property  = $wptl_locale_switcher_reflection->getProperty( 'available_languages' );
	$wptl_locale_languages_property->setAccessible( true );
	$wptl_locale_languages_before  = (array) $wptl_locale_languages_property->getValue( $wptl_locale_switcher );
	$wptl_locale_languages_property->setValue(
		$wptl_locale_switcher,
		array_values( array_unique( array_merge( $wptl_locale_languages_before, array( 'zh_CN' ) ) ) )
	);
}
$wptl_switched_to_zh_cn    = switch_to_locale( 'zh_CN' );
wptl_test_assert(
	$wptl_switched_to_zh_cn,
	'WordPress could not switch to the packaged Simplified Chinese locale. Current: ' . get_locale()
		. '; determine: ' . determine_locale()
		. '; marker: ' . ( is_readable( $wptl_core_locale_marker ) ? 'readable' : 'missing' )
		. '; available: ' . implode( ',', get_available_languages() )
);
wptl_test_same( '副标题', __( 'Subtitle', 'wp-title-layer' ), 'The packaged PHP translation did not load after switching locale.' );
wptl_test_same( '系列', __( 'Series', 'wp-title-layer' ), 'The canonical Series term was not translated consistently.' );
wptl_test_same( '系列总序', sprintf( __( 'Series %s', 'wp-title-layer' ), '总序' ), 'The public whole-Series structure heading did not use natural Chinese terminology.' );
wptl_test_same( '设置分区', __( 'Settings sections', 'wp-title-layer' ), 'The grouped settings navigation label was not translated.' );
wptl_test_same( '上一页', __( 'Previous page', 'wp-title-layer' ), 'The Simplified Chinese pagination label used article-navigation wording.' );
wptl_test_same( '下一页', __( 'Next page', 'wp-title-layer' ), 'The Simplified Chinese pagination label used article-navigation wording.' );
wptl_test_same( '上一篇', __( 'Previous', 'wp-title-layer' ), 'The article-navigation label was changed into a pagination label.' );
wptl_test_same( '系列阅读导航', __( 'Series reading navigation', 'wp-title-layer' ), 'The Reader navigation landmark was not translated.' );
wptl_test_same( '系列归档页分页', __( 'Series archive pages', 'wp-title-layer' ), 'The structured archive pagination landmark was not translated.' );
wptl_test_same( '查看所有季', __( 'View all seasons', 'wp-title-layer' ), 'The structured archive season link was not translated.' );
wptl_test_same( '从头阅读', __( 'Read from the beginning', 'wp-title-layer' ), 'The full-Series start link was not translated.' );
wptl_test_same( '从本季开头阅读', __( 'Read this season from the beginning', 'wp-title-layer' ), 'The season-scoped start link was not translated.' );
wptl_test_same( '此系列暂无可公开访问的已发布文章。', __( 'No published articles are available in this Series.', 'wp-title-layer' ), 'The empty structured archive message was not translated.' );
wptl_test_same( '第 2 篇，共 5 篇', sprintf( __( '%1$d of %2$d', 'wp-title-layer' ), 2, 5 ), 'The Reader position string was not translated.' );
wptl_test_same( '3 篇已发布文章', sprintf( _n( '%d published article', '%d published articles', 3, 'wp-title-layer' ), 3 ), 'The structured archive article-count plural was not translated.' );
wptl_test_same(
	array( 'colon' => '：', 'dash' => '——', 'pipe' => '｜' ),
	\WPTitleLayer\Presentation\Presets::localeSeparators(),
	'The Simplified Chinese locale did not use Chinese title separators.'
);
wptl_test_same(
	'：',
	\WPTitleLayer\Presentation\Presets::separatorText( \WPTitleLayer\Presentation\Presets::defaultRecipe( 'inline' ) ),
	'The built-in inline recipe did not resolve its Chinese colon at render time.'
);
wptl_test_same(
	'·',
	\WPTitleLayer\Presentation\Presets::separatorText(
		array(
			'separator_style'  => 'custom',
			'custom_separator' => '·',
		)
	),
	'A custom separator was changed by locale switching.'
);
wptl_test_same( 'SEO primary title：SEO subtitle', \WPTitleLayer\Integrations\RankMath::titleWithSubtitle( array(), $wptl_rank_math_post ), 'The Rank Math variable did not use Chinese punctuation under zh_CN.' );
$wptl_zh_role_labels = \WPTitleLayer\Core\Schema::role_labels();
wptl_test_same( '未指定（按主要文章处理）', $wptl_zh_role_labels[''] ?? '', 'The unspecified Series role was not translated or clarified.' );
wptl_test_same( '序文／前言', $wptl_zh_role_labels['intro'] ?? '', 'The Series role labels were not translated.' );
wptl_test_same( '主要文章（明确指定）', $wptl_zh_role_labels['article'] ?? '', 'The explicit main-article role was not translated or distinguished.' );
$wptl_zh_scope_labels = \WPTitleLayer\Core\Schema::scope_labels();
wptl_test_same( '全系列', $wptl_zh_scope_labels['series'] ?? '', 'The Series-wide scope label was not translated.' );
wptl_test_same( '单季', $wptl_zh_scope_labels['season'] ?? '', 'The season scope label was not translated.' );
$wptl_zh_editor_data = $wptl_editor_data_method->invoke( null );
wptl_test_same( '未指定（按主要文章处理）', $wptl_zh_editor_data['roles'][0]['label'] ?? '', 'The block-editor bootstrap did not receive the clarified translated role label.' );
wptl_test_same( 'wptl_parent_category_id', $wptl_zh_editor_data['schema']['parentCategory'] ?? '', 'The block editor did not receive the Series parent-Category schema key.' );
$wptl_editor_category_branch = $wptl_zh_editor_data['seriesCategoryBranches'][ (string) $wptl_series_parent_category_id ] ?? array();
wptl_test_assert(
	in_array( $wptl_series_parent_category_id, (array) ( $wptl_editor_category_branch['ids'] ?? array() ), true )
	&& in_array( $wptl_series_child_category_id, (array) ( $wptl_editor_category_branch['ids'] ?? array() ), true ),
	'The editor consistency warning did not receive the parent Category and its descendants.'
);
ob_start();
\WPTitleLayer\Core\Series::render_add_term_fields();
$wptl_zh_series_form = (string) ob_get_clean();
wptl_test_assert(
	false !== strpos( $wptl_zh_series_form, '>有序</option>' )
	&& false !== strpos( $wptl_zh_series_form, '>无序</option>' )
	&& false !== strpos( $wptl_zh_series_form, '>不分季</option>' )
	&& false !== strpos( $wptl_zh_series_form, '>分季</option>' )
	&& false !== strpos( $wptl_zh_series_form, '>最新在前</option>' ),
	'The native Series form leaked unlocalized stored enum values.'
);
ob_start();
\WPTitleLayer\Presentation\SettingsPage::renderPresetCustomizationsField();
$wptl_zh_preset_form = (string) ob_get_clean();
wptl_test_assert(
	false !== strpos( $wptl_zh_preset_form, '同行分隔符' )
	&& false !== strpos( $wptl_zh_preset_form, '冒号 ：' )
	&& false !== strpos( $wptl_zh_preset_form, '破折号 ——' )
	&& false !== strpos( $wptl_zh_preset_form, '竖线 ｜' ),
	'The template settings did not present locale-appropriate separator choices.'
);
wptl_test_same( $wptl_i18n_settings_before, get_option( 'wptl_settings', array() ), 'Switching locale rewrote stored plugin settings.' );
wptl_test_assert( restore_previous_locale(), 'WordPress could not restore the test locale.' );
if ( $wptl_locale_languages_property instanceof ReflectionProperty ) {
	$wptl_locale_languages_property->setValue( $wptl_locale_switcher, $wptl_locale_languages_before );
}
if ( $wptl_created_locale_marker ) {
	unlink( $wptl_core_locale_marker );
}
wptl_test_same( 'Subtitle', __( 'Subtitle', 'wp-title-layer' ), 'Restoring the locale left Simplified Chinese strings active.' );
wptl_test_same(
	array( 'colon' => ': ', 'dash' => ' — ', 'pipe' => ' | ' ),
	\WPTitleLayer\Presentation\Presets::localeSeparators(),
	'The English locale did not restore conventional English separators.'
);

/* The settings page groups all registered modules while preserving one native save form. */
$wptl_settings_page_original_user = get_current_user_id();
wp_set_current_user( (int) $wptl_admin_id );
\WPTitleLayer\Presentation\SettingsPage::registerSettings();
\WPTitleLayer\Reader\AdminSettings::addFields();
\WPTitleLayer\Integrations\RankMath::addSettingsSection();
ob_start();
\WPTitleLayer\Presentation\SettingsPage::renderPage();
$wptl_settings_page_html = (string) ob_get_clean();
wptl_test_same( 4, substr_count( $wptl_settings_page_html, 'class="wptl-settings-group"' ), 'The settings page did not render one visual group per registered settings section.' );
wptl_test_same( 4, substr_count( $wptl_settings_page_html, 'href="#wptl-settings-group-' ), 'The settings overview did not link to every registered settings group.' );
$wptl_settings_section_positions = array_map(
	static function ( $section_id ) use ( $wptl_settings_page_html ) {
		return strpos( $wptl_settings_page_html, 'id="wptl-settings-group-' . $section_id . '"' );
	},
	array( 'wptl_frontend_output_section', 'wptl_presentation_section', 'wptl_reader_section', 'wptl_rank_math_section' )
);
wptl_test_assert(
	! in_array( false, $wptl_settings_section_positions, true )
	&& $wptl_settings_section_positions === array_values( $wptl_settings_section_positions )
	&& $wptl_settings_section_positions[0] < $wptl_settings_section_positions[1]
	&& $wptl_settings_section_positions[1] < $wptl_settings_section_positions[2]
	&& $wptl_settings_section_positions[2] < $wptl_settings_section_positions[3],
	'The settings groups did not preserve their native registration order.'
);
wptl_test_assert(
	false !== strpos( $wptl_settings_page_html, "name='option_page' value='wptl_settings_group'" )
	&& false !== strpos( $wptl_settings_page_html, 'name="wptl_settings[reader][_present]" value="1"' ),
	'The grouped settings UI lost the native option nonce path or Reader ownership marker.'
);
wptl_test_assert(
	false !== strpos( $wptl_settings_page_html, 'Detected integration:' )
	&& false !== strpos( $wptl_settings_page_html, 'These features use the ordered / unordered rule' )
	&& false !== strpos( $wptl_settings_page_html, 'visible title, SEO title, and social-sharing title are separate channels' ),
	'The grouped settings UI did not execute each section callback.'
);
wptl_test_same( 1, substr_count( $wptl_settings_page_html, 'class="wptl-settings__actions"' ), 'The grouped settings page did not keep one shared save action.' );
wptl_test_same( 4, substr_count( $wptl_settings_page_html, 'data-wptl-settings-tab ' ), 'The settings page did not expose one progressive tab control per section.' );
wptl_test_same( 4, substr_count( $wptl_settings_page_html, 'data-wptl-settings-panel ' ), 'The settings page did not expose one progressive tab panel per section.' );
wptl_test_assert(
	false !== strpos( $wptl_settings_page_html, 'archive_show_featured_images' ),
	'The Reader settings omitted the structured-archive featured-image default.'
);
wptl_test_assert(
	false !== strpos( $wptl_settings_page_html, 'archive_show_excerpts' )
	&& false !== strpos( $wptl_settings_page_html, 'series_icons_enabled' ),
	'The grouped settings page omitted the opt-in structured excerpts or Series icon controls.'
);
wp_dequeue_script( 'wptl-settings-tabs' );
wp_deregister_script( 'wptl-settings-tabs' );
\WPTitleLayer\Presentation\SettingsPage::enqueueAssets( 'dashboard' );
wptl_test_assert( ! wp_script_is( 'wptl-settings-tabs', 'enqueued' ), 'The settings tab script loaded outside its own screen.' );
\WPTitleLayer\Presentation\SettingsPage::enqueueAssets( 'settings_page_wp-title-layer' );
wptl_test_assert( wp_script_is( 'wptl-settings-tabs', 'enqueued' ), 'The settings tab script did not load on the settings screen.' );
unregister_setting( 'wptl_settings_group', \WPTitleLayer\Presentation\Schema::settingsOption() );
wp_set_current_user( $wptl_settings_page_original_user );

$wptl_editor_translation_json = json_decode( (string) file_get_contents( WPTL_PATH . 'languages/wp-title-layer-zh_CN-wptl-editor.json' ), true );
$wptl_editor_translation_messages = is_array( $wptl_editor_translation_json )
	? ( $wptl_editor_translation_json['locale_data']['messages'] ?? array() )
	: array();
wptl_test_same( '标题层', $wptl_editor_translation_messages['Title Layer'][0] ?? '', 'The packaged editor translation omitted Title Layer.' );
wptl_test_same( '无系列', $wptl_editor_translation_messages['No Series'][0] ?? '', 'The packaged editor translation omitted the Series selector.' );
wptl_test_same( '副标题', $wptl_editor_translation_messages['Subtitle'][0] ?? '', 'The packaged editor translation omitted Subtitle.' );

$wptl_rank_math_plain_post_id = wptl_test_create_post( 'SEO title without subtitle' );
wptl_test_same(
	'SEO title without subtitle',
	\WPTitleLayer\Integrations\RankMath::titleWithSubtitle( array(), get_post( $wptl_rank_math_plain_post_id ) ),
	'The conditional Rank Math title variable left a separator without a subtitle.'
);
$wptl_rank_math_untitled_post_id = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => '',
		'post_content' => '<p>Untitled fixture body.</p>',
	),
	true
);
wptl_test_assert( ! is_wp_error( $wptl_rank_math_untitled_post_id ) && (int) $wptl_rank_math_untitled_post_id > 0, 'Could not create the untitled Rank Math fixture.' );
$wptl_rank_math_untitled_post_id = is_wp_error( $wptl_rank_math_untitled_post_id ) ? 0 : (int) $wptl_rank_math_untitled_post_id;
update_post_meta( $wptl_rank_math_untitled_post_id, 'wptl_subtitle', 'Subtitle without title' );
wptl_test_same(
	'Subtitle without title',
	\WPTitleLayer\Integrations\RankMath::titleWithSubtitle( array(), get_post( $wptl_rank_math_untitled_post_id ) ),
	'The conditional Rank Math title variable left a leading separator without a primary title.'
);
wptl_test_assert(
	! metadata_exists( 'post', $wptl_rank_math_post_id, 'rank_math_title' )
	&& ! metadata_exists( 'post', $wptl_rank_math_post_id, 'rank_math_facebook_title' )
	&& ! metadata_exists( 'post', $wptl_rank_math_post_id, 'rank_math_twitter_title' ),
	'The Rank Math integration wrote provider-owned title metadata.'
);
ob_start();
\WPTitleLayer\Integrations\RankMath::renderSectionIntro();
\WPTitleLayer\Integrations\RankMath::renderVariables();
$wptl_rank_math_settings_html = (string) ob_get_clean();
wptl_test_assert(
	false !== strpos( $wptl_rank_math_settings_html, '%wptl_title_with_subtitle%' )
	&& false !== strpos( $wptl_rank_math_settings_html, 'never changes Rank Math metadata automatically' ),
	'The settings screen did not explain the independent SEO and social title variables.'
);
wp_delete_post( $wptl_rank_math_post_id, true );
wp_delete_post( $wptl_rank_math_plain_post_id, true );
wp_delete_post( $wptl_rank_math_untitled_post_id, true );
wp_delete_term( $wptl_rank_math_term_id, 'wptl_series' );

/* Reader ordering/navigation uses one public canonical sequence. */
$wptl_reader_settings = \WPTitleLayer\Reader\Settings::all();
wptl_test_assert( ! $wptl_reader_settings['archive_template_enabled'], 'Series archive replacement was not opt-in by default.' );
wptl_test_assert( $wptl_reader_settings['archive_show_subtitles'], 'Structured Series archives did not retain their existing subtitle behavior by default.' );
wptl_test_assert( ! $wptl_reader_settings['archive_show_excerpts'], 'Structured archive excerpts were not opt-in by default.' );
wptl_test_assert( ! $wptl_reader_settings['theme_archive_show_subtitles'], 'Theme archive-card Subtitle injection was not opt-in by default.' );
wptl_test_assert( ! $wptl_reader_settings['archive_show_featured_images'], 'Structured archive featured images were not opt-in by default.' );
wptl_test_assert( ! $wptl_reader_settings['after_content_enabled'], 'Automatic Series navigation was not opt-in by default.' );
wptl_test_assert( $wptl_reader_settings['shortcode_enabled'], 'The manual Series navigation shortcode was not enabled by default.' );
wptl_test_assert( shortcode_exists( 'wptl_series_navigation' ), 'The manual Series navigation shortcode was not registered.' );

$wptl_shared_settings_before = get_option( 'wptl_settings', array() );
update_option(
	'wptl_settings',
	array(
		'presentation' => array( 'default_template' => 'standard' ),
		'reader'       => array(
			'archive_template_enabled' => true,
			'archive_show_subtitles'   => false,
			'archive_show_excerpts'    => true,
			'theme_archive_show_subtitles' => true,
			'archive_show_featured_images' => true,
			'after_content_enabled'     => true,
			'shortcode_enabled'         => false,
		),
	)
);
$wptl_presentation_only = \WPTitleLayer\Presentation\SettingsPage::sanitizeSettings(
	array( 'presentation' => array( 'default_template' => 'minimal' ) )
);
wptl_test_assert( ! empty( $wptl_presentation_only['reader']['archive_template_enabled'] ), 'Updating Presentation silently cleared the Reader archive setting.' );
wptl_test_assert( empty( $wptl_presentation_only['reader']['archive_show_subtitles'] ), 'Updating Presentation changed the Reader archive-subtitle setting.' );
wptl_test_assert( ! empty( $wptl_presentation_only['reader']['archive_show_excerpts'] ), 'Updating Presentation silently cleared the structured-archive excerpt setting.' );
wptl_test_assert( ! empty( $wptl_presentation_only['reader']['theme_archive_show_subtitles'] ), 'Updating Presentation silently cleared the theme archive-card Subtitle setting.' );
wptl_test_assert( ! empty( $wptl_presentation_only['reader']['archive_show_featured_images'] ), 'Updating Presentation silently cleared the structured-archive featured-image setting.' );
wptl_test_assert( ! empty( $wptl_presentation_only['reader']['after_content_enabled'] ), 'Updating Presentation silently cleared the Reader navigation setting.' );
wptl_test_assert( empty( $wptl_presentation_only['reader']['shortcode_enabled'] ), 'Updating Presentation changed the Reader shortcode setting.' );
$wptl_reader_only = \WPTitleLayer\Presentation\SettingsPage::sanitizeSettings(
	array(
		'reader' => array(
			'_present'                => 1,
			'archive_template_enabled' => false,
			'archive_show_subtitles'   => true,
			'archive_show_excerpts'    => false,
			'theme_archive_show_subtitles' => false,
			'archive_show_featured_images' => false,
			'after_content_enabled'     => false,
			'shortcode_enabled'         => true,
		),
	)
);
wptl_test_same( array( 'default_template' => 'standard' ), $wptl_reader_only['presentation'], 'Updating Reader settings silently changed the Presentation subtree.' );
wptl_test_assert( ! empty( $wptl_reader_only['reader']['archive_show_subtitles'] ), 'Reader settings did not save the structured-archive subtitle choice.' );
wptl_test_assert( empty( $wptl_reader_only['reader']['archive_show_excerpts'] ), 'Reader settings did not save the structured-archive excerpt choice.' );
wptl_test_assert( empty( $wptl_reader_only['reader']['theme_archive_show_subtitles'] ), 'Reader settings did not save the theme archive-card Subtitle choice.' );
wptl_test_assert( empty( $wptl_reader_only['reader']['archive_show_featured_images'] ), 'Reader settings did not save the structured-archive featured-image choice.' );
wptl_test_assert( false !== has_action( 'kadence_loop_entry_header', array( 'WPTitleLayer\\Reader\\ThemeArchiveIntegration', 'renderKadenceSubtitle' ) ), 'The verified theme archive adapter hook was not registered.' );
wptl_test_assert( empty( \WPTitleLayer\Reader\ThemeArchiveIntegration::support()['supported'] ), 'An unverified active theme was treated as archive-card compatible.' );
update_option( 'wptl_settings', $wptl_shared_settings_before );

$wptl_reader_term_id = wptl_test_create_term( 'WPTL Reader Ordered', 'wptl_series' );
update_term_meta( $wptl_reader_term_id, 'wptl_series_mode', 'ordered' );
update_term_meta( $wptl_reader_term_id, 'wptl_series_structure', 'seasoned' );
update_term_meta( $wptl_reader_term_id, 'wptl_series_status', 'ongoing' );
update_term_meta(
	$wptl_reader_term_id,
	'wptl_seasons',
	array(
		array( 'key' => 's1', 'label' => 'Season 1', 'sort' => 10 ),
		array( 'key' => 's2', 'label' => 'Season 2', 'sort' => 20 ),
	)
);
$wptl_reader_term = get_term( $wptl_reader_term_id, 'wptl_series' );
$wptl_reader_override_settings = get_option( 'wptl_settings', array() );
update_option(
	'wptl_settings',
	array(
		'reader' => array(
			'archive_template_enabled'       => false,
			'archive_show_subtitles'         => true,
			'archive_show_excerpts'          => false,
			'theme_archive_show_subtitles'   => false,
			'archive_show_featured_images'   => false,
			'after_content_enabled'           => false,
			'shortcode_enabled'               => true,
		),
	)
);
wptl_test_assert( ! \WPTitleLayer\Reader\Settings::archiveTemplateEnabled( $wptl_reader_term ), 'A Series without an override did not inherit the global theme layout.' );
wptl_test_assert( \WPTitleLayer\Reader\Settings::archiveSubtitlesEnabled( $wptl_reader_term ), 'A Series without an override did not inherit structured subtitles.' );
wptl_test_assert( ! \WPTitleLayer\Reader\Settings::archiveExcerptsEnabled( $wptl_reader_term ), 'A Series without an override did not inherit the opt-in excerpt default.' );
wptl_test_assert( ! \WPTitleLayer\Reader\Settings::archiveFeaturedImagesEnabled( $wptl_reader_term ), 'A Series without an override did not inherit the featured-image default.' );
update_term_meta( $wptl_reader_term_id, 'wptl_archive_layout', 'structured' );
update_term_meta( $wptl_reader_term_id, 'wptl_archive_subtitles', 'hide' );
update_term_meta( $wptl_reader_term_id, 'wptl_archive_excerpts', 'show' );
update_term_meta( $wptl_reader_term_id, 'wptl_archive_featured_images', 'show' );
update_term_meta( $wptl_reader_term_id, 'wptl_parent_category_id', $wptl_series_parent_category_id );
wptl_test_assert( \WPTitleLayer\Reader\Settings::archiveTemplateEnabled( $wptl_reader_term ), 'A per-Series structured-layout override was ignored.' );
wptl_test_assert( ! \WPTitleLayer\Reader\Settings::archiveSubtitlesEnabled( $wptl_reader_term ), 'A per-Series Subtitle hide override was ignored.' );
wptl_test_assert( \WPTitleLayer\Reader\Settings::archiveExcerptsEnabled( $wptl_reader_term ), 'A per-Series Excerpt show override was ignored.' );
wptl_test_assert( \WPTitleLayer\Reader\Settings::archiveFeaturedImagesEnabled( $wptl_reader_term ), 'A per-Series featured-image show override was ignored.' );
$wptl_reader_global_excerpt_settings = get_option( 'wptl_settings', array() );
$wptl_reader_global_excerpt_settings['reader']['archive_show_excerpts'] = true;
update_option( 'wptl_settings', $wptl_reader_global_excerpt_settings );
update_term_meta( $wptl_reader_term_id, 'wptl_archive_excerpts', 'hide' );
wptl_test_assert( ! \WPTitleLayer\Reader\Settings::archiveExcerptsEnabled( $wptl_reader_term ), 'A per-Series Excerpt hide override was ignored while the global setting was on.' );
update_term_meta( $wptl_reader_term_id, 'wptl_archive_excerpts', 'show' );
update_term_meta( $wptl_reader_term_id, 'wptl_archive_layout', 'theme' );
wptl_test_assert( ! \WPTitleLayer\Reader\Settings::archiveTemplateEnabled( $wptl_reader_term ), 'A per-Series theme-layout override was ignored.' );
update_term_meta( $wptl_reader_term_id, 'wptl_archive_layout', 'structured' );
update_option( 'wptl_settings', $wptl_reader_override_settings );
$wptl_reader_ids = array(
	wptl_test_create_post( 'Reader first <script>alert(1)</script>' ),
	wptl_test_create_post( 'Reader second' ),
	wptl_test_create_post( 'Reader third' ),
);
foreach ( $wptl_reader_ids as $wptl_reader_id ) {
	wp_set_object_terms( $wptl_reader_id, array( $wptl_reader_term_id ), 'wptl_series' );
}
wp_update_post(
	array(
		'ID'           => $wptl_reader_ids[2],
		'post_excerpt' => 'Structured <strong>excerpt</strong> sentinel',
	)
);
$wptl_reader_image_upload = wp_upload_bits(
	'wptl-reader-thumbnail.png',
	null,
	base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true )
);
$wptl_reader_thumbnail_id = ! empty( $wptl_reader_image_upload['error'] )
	? new WP_Error( 'wptl_test_upload_failed', (string) $wptl_reader_image_upload['error'] )
	: wp_insert_attachment(
		array(
			'post_title'     => 'Reader archive thumbnail',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/png',
		),
		(string) $wptl_reader_image_upload['file'],
		$wptl_reader_ids[2],
		true
	);
wptl_test_assert( ! is_wp_error( $wptl_reader_thumbnail_id ), 'Could not create the structured-archive thumbnail fixture.' );
if ( ! is_wp_error( $wptl_reader_thumbnail_id ) ) {
	update_post_meta( $wptl_reader_ids[2], '_thumbnail_id', (int) $wptl_reader_thumbnail_id );
}
update_post_meta( $wptl_reader_ids[0], 'wptl_season_key', 's1' );
update_post_meta( $wptl_reader_ids[0], 'wptl_sequence_position', 10 );
update_post_meta( $wptl_reader_ids[1], 'wptl_season_key', 's1' );
update_post_meta( $wptl_reader_ids[1], 'wptl_sequence_position', 20 );
update_post_meta( $wptl_reader_ids[2], 'wptl_season_key', 's2' );
update_post_meta( $wptl_reader_ids[2], 'wptl_sequence_position', 10 );

$wptl_reader_hidden = array(
	'draft'    => wptl_test_create_post( 'Reader hidden draft sentinel' ),
	'private'  => wptl_test_create_post( 'Reader hidden private sentinel' ),
	'future'   => wptl_test_create_post( 'Reader hidden future sentinel' ),
	'password' => wptl_test_create_post( 'Reader hidden password sentinel' ),
);
foreach ( $wptl_reader_hidden as $wptl_reader_hidden_id ) {
	wp_set_object_terms( $wptl_reader_hidden_id, array( $wptl_reader_term_id ), 'wptl_series' );
	update_post_meta( $wptl_reader_hidden_id, 'wptl_season_key', 's2' );
	update_post_meta( $wptl_reader_hidden_id, 'wptl_sequence_position', 90 );
}
wp_update_post( array( 'ID' => $wptl_reader_hidden['draft'], 'post_status' => 'draft' ) );
wp_update_post( array( 'ID' => $wptl_reader_hidden['private'], 'post_status' => 'private' ) );
wp_update_post(
	array(
		'ID'            => $wptl_reader_hidden['future'],
		'post_status'   => 'future',
		'post_date'     => '2035-01-01 00:00:00',
		'post_date_gmt' => '2035-01-01 00:00:00',
	)
);
wp_update_post( array( 'ID' => $wptl_reader_hidden['password'], 'post_password' => 'secret' ) );

$wptl_navigation  = new \WPTitleLayer\Reader\Navigation();
wptl_test_same( $wptl_reader_ids, $wptl_navigation->orderedPostIds( $wptl_reader_term ), 'Reader order was not season rank, position, then post ID.' );
$wptl_middle_context = $wptl_navigation->context( $wptl_reader_ids[1] );
wptl_test_same( $wptl_reader_ids[0], $wptl_middle_context['previous']['id'], 'Reader previous navigation was incorrect.' );
wptl_test_same( $wptl_reader_ids[2], $wptl_middle_context['next']['id'], 'Reader next navigation did not cross the season boundary.' );
wptl_test_same( 2, $wptl_middle_context['position'], 'Reader progress numerator was incorrect.' );
wptl_test_same( 3, $wptl_middle_context['total'], 'Reader progress included a hidden post.' );
wptl_test_same(
	\WPTitleLayer\Core\Series::public_archive_url( $wptl_reader_term ),
	$wptl_middle_context['series']['url'],
	'Reader navigation did not use the shared public Series archive URL.'
);
wptl_test_same( 'series', \WPTitleLayer\Core\Series::navigation_scope( $wptl_reader_term ), 'An existing Series without scope meta did not retain cross-season navigation.' );
wptl_test_same( 'series', $wptl_middle_context['scope'], 'The compatible Reader context did not report its full-Series scope.' );

/* A Series may explicitly stop every navigation control at season boundaries. */
update_term_meta( $wptl_reader_term_id, 'wptl_navigation_scope', 'season' );
wptl_test_same( 'season', \WPTitleLayer\Core\Series::navigation_scope( $wptl_reader_term ), 'A seasoned Series did not enable its explicit current-season boundary.' );
wptl_test_same( $wptl_reader_ids, $wptl_navigation->orderedPostIds( $wptl_reader_term ), 'Current-season navigation changed the canonical full-Series order.' );
wptl_test_same( array_slice( $wptl_reader_ids, 0, 2 ), $wptl_navigation->scopedPostIds( $wptl_reader_term, 's1' ), 'The first season navigation collection was incorrect.' );
wptl_test_same( array_slice( $wptl_reader_ids, 2, 1 ), $wptl_navigation->scopedPostIds( $wptl_reader_term, 's2' ), 'The second season navigation collection included another season or a hidden article.' );

$wptl_season_first_context  = $wptl_navigation->context( $wptl_reader_ids[0] );
$wptl_season_middle_context = $wptl_navigation->context( $wptl_reader_ids[1] );
$wptl_season_last_context   = $wptl_navigation->context( $wptl_reader_ids[2] );
wptl_test_same( null, $wptl_season_first_context['previous'], 'The first article in a season linked backward across the boundary.' );
wptl_test_same( $wptl_reader_ids[1], $wptl_season_first_context['next']['id'], 'The first article in a season did not link to its in-season successor.' );
wptl_test_same( 1, $wptl_season_first_context['position'], 'The first season progress numerator did not reset.' );
wptl_test_same( 2, $wptl_season_first_context['total'], 'The first season progress denominator used the full Series.' );
wptl_test_same( $wptl_reader_ids[0], $wptl_season_first_context['first']['id'], 'The first-season start link did not target that season’s first article.' );
wptl_test_same( $wptl_reader_ids[0], $wptl_season_middle_context['previous']['id'], 'The second article lost its in-season previous link.' );
wptl_test_same( null, $wptl_season_middle_context['next'], 'The last article in a season linked forward across the boundary.' );
wptl_test_same( 2, $wptl_season_middle_context['position'], 'The last article in the first season had the wrong scoped position.' );
wptl_test_same( 2, $wptl_season_middle_context['total'], 'The first season total included another season.' );
wptl_test_same( null, $wptl_season_last_context['previous'], 'A one-article season linked backward to another season.' );
wptl_test_same( null, $wptl_season_last_context['next'], 'A one-article season linked forward to another season.' );
wptl_test_same( 1, $wptl_season_last_context['position'], 'A one-article season did not start at position one.' );
wptl_test_same( 1, $wptl_season_last_context['total'], 'A one-article season did not report a total of one.' );
wptl_test_same( 's1', $wptl_season_middle_context['season']['key'], 'The scoped Reader context lost the stable season key.' );
wptl_test_same( 'Season 1', $wptl_season_middle_context['season']['label'], 'The scoped Reader context lost the season label.' );
wptl_test_same(
	\WPTitleLayer\Core\Series::public_season_archive_url( $wptl_reader_term, 's1' ),
	$wptl_season_middle_context['season']['url'],
	'The scoped Reader context did not use the public season archive URL.'
);

$wptl_season_reader_html = ( new \WPTitleLayer\Reader\Renderer( $wptl_navigation ) )->render( $wptl_reader_ids[1] );
wptl_test_assert( false !== strpos( $wptl_season_reader_html, 'Season 1' ), 'The current-season navigation card did not identify its season.' );
wptl_test_assert( false !== strpos( $wptl_season_reader_html, 'Read this season from the beginning' ), 'The current-season navigation card retained the ambiguous full-Series start label.' );
wptl_test_assert( false !== strpos( $wptl_season_reader_html, esc_url( \WPTitleLayer\Core\Series::public_season_archive_url( $wptl_reader_term, 's1' ) ) ), 'The navigation card did not link its season label to the filtered archive.' );
wptl_test_assert( false === strpos( $wptl_season_reader_html, 'Reader third' ), 'The current-season navigation card exposed an article from another season.' );

$wptl_missing_season_id = wptl_test_create_post( 'Reader missing-season boundary fixture' );
wp_set_object_terms( $wptl_missing_season_id, array( $wptl_reader_term_id ), 'wptl_series' );
update_post_meta( $wptl_missing_season_id, 'wptl_sequence_position', 30 );
wptl_test_assert( empty( $wptl_navigation->context( $wptl_missing_season_id )['enabled'] ), 'Current-season navigation guessed a boundary for an article without a season.' );
update_post_meta( $wptl_missing_season_id, 'wptl_season_key', 'undefined-season' );
wptl_test_assert( empty( $wptl_navigation->context( $wptl_missing_season_id )['enabled'] ), 'Current-season navigation guessed a boundary for an undefined season.' );
wp_delete_post( $wptl_missing_season_id, true );

update_term_meta( $wptl_reader_term_id, 'wptl_navigation_scope', 'series' );
$wptl_restored_middle_context = $wptl_navigation->context( $wptl_reader_ids[1] );
wptl_test_same( $wptl_reader_ids[2], $wptl_restored_middle_context['next']['id'], 'Returning to Entire Series did not restore cross-season navigation.' );
update_term_meta( $wptl_reader_term_id, 'wptl_navigation_scope', 'unsupported' );
wptl_test_same( 'series', \WPTitleLayer\Core\Series::navigation_scope( $wptl_reader_term ), 'A corrupt navigation scope did not fail to the compatible full-Series behavior.' );
update_term_meta( $wptl_reader_term_id, 'wptl_navigation_scope', 'series' );

$wptl_order_before_status_change = $wptl_navigation->orderedPostIds( $wptl_reader_term );
update_term_meta( $wptl_reader_term_id, 'wptl_series_status', 'paused' );
wptl_test_same(
	$wptl_order_before_status_change,
	$wptl_navigation->orderedPostIds( $wptl_reader_term ),
	'Changing the descriptive Series lifecycle status changed the public reading order.'
);
update_term_meta( $wptl_reader_term_id, 'wptl_series_status', 'ongoing' );

$wptl_reader_taxonomy_object = get_taxonomy( 'wptl_series' );
$wptl_reader_publicly_queryable = $wptl_reader_taxonomy_object instanceof WP_Taxonomy
	? (bool) $wptl_reader_taxonomy_object->publicly_queryable
	: false;
if ( $wptl_reader_taxonomy_object instanceof WP_Taxonomy ) {
	$wptl_reader_taxonomy_object->publicly_queryable = false;
}
$wptl_nonqueryable_context = $wptl_navigation->context( $wptl_reader_ids[1] );
wptl_test_same( '', $wptl_nonqueryable_context['series']['url'], 'Reader linked a Series whose taxonomy was not publicly queryable.' );
if ( $wptl_reader_taxonomy_object instanceof WP_Taxonomy ) {
	$wptl_reader_taxonomy_object->publicly_queryable = $wptl_reader_publicly_queryable;
}

$wptl_filtered_series_url = 'ftp://example.test/series';
$wptl_unsafe_series_link = static function ( $url, $term, $taxonomy ) use ( $wptl_reader_term_id, &$wptl_filtered_series_url ) {
	if ( 'wptl_series' === $taxonomy && $term instanceof WP_Term && $wptl_reader_term_id === (int) $term->term_id ) {
		return $wptl_filtered_series_url;
	}
	return $url;
};
add_filter( 'term_link', $wptl_unsafe_series_link, 10, 3 );
$wptl_non_http_link_context = $wptl_navigation->context( $wptl_reader_ids[1] );
wptl_test_same( '', $wptl_non_http_link_context['series']['url'], 'Reader retained a non-HTTP(S) filtered Series archive protocol.' );
$wptl_filtered_series_url = 'javascript:alert(1)';
$wptl_unsafe_link_context  = $wptl_navigation->context( $wptl_reader_ids[1] );
remove_filter( 'term_link', $wptl_unsafe_series_link, 10 );
wptl_test_same( '', $wptl_unsafe_link_context['series']['url'], 'Reader retained an unsafe filtered Series archive protocol.' );
$wptl_reader_html = ( new \WPTitleLayer\Reader\Renderer( $wptl_navigation ) )->render( $wptl_reader_ids[1] );
wptl_test_assert( false === stripos( $wptl_reader_html, '<script' ), 'Reader navigation did not escape a post title.' );
foreach ( $wptl_reader_hidden as $wptl_reader_hidden_id ) {
	$wptl_hidden_title = (string) get_post_field( 'post_title', $wptl_reader_hidden_id, 'raw' );
	wptl_test_assert( false === strpos( $wptl_reader_html, $wptl_hidden_title ), 'Reader navigation exposed a hidden post title.' );
}

$wptl_template_guard = static function () {
	return '/wordpress/wp-load.php';
};
add_filter( 'wptl_reader_navigation_template', $wptl_template_guard );
$wptl_guarded_template = ( new \WPTitleLayer\Reader\Renderer() )->templatePath();
remove_filter( 'wptl_reader_navigation_template', $wptl_template_guard );
wptl_test_same( 'series-navigation.php', basename( $wptl_guarded_template ), 'A forged Reader template path escaped the plugin/theme allow-list.' );

// Duplicate single-value meta rows must not duplicate archive rows or pagination.
add_post_meta( $wptl_reader_ids[0], 'wptl_season_key', 's2', false );
add_post_meta( $wptl_reader_ids[0], 'wptl_sequence_position', 999, false );
update_term_meta( $wptl_reader_term_id, 'wptl_navigation_scope', 'season' );
wptl_test_same( array_slice( $wptl_reader_ids, 0, 2 ), $wptl_navigation->scopedPostIds( $wptl_reader_term, 's1' ), 'Duplicate season meta changed the canonical current-season navigation collection.' );
wptl_test_same( array_slice( $wptl_reader_ids, 2, 1 ), $wptl_navigation->scopedPostIds( $wptl_reader_term, 's2' ), 'Duplicate season meta leaked an article across the navigation boundary.' );
update_term_meta( $wptl_reader_term_id, 'wptl_navigation_scope', 'series' );
$wptl_reader_query_args = array(
	'post_type'              => 'post',
	'post_status'            => 'publish',
	'has_password'           => false,
	'fields'                 => 'ids',
	'posts_per_page'         => 2,
	'ignore_sticky_posts'    => true,
	'tax_query'              => array(
		array( 'taxonomy' => 'wptl_series', 'field' => 'term_id', 'terms' => array( $wptl_reader_term_id ) ),
	),
	'wptl_series_ordering'   => array(
		'term_id'     => $wptl_reader_term_id,
		'mode'        => 'ordered',
		'structure'   => 'seasoned',
		'archive_sort'=> 'date_desc',
	),
);
$wptl_reader_page_one = new WP_Query( array_merge( $wptl_reader_query_args, array( 'paged' => 1 ) ) );
$wptl_reader_page_two = new WP_Query( array_merge( $wptl_reader_query_args, array( 'paged' => 2 ) ) );
wptl_test_same( 3, (int) $wptl_reader_page_one->found_posts, 'Duplicate Series meta inflated archive found_posts.' );
wptl_test_same( array_slice( $wptl_reader_ids, 0, 2 ), array_map( 'intval', $wptl_reader_page_one->posts ), 'Duplicate Series meta broke archive page one.' );
wptl_test_same( array_slice( $wptl_reader_ids, 2, 1 ), array_map( 'intval', $wptl_reader_page_two->posts ), 'Duplicate Series meta broke archive page two.' );

// A season link filters by the canonical first meta row without duplicating posts.
$wptl_reader_season_one = new WP_Query(
	array_merge(
		$wptl_reader_query_args,
		array(
			'posts_per_page'       => -1,
			'wptl_series_ordering' => array_merge( $wptl_reader_query_args['wptl_series_ordering'], array( 'season_key' => 's1' ) ),
		)
	)
);
$wptl_reader_season_two = new WP_Query(
	array_merge(
		$wptl_reader_query_args,
		array(
			'posts_per_page'       => -1,
			'wptl_series_ordering' => array_merge( $wptl_reader_query_args['wptl_series_ordering'], array( 'season_key' => 's2' ) ),
		)
	)
);
wptl_test_same( array_slice( $wptl_reader_ids, 0, 2 ), array_map( 'intval', $wptl_reader_season_one->posts ), 'The first season archive returned the wrong articles.' );
wptl_test_same( array_slice( $wptl_reader_ids, 2, 1 ), array_map( 'intval', $wptl_reader_season_two->posts ), 'A duplicate non-canonical season row leaked an article into another season.' );

$wptl_archive_service = new \WPTitleLayer\Reader\Archive();
$wptl_reader_season_two->set( 'wptl_season', 's2' );
$wptl_season_view = $wptl_archive_service->context( $wptl_reader_term, $wptl_reader_season_two );
wptl_test_same( 'Season 2', $wptl_season_view['active_season']['label'] ?? '', 'The structured archive did not identify its active season.' );
wptl_test_same( \WPTitleLayer\Core\Series::public_archive_url( $wptl_reader_term ), $wptl_season_view['series_url'], 'The season view lost its all-seasons return URL.' );
wptl_test_same( 'ongoing', $wptl_season_view['status'] ?? '', 'The structured archive context lost the explicit Series lifecycle status.' );
wptl_test_same( 'Ongoing', $wptl_season_view['status_label'] ?? '', 'The structured archive context lost the lifecycle status label.' );
wptl_test_same( $wptl_series_parent_category_id, (int) ( $wptl_season_view['parent_category']['id'] ?? 0 ), 'The structured archive context lost its parent Category.' );
wptl_test_assert( ! empty( $wptl_season_view['show_featured_images'] ), 'The structured archive context ignored the per-Series featured-image override.' );
wptl_test_assert( empty( $wptl_season_view['show_subtitles'] ), 'The structured archive context ignored the per-Series Subtitle override.' );
wptl_test_assert( ! empty( $wptl_season_view['show_excerpts'] ), 'The structured archive context ignored the per-Series Excerpt override.' );
$wptl_season_view_posts = ! empty( $wptl_season_view['groups'][0]['posts'] ) ? $wptl_season_view['groups'][0]['posts'] : array();
wptl_test_same( 'Structured excerpt sentinel', $wptl_season_view_posts[0]['excerpt'] ?? '', 'The structured archive did not expose a plain-text WordPress excerpt.' );
wptl_test_same(
	'',
	$wptl_archive_service->postContext( $wptl_reader_ids[2], 'ordered', 'seasoned', false, false )['excerpt'] ?? '',
	'Disabling structured excerpts still computed or exposed article excerpt text.'
);
wptl_test_same(
	is_wp_error( $wptl_reader_thumbnail_id ) ? 0 : (int) $wptl_reader_thumbnail_id,
	(int) ( $wptl_season_view_posts[0]['featured_image_id'] ?? 0 ),
	'The structured archive did not expose the article featured image selected by the Series policy.'
);
$wptl_paged_query = new WP_Query();
$wptl_paged_query->max_num_pages = 3;
$wptl_old_paged_query_var = get_query_var( 'paged', 1 );
set_query_var( 'paged', 2 );
$wptl_archive_pagination = $wptl_archive_service->pagination( $wptl_paged_query );
set_query_var( 'paged', $wptl_old_paged_query_var );
wptl_test_assert(
	false !== strpos( $wptl_archive_pagination, 'Previous page' )
	&& false !== strpos( $wptl_archive_pagination, 'Next page' )
	&& false !== strpos( $wptl_archive_pagination, '&lsaquo;' )
	&& false !== strpos( $wptl_archive_pagination, '&rsaquo;' )
	&& 2 === substr_count( $wptl_archive_pagination, 'screen-reader-text' ),
	'The structured archive pagination reused article-navigation labels.'
);

$wptl_unordered_term_id = wptl_test_create_term( 'WPTL Reader Unordered', 'wptl_series' );
$wptl_unordered_post_id = wptl_test_create_post( 'Reader unordered article' );
wp_set_object_terms( $wptl_unordered_post_id, array( $wptl_unordered_term_id ), 'wptl_series' );
$wptl_unordered_term = get_term( $wptl_unordered_term_id, 'wptl_series' );
wptl_test_same( array(), $wptl_navigation->orderedPostIds( $wptl_unordered_term ), 'Unordered Series exposed a reading sequence.' );
wptl_test_same( '', ( new \WPTitleLayer\Reader\Renderer() )->render( $wptl_unordered_post_id ), 'Unordered Series rendered previous/next navigation.' );

$wptl_flat_scope_term_id = wptl_test_create_term( 'WPTL Reader Ordered Flat Scope', 'wptl_series' );
update_term_meta( $wptl_flat_scope_term_id, 'wptl_series_mode', 'ordered' );
update_term_meta( $wptl_flat_scope_term_id, 'wptl_series_structure', 'flat' );
update_term_meta( $wptl_flat_scope_term_id, 'wptl_navigation_scope', 'season' );
wptl_test_same( 'series', \WPTitleLayer\Core\Series::navigation_scope( $wptl_flat_scope_term_id ), 'A flat Series applied a season boundary without seasons.' );
wp_delete_term( $wptl_flat_scope_term_id, 'wptl_series' );

/* Theme archive sorting wins over a later theme callback for unordered Series. */
$wptl_theme_sort_override = static function ( $query ) {
	if ( $query instanceof WP_Query && $query->get( 'wptl_sort_fixture' ) ) {
		$query->set( 'orderby', 'date' );
		$query->set( 'order', 'ASC' );
	}
};
add_action( 'pre_get_posts', $wptl_theme_sort_override, 9999 );
$wptl_old_main_query = $GLOBALS['wp_the_query'] ?? null;
update_term_meta( $wptl_unordered_term_id, 'wptl_archive_sort', 'date_desc' );
$wptl_sort_query = new WP_Query();
$wptl_sort_query->is_tax = true;
$wptl_sort_query->queried_object = $wptl_unordered_term;
$wptl_sort_query->set( 'wptl_sort_fixture', true );
$GLOBALS['wp_the_query'] = $wptl_sort_query;
do_action( 'pre_get_posts', $wptl_sort_query );
wptl_test_same( 'DESC', $wptl_sort_query->get( 'order' ), 'Theme archive mode did not honor newest-first after a theme changed the query.' );
update_term_meta( $wptl_unordered_term_id, 'wptl_archive_sort', 'date_asc' );
$wptl_sort_query_asc = new WP_Query();
$wptl_sort_query_asc->is_tax = true;
$wptl_sort_query_asc->queried_object = $wptl_unordered_term;
$wptl_sort_query_asc->set( 'wptl_sort_fixture', true );
$GLOBALS['wp_the_query'] = $wptl_sort_query_asc;
do_action( 'pre_get_posts', $wptl_sort_query_asc );
wptl_test_same( 'ASC', $wptl_sort_query_asc->get( 'order' ), 'Theme archive mode did not honor oldest-first.' );
$GLOBALS['wp_the_query'] = $wptl_old_main_query;
remove_action( 'pre_get_posts', $wptl_theme_sort_override, 9999 );

/* Migration locks are token-owned and an old worker cannot release a successor. */
$wptl_lock_store  = new \WPTitleLayer\Migration\Store();
$wptl_lock_run_id = \WPTitleLayer\Migration\Config::new_run_id();
$wptl_lock_one    = $wptl_lock_store->acquire_lock( $wptl_lock_run_id, 300 );
wptl_test_assert( is_string( $wptl_lock_one ) && '' !== $wptl_lock_one, 'Could not acquire the first migration lock.' );
wptl_test_assert( false === $wptl_lock_store->acquire_lock( $wptl_lock_run_id, 300 ), 'A second worker acquired a live migration lock.' );
wptl_test_assert( false === $wptl_lock_store->release_lock( $wptl_lock_run_id, 'not-the-owner' ), 'A non-owner released the migration lock.' );
wptl_test_assert( $wptl_lock_store->refresh_lock( $wptl_lock_run_id, $wptl_lock_one, 300 ), 'The lock owner could not refresh a newly acquired lock.' );

$wptl_expired_lock = get_option( \WPTitleLayer\Migration\Config::lock_option( $wptl_lock_run_id ) );
$wptl_expired_lock['expires'] = time() - 1;
update_option( \WPTitleLayer\Migration\Config::lock_option( $wptl_lock_run_id ), $wptl_expired_lock, false );
$wptl_lock_two = $wptl_lock_store->acquire_lock( $wptl_lock_run_id, 300 );
wptl_test_assert( is_string( $wptl_lock_two ) && $wptl_lock_one !== $wptl_lock_two, 'An expired lock was not replaced with a new owner token.' );
wptl_test_assert( false === $wptl_lock_store->release_lock( $wptl_lock_run_id, $wptl_lock_one ), 'The expired worker released the successor lock.' );
wptl_test_same(
	$wptl_lock_two,
	(string) ( get_option( \WPTitleLayer\Migration\Config::lock_option( $wptl_lock_run_id ) )['token'] ?? '' ),
	'The successor lock changed after an old worker attempted release.'
);
wptl_test_assert( $wptl_lock_store->release_lock( $wptl_lock_run_id, $wptl_lock_two ), 'The current lock owner could not release its lock.' );

$wptl_active_run_one = \WPTitleLayer\Migration\Config::new_run_id();
$wptl_active_run_two = \WPTitleLayer\Migration\Config::new_run_id();
$wptl_lock_store->save_option( \WPTitleLayer\Migration\Config::ACTIVE_RUN_OPTION, $wptl_active_run_two );
$wptl_lock_store->clear_active_run( $wptl_active_run_one );
wptl_test_same(
	$wptl_active_run_two,
	get_option( \WPTitleLayer\Migration\Config::ACTIVE_RUN_OPTION ),
	'Completing an older migration removed a newer active-run marker.'
);
$wptl_lock_store->clear_active_run( $wptl_active_run_two );
wptl_test_same( false, get_option( \WPTitleLayer\Migration\Config::ACTIVE_RUN_OPTION, false ), 'The owner could not clear its active-run marker.' );

/* A run must not overwrite another worker's active marker. */
$wptl_blocking_marker = \WPTitleLayer\Migration\Config::new_run_id();
$wptl_lock_store->save_option( \WPTitleLayer\Migration\Config::ACTIVE_RUN_OPTION, $wptl_blocking_marker );
$wptl_failed_run_id = \WPTitleLayer\Migration\Config::new_run_id();
$wptl_failed_run = array(
	'id'         => $wptl_failed_run_id,
	'status'     => 'running',
	'next_batch' => 1,
	'counts'     => array(),
	'rollback'   => null,
);
wptl_test_assert( ! $wptl_lock_store->add_run( $wptl_failed_run ), 'A run with a failed active-marker write was reported as runnable.' );
$wptl_failed_run = $wptl_lock_store->get_run( $wptl_failed_run_id );
wptl_test_same( 'initialization_failed', $wptl_failed_run['status'] ?? '', 'A failed run initialization was left in running state.' );
$wptl_test_same_marker = get_option( \WPTitleLayer\Migration\Config::ACTIVE_RUN_OPTION, '' );
wptl_test_same( $wptl_blocking_marker, $wptl_test_same_marker, 'A failed run initialization overwrote another worker\'s active marker.' );

/* A stale read must fail the final active-marker CAS rather than overwrite. */
$wptl_stale_active_read = static function () {
	return '';
};
add_filter( 'pre_option_' . \WPTitleLayer\Migration\Config::ACTIVE_RUN_OPTION, $wptl_stale_active_read );
$wptl_cas_failed_run_id = \WPTitleLayer\Migration\Config::new_run_id();
$wptl_cas_failed_run    = array(
	'id'         => $wptl_cas_failed_run_id,
	'status'     => 'running',
	'next_batch' => 1,
	'counts'     => array(),
	'rollback'   => null,
);
wptl_test_assert( ! $wptl_lock_store->add_run( $wptl_cas_failed_run ), 'A stale active-marker read bypassed the final compare-and-swap.' );
remove_filter( 'pre_option_' . \WPTitleLayer\Migration\Config::ACTIVE_RUN_OPTION, $wptl_stale_active_read );
$wptl_cas_failed_run = $wptl_lock_store->get_run( $wptl_cas_failed_run_id );
wptl_test_same( 'initialization_failed', $wptl_cas_failed_run['status'] ?? '', 'A failed active-marker CAS left a runnable journal.' );
wptl_test_same( $wptl_blocking_marker, get_option( \WPTitleLayer\Migration\Config::ACTIVE_RUN_OPTION, '' ), 'A failed active-marker CAS changed the real marker.' );
$wptl_lock_store->clear_active_run( $wptl_blocking_marker );

/* The global control lock uses the same token-owned replacement rules. */
$wptl_global_one = $wptl_lock_store->acquire_global_lock( 300 );
wptl_test_assert( is_string( $wptl_global_one ) && '' !== $wptl_global_one, 'Could not acquire the migration control lock.' );
wptl_test_assert( false === $wptl_lock_store->acquire_global_lock( 300 ), 'A second worker acquired a live migration control lock.' );
$wptl_expired_global = get_option( \WPTitleLayer\Migration\Config::GLOBAL_LOCK_OPTION );
$wptl_expired_global['expires'] = time() - 1;
update_option( \WPTitleLayer\Migration\Config::GLOBAL_LOCK_OPTION, $wptl_expired_global, false );
$wptl_global_two = $wptl_lock_store->acquire_global_lock( 300 );
wptl_test_assert( is_string( $wptl_global_two ) && $wptl_global_one !== $wptl_global_two, 'An expired migration control lock was not replaced.' );
wptl_test_assert( false === $wptl_lock_store->release_global_lock( $wptl_global_one ), 'An expired control worker released the successor lock.' );
wptl_test_assert( $wptl_lock_store->release_global_lock( $wptl_global_two ), 'The current control worker could not release its lock.' );

/* Losing the control lease after claiming active cannot expose a runnable run. */
$wptl_claim_lock = $wptl_lock_store->acquire_global_lock( 300 );
$wptl_claim_run_id = \WPTitleLayer\Migration\Config::new_run_id();
$wptl_successor_token = \WPTitleLayer\Migration\Config::new_run_id() . \WPTitleLayer\Migration\Config::new_run_id();
$wptl_takeover_after_claim = static function ( $option, $value ) use ( $wptl_claim_run_id, $wptl_successor_token ): void {
	if ( \WPTitleLayer\Migration\Config::ACTIVE_RUN_OPTION !== $option || $wptl_claim_run_id !== $value ) {
		return;
	}
	update_option(
		\WPTitleLayer\Migration\Config::GLOBAL_LOCK_OPTION,
		array( 'token' => $wptl_successor_token, 'expires' => time() + 300 ),
		false
	);
};
add_action( 'added_option', $wptl_takeover_after_claim, 10, 2 );
$wptl_claim_run = array(
	'id'         => $wptl_claim_run_id,
	'status'     => 'running',
	'next_batch' => 1,
	'counts'     => array(),
	'rollback'   => null,
);
wptl_test_assert( ! $wptl_lock_store->add_run( $wptl_claim_run, (string) $wptl_claim_lock ), 'A run reported success after losing the control lock at active claim.' );
remove_action( 'added_option', $wptl_takeover_after_claim, 10 );
wptl_test_same( 'initialization_failed', (string) ( $wptl_lock_store->get_run( $wptl_claim_run_id )['status'] ?? '' ), 'A post-claim lock loss left a runnable journal.' );
wptl_test_same( false, get_option( \WPTitleLayer\Migration\Config::ACTIVE_RUN_OPTION, false ), 'A post-claim lock loss left its active marker behind.' );
wptl_test_same( $wptl_successor_token, (string) ( get_option( \WPTitleLayer\Migration\Config::GLOBAL_LOCK_OPTION )['token'] ?? '' ), 'The old worker changed the successor control lock.' );
wptl_test_assert( $wptl_lock_store->release_global_lock( $wptl_successor_token ), 'Could not release the successor control-lock fixture.' );

/* A running journal without the matching active marker cannot continue. */
$wptl_inactive_run_id = \WPTitleLayer\Migration\Config::new_run_id();
$wptl_inactive_run = array(
	'id'              => $wptl_inactive_run_id,
	'status'          => 'running',
	'next_batch'      => 1,
	'cursor'          => 0,
	'high_water_mark' => 0,
	'batch_size'      => 1,
	'counts'          => array(),
	'rollback'        => null,
);
wptl_test_assert( $wptl_lock_store->add_run( $wptl_inactive_run ), 'Could not create the inactive-run safety fixture.' );
$wptl_replacement_marker = \WPTitleLayer\Migration\Config::new_run_id();
$wptl_lock_store->save_option( \WPTitleLayer\Migration\Config::ACTIVE_RUN_OPTION, $wptl_replacement_marker );
$wptl_inactive_result = ( new \WPTitleLayer\Migration\Migrator( null, $wptl_lock_store ) )->process_batch( $wptl_inactive_run_id );
wptl_test_error_code( 'wptl_migration_inactive', $wptl_inactive_result, 'An inactive migration journal was allowed to continue.' );
$wptl_inactive_run['status'] = 'abandoned';
$wptl_lock_store->save_run( $wptl_inactive_run );
$wptl_lock_store->clear_active_run( $wptl_replacement_marker );

/* A pre-manifest active run fails closed instead of being misreported complete. */
$wptl_legacy_run_id = \WPTitleLayer\Migration\Config::new_run_id();
$wptl_legacy_run = array(
	'id'              => $wptl_legacy_run_id,
	'status'          => 'running',
	'next_batch'      => 1,
	'cursor'          => 0,
	'high_water_mark' => 0,
	'batch_size'      => 1,
	'counts'          => array(),
	'rollback'        => null,
);
wptl_test_assert( $wptl_lock_store->add_run( $wptl_legacy_run ), 'Could not create the pre-manifest migration fixture.' );
$wptl_legacy_result = ( new \WPTitleLayer\Migration\Migrator( null, $wptl_lock_store ) )->process_batch( $wptl_legacy_run_id );
wptl_test_error_code( 'wptl_migration_legacy_run_requires_rollback', $wptl_legacy_result, 'A pre-manifest migration run was allowed to continue.' );
wptl_test_same( 'running', (string) ( $wptl_lock_store->get_run( $wptl_legacy_run_id )['status'] ?? '' ), 'A pre-manifest run was falsely marked complete.' );
$wptl_legacy_run['status'] = 'abandoned';
$wptl_lock_store->save_run( $wptl_legacy_run );
$wptl_lock_store->clear_active_run( $wptl_legacy_run_id );

/* Migration fixtures: old plugin, ACF, source conflict, and target conflict. */
$wptl_acf_field_id = wp_insert_post(
	array(
		'post_type'    => 'acf-field',
		'post_status'  => 'publish',
		'post_title'   => 'Subtitle',
		'post_name'    => 'field_wptl_subtitle_test',
		'post_excerpt' => 'subtitle',
	),
	true
);
wptl_test_assert( ! is_wp_error( $wptl_acf_field_id ), 'Could not create the verified ACF field definition fixture.' );

foreach ( \WPTitleLayer\Migration\Config::ACF_SERIES_FIELDS as $wptl_acf_series_field ) {
	$wptl_acf_series_field_id = wp_insert_post(
		array(
			'post_type'    => 'acf-field',
			'post_status'  => 'publish',
			'post_title'   => ucwords( str_replace( '_', ' ', $wptl_acf_series_field ) ),
			'post_name'    => 'field_wptl_' . $wptl_acf_series_field . '_test',
			'post_excerpt' => $wptl_acf_series_field,
		),
		true
	);
	wptl_test_assert( ! is_wp_error( $wptl_acf_series_field_id ), 'Could not create an ACF Series field definition fixture.' );
}

$wptl_environment_report = ( new \WPTitleLayer\Migration\Detector() )->detect();
foreach ( \WPTitleLayer\Migration\Config::ACF_SERIES_FIELDS as $wptl_acf_series_field ) {
	$wptl_acf_series_audit = $wptl_environment_report['acf']['series_fields'][ $wptl_acf_series_field ];
	wptl_test_same( 1, (int) $wptl_acf_series_audit['definition_count'], 'The migration report missed an ACF Series field definition.' );
	wptl_test_same( 0, (int) $wptl_acf_series_audit['value_posts'], 'An unused ACF Series field was reported as containing values.' );
}

/* Stored zeros are footprint, not proof that the old Series fields were used. */
$wptl_acf_default_only_id = wptl_test_create_post( 'ACF default-only Series fields' );
update_post_meta( $wptl_acf_default_only_id, 'series_order', '0' );
update_post_meta( $wptl_acf_default_only_id, '_series_order', 'field_wptl_series_order_test' );
update_post_meta( $wptl_acf_default_only_id, 'series_has_order', '0' );
update_post_meta( $wptl_acf_default_only_id, '_series_has_order', 'field_wptl_series_has_order_test' );
$wptl_acf_empty_only_id = wptl_test_create_post( 'ACF empty-only Series field' );
update_post_meta( $wptl_acf_empty_only_id, 'series_key', '' );

$wptl_acf_usage_report = ( new \WPTitleLayer\Migration\Detector() )->detect();
wptl_test_same( 0, (int) $wptl_acf_usage_report['acf']['series_fields']['series_key']['value_posts'], 'The ACF audit unexpectedly counted an exact empty-string value.' );
wptl_test_same( 1, (int) $wptl_acf_usage_report['acf']['series_fields']['series_order']['value_posts'], 'The ACF audit hid a stored numeric default.' );
wptl_test_same( 1, (int) $wptl_acf_usage_report['acf']['series_fields']['series_has_order']['value_posts'], 'The ACF audit hid a stored false default.' );
wptl_test_same( 1, (int) $wptl_acf_usage_report['acf']['series_fields']['series_order']['verified_value_posts'], 'The ACF audit did not verify a stored zero assignment.' );
wptl_test_same( 1, (int) $wptl_acf_usage_report['acf']['series_value_posts'], 'The ACF audit counted one post once for two stored fields.' );

wp_delete_post( $wptl_acf_default_only_id, true );
wp_delete_post( $wptl_acf_empty_only_id, true );

$wptl_migrate_legacy_id = wptl_test_create_post( 'Migrate legacy source' );
update_post_meta( $wptl_migrate_legacy_id, '_secondary_title', 'Copied from legacy' );

$wptl_migrate_acf_id = wptl_test_create_post( 'Migrate ACF source' );
update_post_meta( $wptl_migrate_acf_id, 'subtitle', 'Copied from ACF' );
update_post_meta( $wptl_migrate_acf_id, '_subtitle', 'field_wptl_subtitle_test' );

$wptl_source_conflict_id = wptl_test_create_post( 'Conflicting sources' );
update_post_meta( $wptl_source_conflict_id, '_secondary_title', 'Legacy disagrees' );
update_post_meta( $wptl_source_conflict_id, 'subtitle', 'ACF disagrees' );
update_post_meta( $wptl_source_conflict_id, '_subtitle', 'field_wptl_subtitle_test' );

$wptl_target_conflict_id = wptl_test_create_post( 'Existing target conflict' );
update_post_meta( $wptl_target_conflict_id, '_secondary_title', 'Incoming value' );
update_post_meta( $wptl_target_conflict_id, 'wptl_subtitle', 'Existing value' );

// A generic field named `subtitle` is not ACF data without its `field_*`
// reference row. This protects unrelated plugins and hand-authored metadata.
$wptl_non_acf_id = wptl_test_create_post( 'Non-ACF subtitle metadata' );
update_post_meta( $wptl_non_acf_id, 'subtitle', 'Must not be imported' );

$wptl_unverified_acf_id = wptl_test_create_post( 'Unverified ACF reference' );
update_post_meta( $wptl_unverified_acf_id, 'subtitle', 'Must remain a conflict' );
update_post_meta( $wptl_unverified_acf_id, '_subtitle', 'field_missing_definition' );

/* State-drift fixtures are all present before the reviewed scan. */
$wptl_new_source_after_scan_id = wptl_test_create_post( 'Source added after scan' );

$wptl_source_changed_after_scan_id = wptl_test_create_post( 'Source changed after scan' );
update_post_meta( $wptl_source_changed_after_scan_id, '_secondary_title', 'Reviewed source value' );

$wptl_target_changed_after_scan_id = wptl_test_create_post( 'Target changed after scan' );
update_post_meta( $wptl_target_changed_after_scan_id, '_secondary_title', 'Reviewed incoming value' );

$wptl_trust_changed_after_scan_id = wptl_test_create_post( 'ACF trust changed after scan' );
update_post_meta( $wptl_trust_changed_after_scan_id, 'subtitle', 'Was not trusted at scan time' );
update_post_meta( $wptl_trust_changed_after_scan_id, '_subtitle', 'field_trusted_after_scan' );

$wptl_eligibility_changed_after_scan_id = wptl_test_create_post( 'Eligibility changed after scan' );
update_post_meta( $wptl_eligibility_changed_after_scan_id, '_secondary_title', 'Reviewed eligible value' );

$wptl_source_resolver = new \WPTitleLayer\Migration\SourceResolver();
$wptl_inspection      = $wptl_source_resolver->inspect( $wptl_source_conflict_id );
wptl_test_same( 'conflict', $wptl_inspection['status'], 'Disagreeing legacy and ACF values were not marked as a conflict.' );
wptl_test_same( 'sources_disagree', $wptl_inspection['reason'], 'The source conflict reason was not retained.' );

$wptl_inspection = $wptl_source_resolver->inspect( $wptl_target_conflict_id );
wptl_test_same( 'conflict', $wptl_inspection['status'], 'An existing different canonical value was not marked as a conflict.' );
wptl_test_same( 'target_exists_different', $wptl_inspection['reason'], 'The target conflict reason was not retained.' );

$wptl_inspection = $wptl_source_resolver->inspect( $wptl_unverified_acf_id );
wptl_test_same( 'conflict', $wptl_inspection['status'], 'An unverified ACF field reference was not marked as a conflict.' );
wptl_test_same( 'unverified_acf_field_reference', $wptl_inspection['reason'], 'The unverified ACF reference reason was not retained.' );

/* A scan that loses its control lease cannot commit or strand its manifest. */
global $wpdb;
$wptl_manifest_like = $wpdb->esc_like( \WPTitleLayer\Migration\Config::SCAN_MANIFEST_PREFIX ) . '%';
$wptl_manifest_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wptl_manifest_like ) );
$wptl_scan_lock_successor = \WPTitleLayer\Migration\Config::new_run_id() . \WPTitleLayer\Migration\Config::new_run_id();
$wptl_takeover_scan_lock = static function ( $option ) use ( $wptl_scan_lock_successor ): void {
	if ( 0 !== strpos( (string) $option, \WPTitleLayer\Migration\Config::SCAN_MANIFEST_PREFIX ) ) {
		return;
	}
	update_option(
		\WPTitleLayer\Migration\Config::GLOBAL_LOCK_OPTION,
		array( 'token' => $wptl_scan_lock_successor, 'expires' => time() + 300 ),
		false
	);
};
add_action( 'added_option', $wptl_takeover_scan_lock, 10, 1 );
$wptl_failed_snapshot = ( new \WPTitleLayer\Migration\ScanSnapshot() )->create();
remove_action( 'added_option', $wptl_takeover_scan_lock, 10 );
wptl_test_error_code( 'wptl_migration_scan_write_failed', $wptl_failed_snapshot, 'A scan committed after losing its control lock.' );
wptl_test_same( $wptl_scan_lock_successor, (string) ( get_option( \WPTitleLayer\Migration\Config::GLOBAL_LOCK_OPTION )['token'] ?? '' ), 'The failed scan changed its successor control lock.' );
wptl_test_same( $wptl_manifest_before, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wptl_manifest_like ) ), 'A failed scan stranded a manifest chunk.' );
wptl_test_assert( $wptl_lock_store->release_global_lock( $wptl_scan_lock_successor ), 'Could not release the failed-scan successor lock fixture.' );

$wptl_scan = ( new \WPTitleLayer\Migration\ScanSnapshot() )->create();
wptl_test_assert( ! is_wp_error( $wptl_scan ), 'Could not persist the reviewed migration snapshot.' );
wptl_test_same( 9, $wptl_scan['stats']['candidate_posts'], 'The dry run did not find all migration candidates.' );
wptl_test_same( 5, $wptl_scan['stats']['ready'], 'The dry run did not identify all safe copies.' );
wptl_test_same( 4, $wptl_scan['stats']['conflicts'], 'The dry run did not report all conflicts.' );
wptl_test_same( 9, (int) $wptl_scan['manifest_count'], 'The frozen manifest count did not match the reviewed candidates.' );
wptl_test_assert(
	( new \WPTitleLayer\Migration\Store() )->verify_manifest( $wptl_scan['scan_id'], $wptl_scan['manifest_chunks'], $wptl_scan['manifest_count'], $wptl_scan['manifest_hash'] ),
	'The persisted migration manifest did not verify.'
);
wptl_test_assert( \WPTitleLayer\Migration\AdminPage::canStartFromScan( $wptl_scan ), 'A complete compatible dry run was not accepted for migration.' );
$wptl_truncated_scan              = $wptl_scan;
$wptl_truncated_scan['truncated'] = true;
wptl_test_assert( ! \WPTitleLayer\Migration\AdminPage::canStartFromScan( $wptl_truncated_scan ), 'A truncated dry run was allowed to authorize migration writes.' );
$wptl_incomplete_manifest_scan = $wptl_scan;
unset( $wptl_incomplete_manifest_scan['manifest_count'] );
wptl_test_assert( ! \WPTitleLayer\Migration\AdminPage::canStartFromScan( $wptl_incomplete_manifest_scan ), 'A scan missing its manifest count was allowed to authorize migration writes.' );

/* Changes after review must never become newly authorized writes. */
update_post_meta( $wptl_new_source_after_scan_id, '_secondary_title', 'Not present in reviewed candidates' );
update_post_meta( $wptl_source_changed_after_scan_id, '_secondary_title', 'Changed after review' );
update_post_meta( $wptl_target_changed_after_scan_id, 'wptl_subtitle', 'Target created after review' );
$wptl_late_acf_field_id = wp_insert_post(
	array(
		'post_type'    => 'acf-field',
		'post_status'  => 'publish',
		'post_title'   => 'Late trusted subtitle',
		'post_name'    => 'field_trusted_after_scan',
		'post_excerpt' => 'subtitle',
	),
	true
);
wptl_test_assert( ! is_wp_error( $wptl_late_acf_field_id ), 'Could not create the post-scan ACF trust fixture.' );
wp_update_post( array( 'ID' => $wptl_eligibility_changed_after_scan_id, 'post_status' => 'auto-draft' ) );

$wptl_migrator = new \WPTitleLayer\Migration\Migrator();
$wptl_run      = $wptl_migrator->start_run(
	array(
		'batch_size'      => 2,
		'high_water_mark' => (int) $wptl_scan['high_water_mark'],
		'scan_id'         => $wptl_scan['scan_id'],
		'manifest_count'  => $wptl_scan['manifest_count'],
		'manifest_chunks' => $wptl_scan['manifest_chunks'],
		'manifest_hash'   => $wptl_scan['manifest_hash'],
	)
);
wptl_test_assert( ! is_wp_error( $wptl_run ), 'Could not start the migration run.' );
wptl_test_same( (int) $wptl_scan['high_water_mark'], (int) ( $wptl_run['high_water_mark'] ?? -1 ), 'The migration did not freeze the reviewed scan high-water mark.' );

if ( is_array( $wptl_run ) ) {
	$wptl_run = wptl_test_finish_migration( $wptl_migrator, $wptl_run );
}

wptl_test_assert( is_array( $wptl_run ), 'The migration run returned an unexpected error.' );
if ( is_array( $wptl_run ) ) {
	wptl_test_same( 'complete', $wptl_run['status'], 'The migration run did not reach completion.' );
	wptl_test_same( 2, (int) $wptl_run['counts']['migrated'], 'The migration did not copy exactly two safe values.' );
	wptl_test_same( 7, (int) $wptl_run['counts']['conflict'], 'The migration did not journal original and post-scan state conflicts.' );
}

wptl_test_same(
	'Copied from legacy',
	get_post_meta( $wptl_migrate_legacy_id, 'wptl_subtitle', true ),
	'The legacy subtitle was not copied into canonical metadata.'
);
wptl_test_same(
	'Copied from ACF',
	get_post_meta( $wptl_migrate_acf_id, 'wptl_subtitle', true ),
	'The ACF subtitle was not copied into canonical metadata.'
);
wptl_test_same(
	'Legacy disagrees',
	get_post_meta( $wptl_source_conflict_id, '_secondary_title', true ),
	'The migration changed a conflicting legacy source.'
);
wptl_test_assert(
	! metadata_exists( 'post', $wptl_source_conflict_id, 'wptl_subtitle' ),
	'The migration wrote a canonical value despite a source conflict.'
);
wptl_test_same(
	'Existing value',
	get_post_meta( $wptl_target_conflict_id, 'wptl_subtitle', true ),
	'The migration overwrote an existing canonical value.'
);
wptl_test_assert(
	! metadata_exists( 'post', $wptl_non_acf_id, 'wptl_subtitle' ),
	'The migration treated an unreferenced generic subtitle field as ACF data.'
);
wptl_test_assert(
	! metadata_exists( 'post', $wptl_unverified_acf_id, 'wptl_subtitle' ),
	'The migration trusted an ACF reference with no matching field definition.'
);
wptl_test_assert( ! metadata_exists( 'post', $wptl_new_source_after_scan_id, 'wptl_subtitle' ), 'A low-ID source added after the scan entered the frozen run.' );
wptl_test_assert( ! metadata_exists( 'post', $wptl_source_changed_after_scan_id, 'wptl_subtitle' ), 'A source changed after review was copied.' );
wptl_test_same( 'Target created after review', get_post_meta( $wptl_target_changed_after_scan_id, 'wptl_subtitle', true ), 'A target created after review was overwritten.' );
wptl_test_assert( ! metadata_exists( 'post', $wptl_trust_changed_after_scan_id, 'wptl_subtitle' ), 'An ACF conflict that became trusted after review was copied.' );
wptl_test_assert( ! metadata_exists( 'post', $wptl_eligibility_changed_after_scan_id, 'wptl_subtitle' ), 'A post that became ineligible after review was copied.' );
wptl_test_same(
	'Copied from legacy',
	get_post_meta( $wptl_migrate_legacy_id, '_secondary_title', true ),
	'The migration removed the legacy source.'
);
wptl_test_same(
	'field_wptl_subtitle_test',
	get_post_meta( $wptl_migrate_acf_id, '_subtitle', true ),
	'The migration removed the ACF field reference.'
);

/* Rollback deletes only rows owned by this run and preserves every source. */
if ( is_array( $wptl_run ) ) {
	$wptl_rollback = new \WPTitleLayer\Migration\Rollback();
	$wptl_run      = $wptl_rollback->start( $wptl_run['id'] );
	wptl_test_assert( ! is_wp_error( $wptl_run ), 'Could not start migration rollback.' );
	if ( is_array( $wptl_run ) ) {
		$wptl_run = wptl_test_finish_rollback( $wptl_rollback, $wptl_run );
	}
}

wptl_test_assert( is_array( $wptl_run ), 'The rollback returned an unexpected error.' );
if ( is_array( $wptl_run ) ) {
	wptl_test_same( 'rolled_back', $wptl_run['status'], 'The rollback did not reach completion.' );
	wptl_test_same( 2, (int) $wptl_run['rollback']['counts']['deleted'], 'Rollback did not delete exactly the two rows created by the run.' );
}

wptl_test_assert(
	! metadata_exists( 'post', $wptl_migrate_legacy_id, 'wptl_subtitle' ),
	'Rollback did not remove the canonical row copied from legacy data.'
);
wptl_test_assert(
	! metadata_exists( 'post', $wptl_migrate_acf_id, 'wptl_subtitle' ),
	'Rollback did not remove the canonical row copied from ACF data.'
);
wptl_test_same(
	'Existing value',
	get_post_meta( $wptl_target_conflict_id, 'wptl_subtitle', true ),
	'Rollback removed or changed a canonical value it did not own.'
);
wptl_test_same(
	'Copied from legacy',
	get_post_meta( $wptl_migrate_legacy_id, '_secondary_title', true ),
	'Rollback changed the legacy source.'
);
wptl_test_same(
	'Copied from ACF',
	get_post_meta( $wptl_migrate_acf_id, 'subtitle', true ),
	'Rollback changed the ACF source.'
);

/* Empty manifests are explicit and never turn ID zero into an unbounded run. */
$wptl_empty_type_calls = 0;
$wptl_empty_types = static function ( $post_types ) use ( &$wptl_empty_type_calls ): array {
	++$wptl_empty_type_calls;
	return 1 === $wptl_empty_type_calls ? array() : array( 'post' );
};
add_filter( 'wptl_migration_post_types', $wptl_empty_types );
$wptl_empty_scan = ( new \WPTitleLayer\Migration\ScanSnapshot() )->create();
remove_filter( 'wptl_migration_post_types', $wptl_empty_types );
wptl_test_assert( ! is_wp_error( $wptl_empty_scan ), 'Could not save an explicit empty migration snapshot.' );
wptl_test_same( 1, $wptl_empty_type_calls, 'A zero high-water scan made an unbounded follow-up candidate query.' );
wptl_test_same( 0, (int) $wptl_empty_scan['manifest_count'], 'The empty scan did not persist an explicit zero-count manifest.' );
wptl_test_same( 0, (int) $wptl_empty_scan['manifest_chunks'], 'The empty scan unexpectedly created a manifest chunk.' );
$wptl_empty_run = ( new \WPTitleLayer\Migration\Migrator() )->start_run(
	array(
		'scan_id'       => $wptl_empty_scan['scan_id'],
		'batch_size'    => 2,
	)
);
wptl_test_assert( ! is_wp_error( $wptl_empty_run ), 'Could not start an explicit empty-manifest run.' );
if ( is_array( $wptl_empty_run ) ) {
	$wptl_empty_run = wptl_test_finish_migration( new \WPTitleLayer\Migration\Migrator(), $wptl_empty_run );
}
wptl_test_same( 'complete', is_array( $wptl_empty_run ) ? $wptl_empty_run['status'] : '', 'The empty-manifest run did not complete.' );
wptl_test_same( 0, is_array( $wptl_empty_run ) ? (int) $wptl_empty_run['counts']['processed'] : -1, 'The empty manifest expanded into current database candidates.' );

/* If a hook changes the just-added row, the run never claims that new value. */
$wptl_hook_changed_id = wptl_test_create_post( 'Hook changes migration write' );
update_post_meta( $wptl_hook_changed_id, '_secondary_title', 'Migration intended value' );
$wptl_hook_scan = ( new \WPTitleLayer\Migration\ScanSnapshot() )->create();
wptl_test_assert( ! is_wp_error( $wptl_hook_scan ), 'Could not scan the hook-change migration fixture.' );
$wptl_change_added_value = static function ( $meta_id, $post_id, $meta_key ) use ( $wptl_hook_changed_id ): void {
	if ( $wptl_hook_changed_id === (int) $post_id && \WPTitleLayer\Migration\Config::TARGET_META === $meta_key ) {
		update_metadata_by_mid( 'post', (int) $meta_id, 'Value changed by hook' );
	}
};
add_action( 'added_post_meta', $wptl_change_added_value, 10, 3 );
$wptl_hook_run = ( new \WPTitleLayer\Migration\Migrator() )->start_run(
	array(
		'scan_id'    => $wptl_hook_scan['scan_id'],
		'batch_size' => 500,
	)
);
if ( is_array( $wptl_hook_run ) ) {
	$wptl_hook_run = wptl_test_finish_migration( new \WPTitleLayer\Migration\Migrator(), $wptl_hook_run );
}
remove_action( 'added_post_meta', $wptl_change_added_value, 10 );
wptl_test_assert( is_array( $wptl_hook_run ), 'The hook-change migration returned an error.' );
wptl_test_same( 'Value changed by hook', get_post_meta( $wptl_hook_changed_id, 'wptl_subtitle', true ), 'The hook-mutated target value was not retained.' );
if ( is_array( $wptl_hook_run ) ) {
	$wptl_hook_record = array();
	$wptl_hook_store  = new \WPTitleLayer\Migration\Store();
	for ( $wptl_hook_batch = 1; $wptl_hook_batch < (int) $wptl_hook_run['next_batch']; ++$wptl_hook_batch ) {
		$wptl_hook_log = $wptl_hook_store->get_log( $wptl_hook_run['id'], $wptl_hook_batch );
		if ( isset( $wptl_hook_log['records'][ (string) $wptl_hook_changed_id ] ) ) {
			$wptl_hook_record = $wptl_hook_log['records'][ (string) $wptl_hook_changed_id ];
			break;
		}
	}
	wptl_test_same( 'conflict', (string) ( $wptl_hook_record['status'] ?? '' ), 'A hook-mutated target was reported as migrated.' );
	$wptl_hook_rollback = new \WPTitleLayer\Migration\Rollback();
	$wptl_hook_run      = $wptl_hook_rollback->start( $wptl_hook_run['id'] );
	if ( is_array( $wptl_hook_run ) ) {
		$wptl_hook_run = wptl_test_finish_rollback( $wptl_hook_rollback, $wptl_hook_run );
	}
}
wptl_test_same( 'Value changed by hook', get_post_meta( $wptl_hook_changed_id, 'wptl_subtitle', true ), 'Rollback removed a hook-mutated value the run did not own.' );

/* A second target row or source change at a write hook cannot be reported migrated. */
$wptl_multi_target_id = wptl_test_create_post( 'Concurrent second target row' );
update_post_meta( $wptl_multi_target_id, '_secondary_title', 'Run-owned target A' );
$wptl_source_toctou_id = wptl_test_create_post( 'Source changes after intent journal' );
update_post_meta( $wptl_source_toctou_id, '_secondary_title', 'Reviewed source A' );
$wptl_concurrency_scan = ( new \WPTitleLayer\Migration\ScanSnapshot() )->create();
wptl_test_assert( ! is_wp_error( $wptl_concurrency_scan ), 'Could not scan the write-concurrency fixtures.' );

$wptl_second_row_added = false;
$wptl_add_second_target = static function ( $meta_id, $post_id, $meta_key ) use ( $wptl_multi_target_id, &$wptl_second_row_added ): void {
	if ( ! $wptl_second_row_added && $wptl_multi_target_id === (int) $post_id && \WPTitleLayer\Migration\Config::TARGET_META === $meta_key ) {
		$wptl_second_row_added = true;
		add_post_meta( $wptl_multi_target_id, \WPTitleLayer\Migration\Config::TARGET_META, 'Concurrent target B', false );
	}
};
$wptl_source_changed_at_intent = false;
$wptl_change_source_after_intent = static function ( $option, $old_value, $value ) use ( $wptl_source_toctou_id, &$wptl_source_changed_at_intent ): void {
	$record = is_array( $value ) ? ( $value['records'][ (string) $wptl_source_toctou_id ] ?? null ) : null;
	if ( ! $wptl_source_changed_at_intent
		&& 0 === strpos( (string) $option, 'wptl_migration_log_' )
		&& is_array( $record )
		&& 'pending' === (string) ( $record['status'] ?? '' ) ) {
		$wptl_source_changed_at_intent = true;
		update_post_meta( $wptl_source_toctou_id, '_secondary_title', 'Source changed to B' );
	}
};
add_action( 'added_post_meta', $wptl_add_second_target, 10, 3 );
add_action( 'updated_option', $wptl_change_source_after_intent, 10, 3 );
$wptl_concurrency_run = ( new \WPTitleLayer\Migration\Migrator() )->start_run(
	array(
		'scan_id'    => $wptl_concurrency_scan['scan_id'],
		'batch_size' => 500,
	)
);
if ( is_array( $wptl_concurrency_run ) ) {
	$wptl_concurrency_run = wptl_test_finish_migration( new \WPTitleLayer\Migration\Migrator(), $wptl_concurrency_run );
}
remove_action( 'added_post_meta', $wptl_add_second_target, 10 );
remove_action( 'updated_option', $wptl_change_source_after_intent, 10 );
wptl_test_assert( is_array( $wptl_concurrency_run ), 'The write-concurrency migration returned an error.' );
wptl_test_assert( $wptl_second_row_added, 'The concurrent second-target hook did not run.' );
wptl_test_assert( $wptl_source_changed_at_intent, 'The post-intent source-change hook did not run.' );

$wptl_multi_record  = array();
$wptl_toctou_record = array();
if ( is_array( $wptl_concurrency_run ) ) {
	$wptl_concurrency_store = new \WPTitleLayer\Migration\Store();
	for ( $wptl_batch = 1; $wptl_batch < (int) $wptl_concurrency_run['next_batch']; ++$wptl_batch ) {
		$wptl_batch_log = $wptl_concurrency_store->get_log( $wptl_concurrency_run['id'], $wptl_batch );
		if ( isset( $wptl_batch_log['records'][ (string) $wptl_multi_target_id ] ) ) {
			$wptl_multi_record = $wptl_batch_log['records'][ (string) $wptl_multi_target_id ];
		}
		if ( isset( $wptl_batch_log['records'][ (string) $wptl_source_toctou_id ] ) ) {
			$wptl_toctou_record = $wptl_batch_log['records'][ (string) $wptl_source_toctou_id ];
		}
	}
}
wptl_test_same( 'conflict', (string) ( $wptl_multi_record['status'] ?? '' ), 'A second target row was reported as migrated.' );
wptl_test_assert( ! empty( $wptl_multi_record['owned_write'] ) && ! empty( $wptl_multi_record['meta_id'] ), 'The run did not journal ownership of its A row in a multi-target conflict.' );
wptl_test_same( 'conflict', (string) ( $wptl_toctou_record['status'] ?? '' ), 'A source changed after intent was reported as migrated.' );
wptl_test_assert( ! metadata_exists( 'post', $wptl_source_toctou_id, \WPTitleLayer\Migration\Config::TARGET_META ), 'The migrator wrote after its source changed at the intent boundary.' );

if ( is_array( $wptl_concurrency_run ) ) {
	$wptl_concurrency_rollback = new \WPTitleLayer\Migration\Rollback();
	$wptl_concurrency_run      = $wptl_concurrency_rollback->start( $wptl_concurrency_run['id'] );
	if ( is_array( $wptl_concurrency_run ) ) {
		$wptl_concurrency_run = wptl_test_finish_rollback( $wptl_concurrency_rollback, $wptl_concurrency_run );
	}
}
wptl_test_same( array( 'Concurrent target B' ), array_values( get_post_meta( $wptl_multi_target_id, \WPTitleLayer\Migration\Config::TARGET_META, false ) ), 'Rollback did not delete only the run-owned A row and preserve concurrent B.' );

/* If unique add loses a race, only the frozen expected state may be accepted. */
$wptl_add_false_id = wptl_test_create_post( 'Unique add loses to changed B' );
update_post_meta( $wptl_add_false_id, '_secondary_title', 'Reviewed A before unique add' );
$wptl_add_false_scan = ( new \WPTitleLayer\Migration\ScanSnapshot() )->create();
wptl_test_assert( ! is_wp_error( $wptl_add_false_scan ), 'Could not scan the unique-add race fixture.' );
$wptl_inject_changed_target = null;
$wptl_inject_changed_target = static function ( $check, $post_id, $meta_key, $meta_value, $unique ) use ( $wptl_add_false_id, &$wptl_inject_changed_target ) {
	if ( null === $check && $unique && $wptl_add_false_id === (int) $post_id && \WPTitleLayer\Migration\Config::TARGET_META === $meta_key ) {
		remove_filter( 'add_post_metadata', $wptl_inject_changed_target, 10 );
		update_post_meta( $wptl_add_false_id, '_secondary_title', 'Concurrent source B' );
		add_post_meta( $wptl_add_false_id, \WPTitleLayer\Migration\Config::TARGET_META, 'Concurrent source B', false );
		return false;
	}
	return $check;
};
add_filter( 'add_post_metadata', $wptl_inject_changed_target, 10, 5 );
$wptl_add_false_run = ( new \WPTitleLayer\Migration\Migrator() )->start_run(
	array( 'scan_id' => $wptl_add_false_scan['scan_id'], 'batch_size' => 500 )
);
if ( is_array( $wptl_add_false_run ) ) {
	$wptl_add_false_run = wptl_test_finish_migration( new \WPTitleLayer\Migration\Migrator(), $wptl_add_false_run );
}
remove_filter( 'add_post_metadata', $wptl_inject_changed_target, 10 );
$wptl_add_false_record = array();
if ( is_array( $wptl_add_false_run ) ) {
	$wptl_add_false_store = new \WPTitleLayer\Migration\Store();
	for ( $wptl_batch = 1; $wptl_batch < (int) $wptl_add_false_run['next_batch']; ++$wptl_batch ) {
		$wptl_batch_log = $wptl_add_false_store->get_log( $wptl_add_false_run['id'], $wptl_batch );
		if ( isset( $wptl_batch_log['records'][ (string) $wptl_add_false_id ] ) ) {
			$wptl_add_false_record = $wptl_batch_log['records'][ (string) $wptl_add_false_id ];
			break;
		}
	}
}
wptl_test_same( 'conflict', (string) ( $wptl_add_false_record['status'] ?? '' ), 'A concurrent source/target B was accepted after the unique A add failed.' );
wptl_test_same( 'Concurrent source B', get_post_meta( $wptl_add_false_id, \WPTitleLayer\Migration\Config::TARGET_META, true ), 'The unique-add race changed the concurrent B target.' );

/* Resuming a pending intent must revalidate all target rows and source state. */
$wptl_pending_multi_id = wptl_test_create_post( 'Pending resumes with A and B' );
update_post_meta( $wptl_pending_multi_id, '_secondary_title', 'Pending intended A' );
$wptl_pending_source_id = wptl_test_create_post( 'Pending resumes after source drift' );
update_post_meta( $wptl_pending_source_id, '_secondary_title', 'Pending reviewed source A' );
$wptl_pending_scan = ( new \WPTitleLayer\Migration\ScanSnapshot() )->create();
wptl_test_assert( ! is_wp_error( $wptl_pending_scan ), 'Could not scan the pending-resume fixtures.' );
$wptl_pending_run = ( new \WPTitleLayer\Migration\Migrator() )->start_run(
	array(
		'scan_id'    => $wptl_pending_scan['scan_id'],
		'batch_size' => 500,
	)
);
wptl_test_assert( is_array( $wptl_pending_run ), 'Could not start the pending-resume run.' );
if ( is_array( $wptl_pending_run ) ) {
	$wptl_pending_store = new \WPTitleLayer\Migration\Store();
	$wptl_manifest_records = $wptl_pending_store->get_manifest_slice( $wptl_pending_run['scan_id'], 0, 500, $wptl_pending_run['manifest_count'] );
	$wptl_pending_candidates = array();
	foreach ( $wptl_manifest_records as $wptl_manifest_record ) {
		$wptl_pending_candidates[ (string) (int) $wptl_manifest_record['post_id'] ] = $wptl_manifest_record['fingerprint'];
	}
	$wptl_pending_ids = array_map( 'intval', array_keys( $wptl_pending_candidates ) );
	$wptl_pending_log = array(
		'run_id'        => $wptl_pending_run['id'],
		'batch'         => 1,
		'status'        => 'running',
		'started_at'    => \WPTitleLayer\Migration\Config::now(),
		'finished_at'   => null,
		'cursor_start'  => 0,
		'cursor_end'    => count( $wptl_pending_ids ),
		'candidate_ids' => $wptl_pending_ids,
		'candidates'    => $wptl_pending_candidates,
		'records'       => array(),
		'counts'        => array(),
	);
	foreach ( array( $wptl_pending_multi_id, $wptl_pending_source_id ) as $wptl_pending_id ) {
		$wptl_pending_fp = $wptl_pending_candidates[ (string) $wptl_pending_id ];
		$wptl_pending_log['records'][ (string) $wptl_pending_id ] = array(
			'post_id'     => $wptl_pending_id,
			'status'      => 'pending',
			'source_hash' => $wptl_pending_fp['source_hash'],
			'target_hash' => $wptl_pending_fp['target_hash'],
		);
	}
	wptl_test_assert( $wptl_pending_store->save_log( $wptl_pending_run['id'], 1, $wptl_pending_log ), 'Could not save the pending-resume batch journal.' );
	add_post_meta( $wptl_pending_multi_id, \WPTitleLayer\Migration\Config::TARGET_META, 'Pending intended A', false );
	add_post_meta( $wptl_pending_multi_id, \WPTitleLayer\Migration\Config::TARGET_META, 'Concurrent B', false );
	update_post_meta( $wptl_pending_source_id, '_secondary_title', 'Pending source changed B' );
	$wptl_pending_run = wptl_test_finish_migration( new \WPTitleLayer\Migration\Migrator(), $wptl_pending_run );
	$wptl_pending_log = $wptl_pending_store->get_log( $wptl_pending_run['id'], 1 );
	wptl_test_same( 'conflict', (string) ( $wptl_pending_log['records'][ (string) $wptl_pending_multi_id ]['status'] ?? '' ), 'Pending A+B was resumed as existing_same.' );
	wptl_test_same( 'conflict', (string) ( $wptl_pending_log['records'][ (string) $wptl_pending_source_id ]['status'] ?? '' ), 'Pending source drift was resumed as existing_same.' );
	wptl_test_assert( empty( $wptl_pending_log['records'][ (string) $wptl_pending_multi_id ]['owned_write'] ), 'An unproven pending A row was claimed by the run.' );
}

/* A value changed inside the pre-delete hook must win the rollback race. */
$wptl_atomic_post_id = wptl_test_create_post( 'Rollback compare-and-delete race' );
$wptl_atomic_owned_value = 'Run-owned value before rollback';
$wptl_atomic_user_value  = 'run-owned value before rollback';
$wptl_atomic_meta_id = add_post_meta( $wptl_atomic_post_id, 'wptl_subtitle', $wptl_atomic_owned_value, true );
wptl_test_assert( is_int( $wptl_atomic_meta_id ) && $wptl_atomic_meta_id > 0, 'Could not create the atomic rollback target row.' );

$wptl_atomic_store  = new \WPTitleLayer\Migration\Store();
$wptl_atomic_run_id = \WPTitleLayer\Migration\Config::new_run_id();
$wptl_atomic_run    = array(
	'id'         => $wptl_atomic_run_id,
	'status'     => 'complete',
	'next_batch' => 2,
	'counts'     => array(),
	'rollback'   => null,
);
wptl_test_assert( $wptl_atomic_store->add_run( $wptl_atomic_run ), 'Could not create the atomic rollback journal.' );
$wptl_atomic_store->save_log(
	$wptl_atomic_run_id,
	1,
	array(
		'run_id'  => $wptl_atomic_run_id,
		'batch'   => 1,
		'status'  => 'complete',
		'records' => array(
			(string) $wptl_atomic_post_id => array(
				'post_id'    => $wptl_atomic_post_id,
				'status'     => 'migrated',
				'value_hash' => \WPTitleLayer\Migration\Config::value_hash( $wptl_atomic_owned_value ),
				'meta_id'    => (int) $wptl_atomic_meta_id,
			),
		),
	)
);

$wptl_atomic_pre_hooks     = 0;
$wptl_atomic_deleted_hooks = 0;
$wptl_atomic_change_during_delete = static function ( $meta_ids, $post_id, $meta_key, $meta_value ) use ( &$wptl_atomic_pre_hooks, $wptl_atomic_post_id, $wptl_atomic_meta_id, $wptl_atomic_owned_value, $wptl_atomic_user_value ): void {
	if (
		$wptl_atomic_post_id === (int) $post_id
		&& \WPTitleLayer\Migration\Config::TARGET_META === $meta_key
		&& $wptl_atomic_owned_value === $meta_value
		&& in_array( (int) $wptl_atomic_meta_id, array_map( 'intval', (array) $meta_ids ), true )
	) {
		++$wptl_atomic_pre_hooks;
		update_metadata_by_mid( 'post', (int) $wptl_atomic_meta_id, $wptl_atomic_user_value );
	}
};
$wptl_atomic_after_delete = static function ( $meta_ids, $post_id ) use ( &$wptl_atomic_deleted_hooks, $wptl_atomic_post_id, $wptl_atomic_meta_id ): void {
	if ( $wptl_atomic_post_id === (int) $post_id && in_array( (int) $wptl_atomic_meta_id, array_map( 'intval', (array) $meta_ids ), true ) ) {
		++$wptl_atomic_deleted_hooks;
	}
};
add_action( 'delete_post_meta', $wptl_atomic_change_during_delete, 10, 4 );
add_action( 'deleted_post_meta', $wptl_atomic_after_delete, 10, 2 );
$wptl_atomic_rollback = new \WPTitleLayer\Migration\Rollback();
$wptl_atomic_run      = $wptl_atomic_rollback->start( $wptl_atomic_run_id );
if ( is_array( $wptl_atomic_run ) ) {
	$wptl_atomic_run = wptl_test_finish_rollback( $wptl_atomic_rollback, $wptl_atomic_run );
}
remove_action( 'delete_post_meta', $wptl_atomic_change_during_delete, 10 );
remove_action( 'deleted_post_meta', $wptl_atomic_after_delete, 10 );

wptl_test_assert( is_array( $wptl_atomic_run ), 'The atomic rollback fixture returned an error.' );
wptl_test_same( $wptl_atomic_user_value, get_post_meta( $wptl_atomic_post_id, 'wptl_subtitle', true ), 'Rollback deleted a value changed after its ownership check.' );
wptl_test_same( 1, $wptl_atomic_pre_hooks, 'Rollback did not preserve the standard pre-delete metadata hook.' );
wptl_test_same( 0, $wptl_atomic_deleted_hooks, 'Rollback fired the post-delete hook for a row its comparison preserved.' );
$wptl_atomic_log = $wptl_atomic_store->get_log( $wptl_atomic_run_id, 1 );
wptl_test_same(
	'preserved_changed',
	(string) ( $wptl_atomic_log['records'][ (string) $wptl_atomic_post_id ]['rollback_status'] ?? '' ),
	'Rollback did not journal the concurrent value change as preserved.'
);

/* A pending journal without a meta_id cannot prove ownership of a matching row. */
$wptl_unproven_post_id = wptl_test_create_post( 'Unproven pending rollback' );
$wptl_unproven_value   = 'Third-party canonical value';
add_post_meta( $wptl_unproven_post_id, 'wptl_subtitle', $wptl_unproven_value, true );

$wptl_store  = new \WPTitleLayer\Migration\Store();
$wptl_run_id = \WPTitleLayer\Migration\Config::new_run_id();
$wptl_unproven_run = array(
	'id'         => $wptl_run_id,
	'status'     => 'complete',
	'next_batch' => 2,
	'counts'     => array(),
	'rollback'   => null,
);
wptl_test_assert( $wptl_store->add_run( $wptl_unproven_run ), 'Could not create the pending rollback fixture.' );
$wptl_store->save_log(
	$wptl_run_id,
	1,
	array(
		'run_id'  => $wptl_run_id,
		'batch'   => 1,
		'status'  => 'complete',
		'records' => array(
			(string) $wptl_unproven_post_id => array(
				'post_id'     => $wptl_unproven_post_id,
				'status'      => 'pending',
				'target_hash' => \WPTitleLayer\Migration\Config::value_hash( $wptl_unproven_value ),
			),
		),
	)
);

$wptl_unproven_rollback = new \WPTitleLayer\Migration\Rollback( $wptl_store );
$wptl_unproven_run      = $wptl_unproven_rollback->start( $wptl_run_id );
if ( is_array( $wptl_unproven_run ) ) {
	$wptl_unproven_run = wptl_test_finish_rollback( $wptl_unproven_rollback, $wptl_unproven_run );
}
wptl_test_assert( is_array( $wptl_unproven_run ), 'The pending-record rollback returned an unexpected error.' );
wptl_test_same(
	$wptl_unproven_value,
	get_post_meta( $wptl_unproven_post_id, 'wptl_subtitle', true ),
	'Rollback deleted a matching canonical row without a journaled meta_id.'
);
$wptl_unproven_log = $wptl_store->get_log( $wptl_run_id, 1 );
wptl_test_same(
	'preserved_unproven_pending',
	$wptl_unproven_log['records'][ (string) $wptl_unproven_post_id ]['rollback_status'],
	'Rollback did not record the unproven pending row as preserved.'
);

/* A completed run newer than its retained scan is reported as copied. */
$wptl_state_scan_time = gmdate( 'c', time() - 60 );
$wptl_state_run_id    = \WPTitleLayer\Migration\Config::new_run_id();
$wptl_state_run       = array(
	'id'           => $wptl_state_run_id,
	'status'       => 'complete',
	'completed_at' => gmdate( 'c' ),
	'counts'       => array( 'processed' => 3, 'migrated' => 3, 'existing_same' => 0, 'conflict' => 0, 'errors' => 0 ),
);
update_option(
	\WPTitleLayer\Migration\Config::LAST_SCAN_OPTION,
	array(
		'generated_at' => $wptl_state_scan_time,
		'truncated'    => false,
		'stats'        => array( 'candidate_posts' => 3, 'ready' => 3, 'existing_same' => 0, 'conflicts' => 0 ),
	)
);
update_option( \WPTitleLayer\Migration\Config::RUN_INDEX_OPTION, array( $wptl_state_run_id ) );
update_option( \WPTitleLayer\Migration\Config::run_option( $wptl_state_run_id ), $wptl_state_run );
$wptl_completed_snapshot = \WPTitleLayer\Admin\ControlCenter::snapshot();
wptl_test_same( 'copied', $wptl_completed_snapshot['migration']['state'], 'Control Center treated a completed migration as ready to copy again.' );

/* A newer scan is authoritative over the older terminal run. */
update_option(
	\WPTitleLayer\Migration\Config::LAST_SCAN_OPTION,
	array(
		'generated_at' => gmdate( 'c', time() + 60 ),
		'truncated'    => false,
		'stats'        => array( 'candidate_posts' => 2, 'ready' => 1, 'existing_same' => 0, 'conflicts' => 1 ),
	)
);
$wptl_new_scan_snapshot = \WPTitleLayer\Admin\ControlCenter::snapshot();
wptl_test_same( 'conflicts', $wptl_new_scan_snapshot['migration']['state'], 'Control Center ignored a scan newer than the terminal migration run.' );

/*
 * Re-run the application bootstrap in a legacy-like admin request. The normal
 * runtime hooks have already been tested above, so removing them here lets the
 * same process verify the migration-only boundary without a second database.
 */
$wptl_legacy_boot_prefixes = array( 'WPTitleLayer\\Presentation\\', 'WPTitleLayer\\Reader\\', 'WPTitleLayer\\Admin\\ClassicEditor' );
wptl_test_remove_module_hooks( $wptl_legacy_boot_prefixes );
foreach ( array( 'wp_title_layer', 'wptl_subtitle', 'secondary_title', 'wptl_series_navigation' ) as $wptl_frontend_shortcode ) {
	remove_shortcode( $wptl_frontend_shortcode );
}
if ( function_exists( 'unregister_block_type' ) && WP_Block_Type_Registry::get_instance()->is_registered( 'wp-title-layer/title-layer' ) ) {
	unregister_block_type( 'wp-title-layer/title-layer' );
}

/* Content-model registration alone must retain the Series REST write guard. */
remove_filter( 'rest_pre_dispatch', array( \WPTitleLayer\Core\Series::class, 'guard_rest_term_writes' ), 10 );
$wptl_rest_guard_property = $wptl_series_reflection->getProperty( 'rest_term_guard_registered' );
$wptl_rest_guard_property->setAccessible( true );
$wptl_rest_guard_property->setValue( null, false );
\WPTitleLayer\Core\Bootstrap::register_content_model();
wptl_test_same(
	10,
	has_filter( 'rest_pre_dispatch', array( \WPTitleLayer\Core\Series::class, 'guard_rest_term_writes' ) ),
	'Direct content-model registration omitted the Series REST write guard.'
);

remove_action( 'admin_menu', array( 'WPTitleLayer\\Admin\\ControlCenter', 'addPage' ) );
remove_action( 'admin_enqueue_scripts', array( 'WPTitleLayer\\Admin\\ControlCenter', 'enqueueAssets' ) );
remove_action( 'admin_menu', array( 'WPTitleLayer\\Admin\\SeriesHealth', 'add_page' ) );
remove_action( 'admin_enqueue_scripts', array( 'WPTitleLayer\\Admin\\SeriesHealth', 'enqueue_assets' ) );
remove_action( 'admin_menu', array( 'WPTitleLayer\\Admin\\ChannelHealth', 'add_page' ) );
remove_action( 'admin_enqueue_scripts', array( 'WPTitleLayer\\Admin\\ChannelHealth', 'enqueue_assets' ) );
remove_filter(
	'plugin_action_links_' . plugin_basename( WPTL_FILE ),
	array( 'WPTitleLayer\\Admin\\ControlCenter', 'pluginActionLinks' )
);
$wptl_control_reflection = new ReflectionClass( 'WPTitleLayer\\Admin\\ControlCenter' );
foreach ( array( 'registered' => false, 'legacy_active' => false ) as $wptl_property_name => $wptl_property_value ) {
	$wptl_property = $wptl_control_reflection->getProperty( $wptl_property_name );
	$wptl_property->setAccessible( true );
	$wptl_property->setValue( null, $wptl_property_value );
}
$wptl_health_reflection = new ReflectionClass( 'WPTitleLayer\\Admin\\SeriesHealth' );
foreach ( array( 'registered' => false, 'hook_suffix' => '' ) as $wptl_property_name => $wptl_property_value ) {
	$wptl_property = $wptl_health_reflection->getProperty( $wptl_property_name );
	$wptl_property->setAccessible( true );
	$wptl_property->setValue( null, $wptl_property_value );
}
$wptl_channel_reflection = new ReflectionClass( 'WPTitleLayer\\Admin\\ChannelHealth' );
foreach ( array( 'registered' => false, 'hook_suffix' => '' ) as $wptl_property_name => $wptl_property_value ) {
	$wptl_property = $wptl_channel_reflection->getProperty( $wptl_property_name );
	$wptl_property->setAccessible( true );
	$wptl_property->setValue( null, $wptl_property_value );
}

$wptl_legacy_old_screen = $GLOBALS['current_screen'] ?? null;
set_current_screen( 'dashboard' );
if ( ! is_wp_error( $wptl_admin_id ) ) {
	wp_set_current_user( (int) $wptl_admin_id );
}
\WPTitleLayer\Plugin::register();

wptl_test_assert(
	false !== has_action( 'admin_menu', array( 'WPTitleLayer\\Admin\\ControlCenter', 'addPage' ) ),
	'The Control Center was not registered during the simulated migration-only boot.'
);
wptl_test_assert(
	false === has_action( 'admin_menu', array( 'WPTitleLayer\\Admin\\SeriesHealth', 'add_page' ) ),
	'The normal-mode Series health page was registered during migration-only boot.'
);
wptl_test_assert(
	false === has_action( 'admin_menu', array( 'WPTitleLayer\\Admin\\ChannelHealth', 'add_page' ) ),
	'The normal-mode landing-page and channel report was registered during migration-only boot.'
);
$wptl_legacy_control_snapshot = \WPTitleLayer\Admin\ControlCenter::snapshot();
wptl_test_assert( ! empty( $wptl_legacy_control_snapshot['legacy_active'] ), 'The simulated legacy boot was not retained by the Control Center.' );
wptl_test_assert(
	empty( $wptl_legacy_control_snapshot['presentation']['available'] )
	&& empty( $wptl_legacy_control_snapshot['reader']['available'] )
	&& empty( $wptl_legacy_control_snapshot['series']['can_manage'] ),
	'The Control Center did not report editor and front-end features as paused during migration-only mode.'
);
wptl_test_assert( taxonomy_exists( 'wptl_series' ), 'Migration-only mode did not retain the Core Series schema.' );
wptl_test_same(
	0,
	wptl_test_module_hook_count( $wptl_legacy_boot_prefixes ),
	'Presentation or Reader hooks were registered during the simulated migration-only boot.'
);
wptl_test_assert(
	! shortcode_exists( 'wp_title_layer' )
	&& ! shortcode_exists( 'wptl_subtitle' )
	&& ! shortcode_exists( 'wptl_series_navigation' ),
	'Front-end shortcodes were registered during the simulated migration-only boot.'
);
wptl_test_assert(
	! WP_Block_Type_Registry::get_instance()->is_registered( 'wp-title-layer/title-layer' ),
	'The front-end Title Layer block was registered during the simulated migration-only boot.'
);

if ( ! is_wp_error( $wptl_author_id ) ) {
	wp_set_current_user( (int) $wptl_author_id );
	$wptl_migration_terms_request = new WP_REST_Request( 'GET', '/wp/v2/wptl_series' );
	$wptl_migration_terms_request->set_param( 'context', 'view' );
	$wptl_migration_terms_request->set_param( 'hide_empty', false );
	$wptl_migration_terms_response = rest_do_request( $wptl_migration_terms_request );
	wptl_test_same( 200, $wptl_migration_terms_response->get_status(), 'An assign-only author could not load existing Series in migration-only mode.' );
	wptl_test_assert(
		in_array( $wptl_auth_term_id, array_map( 'absint', wp_list_pluck( (array) $wptl_migration_terms_response->get_data(), 'id' ) ), true ),
		'Migration-only Series REST omitted an existing term.'
	);
	$wptl_migration_create_request = new WP_REST_Request( 'POST', '/wp/v2/wptl_series' );
	$wptl_migration_create_request->set_param( 'name', 'Forbidden migration-only Series' );
	$wptl_migration_create_response = rest_do_request( $wptl_migration_create_request );
	wptl_test_same( 403, $wptl_migration_create_response->get_status(), 'An assign-only author created a Series in migration-only mode.' );
	wptl_test_assert( ! term_exists( 'Forbidden migration-only Series', 'wptl_series' ), 'The denied migration-only REST request still created a Series.' );
	if ( ! is_wp_error( $wptl_admin_id ) ) {
		wp_set_current_user( (int) $wptl_admin_id );
	}
}

ob_start();
\WPTitleLayer\Admin\ControlCenter::render();
$wptl_legacy_control_html = (string) ob_get_clean();
wptl_test_assert(
	false !== strpos( $wptl_legacy_control_html, 'wptl-control-center' )
	&& false !== strpos( $wptl_legacy_control_html, 'Finish the old-title migration first' ),
	'The migration-only Control Center did not render its paused-state guidance.'
);
$GLOBALS['current_screen'] = $wptl_legacy_old_screen;

if ( $wptl_failures ) {
	fwrite( STDERR, "WP Title Layer integration failures:\n- " . implode( "\n- ", $wptl_failures ) . "\n" );
	exit( 1 );
}

echo 'WP Title Layer integration checks passed: ' . (int) $wptl_checks . "\n";
