<?php
/**
 * WP Title Layer 0.10 canonical-sequence integration checks.
 *
 * @package WPTitleLayer
 */

require_once '/wordpress/wp-load.php';

$wptl_sequence_checks   = 0;
$wptl_sequence_failures = array();

function wptl_sequence_assert( $condition, $message ) {
	global $wptl_sequence_checks, $wptl_sequence_failures;
	++$wptl_sequence_checks;
	if ( ! $condition ) {
		$wptl_sequence_failures[] = $message;
	}
}

function wptl_sequence_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		$message .= sprintf( ' (expected %s, got %s)', var_export( $expected, true ), var_export( $actual, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
	}
	wptl_sequence_assert( $expected === $actual, $message );
}

function wptl_sequence_error( $code, $result, $message ) {
	wptl_sequence_same( $code, is_wp_error( $result ) ? $result->get_error_code() : '', $message );
}

function wptl_sequence_term( $name, $structure = 'flat' ) {
	$result = wp_insert_term( $name, 'wptl_series' );
	if ( is_wp_error( $result ) ) {
		wptl_sequence_assert( false, 'Could not create Series fixture: ' . $result->get_error_message() );
		return 0;
	}
	$term_id = (int) $result['term_id'];
	update_term_meta( $term_id, 'wptl_series_mode', 'ordered' );
	update_term_meta( $term_id, 'wptl_series_structure', $structure );
	return $term_id;
}

function wptl_sequence_post( $title, $term_id, $position = null, $season_key = '', $date = '' ) {
	$post = array(
		'post_type'   => 'post',
		'post_status' => 'publish',
		'post_title'  => $title,
	);
	if ( '' !== $date ) {
		$post['post_date']     = $date;
		$post['post_date_gmt'] = get_gmt_from_date( $date );
	}
	$post_id = wp_insert_post( $post, true );
	if ( is_wp_error( $post_id ) ) {
		wptl_sequence_assert( false, 'Could not create sequence article: ' . $post_id->get_error_message() );
		return 0;
	}
	wp_set_object_terms( (int) $post_id, array( $term_id ), 'wptl_series' );
	if ( null !== $position ) {
		update_post_meta( (int) $post_id, 'wptl_sequence_position', $position );
	}
	if ( '' !== $season_key ) {
		update_post_meta( (int) $post_id, 'wptl_season_key', $season_key );
	}
	return (int) $post_id;
}

function wptl_sequence_finish_initialization( $term_id, $batch_size = 100 ) {
	$result = null;
	for ( $attempt = 0; $attempt < 100; ++$attempt ) {
		$result = \WPTitleLayer\Core\Sequence::continue_initialization( $term_id, $batch_size );
		if ( is_wp_error( $result ) || 'writing' !== ( $result['status'] ?? '' ) ) {
			break;
		}
	}
	return $result;
}

function wptl_sequence_initialize( $term_id, $append_unresolved = false, $batch_size = 100 ) {
	$preview = \WPTitleLayer\Core\Sequence::initialization_preview( $term_id );
	$result  = \WPTitleLayer\Core\Sequence::begin_initialization( $term_id, $append_unresolved, (string) ( $preview['fingerprint'] ?? '' ) );
	return is_wp_error( $result ) ? $result : wptl_sequence_finish_initialization( $term_id, $batch_size );
}

/* Use a real administrator so every affected term and article is authorized. */
$wptl_sequence_admin = wp_insert_user(
	array(
		'user_login' => 'wptl-sequence-admin',
		'user_pass'  => wp_generate_password( 24, true, true ),
		'user_email' => 'wptl-sequence-admin@example.invalid',
		'role'       => 'administrator',
	)
);
wptl_sequence_assert( ! is_wp_error( $wptl_sequence_admin ), 'Could not create the Sequence Manager administrator.' );
if ( ! is_wp_error( $wptl_sequence_admin ) ) {
	wp_set_current_user( (int) $wptl_sequence_admin );
}

wptl_sequence_assert( taxonomy_exists( 'wptl_series' ), 'The Series taxonomy is unavailable to Sequence Manager.' );
wptl_sequence_assert( class_exists( 'WPTitleLayer\\Core\\Sequence' ), 'The canonical Sequence service did not load.' );
wptl_sequence_same( 1, \WPTitleLayer\Core\Schema::SEQUENCE_SCHEMA_VERSION, 'The sequence schema version changed unexpectedly.' );

/* Flat legacy order initializes in batches and activates only at the end. */
$flat_term_id = wptl_sequence_term( 'WPTL 010 Flat Sequence' );
$flat_term    = get_term( $flat_term_id, 'wptl_series' );
$flat_a       = wptl_sequence_post( 'WPTL Flat A', $flat_term_id, 10, '', '2025-01-01 10:00:00' );
$flat_b       = wptl_sequence_post( 'WPTL Flat Bravo', $flat_term_id, 20, '', '2025-01-02 10:00:00' );
$flat_c       = wptl_sequence_post( 'WPTL Flat C', $flat_term_id, 30, '', '2025-01-03 10:00:00' );
\WPTitleLayer\Core\SequenceRuntime::flush();

$flat_preview = \WPTitleLayer\Core\Sequence::initialization_preview( $flat_term_id );
wptl_sequence_same( 3, (int) $flat_preview['members'], 'The flat initialization preview lost members.' );
wptl_sequence_same( 0, (int) $flat_preview['unresolved'], 'Unambiguous legacy positions were marked unresolved.' );
wptl_sequence_error(
	'wptl_sequence_preview_changed',
	\WPTitleLayer\Core\Sequence::begin_initialization( $flat_term_id, false, str_repeat( '0', 64 ) ),
	'A stale initialization preview was accepted.'
);
$flat_journal = \WPTitleLayer\Core\Sequence::begin_initialization( $flat_term_id, false, (string) $flat_preview['fingerprint'] );
wptl_sequence_same( 'writing', $flat_journal['status'] ?? '', 'Flat initialization did not create a resumable journal.' );
$flat_journal = \WPTitleLayer\Core\Sequence::continue_initialization( $flat_term_id, 1 );
wptl_sequence_same( 'writing', $flat_journal['status'] ?? '', 'A one-record initialization batch activated the Series too early.' );
wptl_sequence_assert( ! \WPTitleLayer\Core\Sequence::is_managed( $flat_term ), 'The schema marker was committed before all ranks were verified.' );
wptl_sequence_same( '10', (string) get_post_meta( $flat_a, 'wptl_sequence_position', true ), 'Initialization rewrote a legacy position.' );
$flat_journal = wptl_sequence_finish_initialization( $flat_term_id, 1 );
wptl_sequence_same( 'complete', $flat_journal['status'] ?? '', 'The resumable flat initialization did not complete.' );
wptl_sequence_assert( \WPTitleLayer\Core\Sequence::is_managed( $flat_term ), 'The completed Series did not receive its activation marker.' );
wptl_sequence_same( array( $flat_a, $flat_b, $flat_c ), \WPTitleLayer\Core\Sequence::ordered_post_ids( $flat_term ), 'Initialization changed the known legacy order.' );
wptl_sequence_same( 1, \WPTitleLayer\Core\Sequence::automatic_ordinal( $flat_a, $flat_term ), 'The first automatic ordinal is wrong.' );
wptl_sequence_same( 2, \WPTitleLayer\Core\Sequence::automatic_ordinal( $flat_b, $flat_term ), 'The second automatic ordinal is wrong.' );
wptl_sequence_same( 3, \WPTitleLayer\Core\Sequence::automatic_ordinal( $flat_c, $flat_term ), 'The third automatic ordinal is wrong.' );
foreach ( array( $flat_a, $flat_b, $flat_c ) as $flat_post_id ) {
	wptl_sequence_assert( 0 < \WPTitleLayer\Core\Sequence::rank( $flat_post_id ), 'Initialization wrote a non-positive private rank.' );
	wptl_sequence_assert( metadata_exists( 'post', $flat_post_id, 'wptl_sequence_source_position' ), 'Initialization did not freeze the legacy-source baseline.' );
}

/* Insert, stale-write protection, undo, and private-rank repair. */
$flat_revision = \WPTitleLayer\Core\Sequence::revision( $flat_term );
$flat_move     = \WPTitleLayer\Core\Sequence::move( $flat_term_id, '', $flat_c, $flat_b, 'before', $flat_revision );
wptl_sequence_assert( ! is_wp_error( $flat_move ), 'An authorized exact insertion failed.' );
wptl_sequence_same( array( $flat_a, $flat_c, $flat_b ), \WPTitleLayer\Core\Sequence::ordered_post_ids( $flat_term ), 'Insert-before produced the wrong canonical order.' );
wptl_sequence_same( $flat_revision + 1, \WPTitleLayer\Core\Sequence::revision( $flat_term ), 'The exact insertion did not advance the scope revision.' );

update_post_meta( $flat_b, 'wptl_sequence_position', 1 );
wptl_sequence_same( array( $flat_a, $flat_c, $flat_b ), \WPTitleLayer\Core\Sequence::ordered_post_ids( $flat_term ), 'Changing obsolete legacy data changed managed order.' );
$flat_health = ( new \WPTitleLayer\Admin\SeriesHealthReport() )->report( $flat_term_id );
wptl_sequence_same( 1, (int) $flat_health['summary']['legacy_position_changes'], 'Series Health did not report post-initialization legacy drift.' );
wptl_sequence_same( 0, (int) $flat_health['summary']['position_issues'], 'Healthy private ranks were reported as missing or invalid.' );

$flat_undo = \WPTitleLayer\Core\Sequence::undo_last_move( $flat_term_id, '', \WPTitleLayer\Core\Sequence::revision( $flat_term ) );
wptl_sequence_assert( ! is_wp_error( $flat_undo ), 'The latest value-matching manual move could not be undone.' );
wptl_sequence_same( array( $flat_a, $flat_b, $flat_c ), \WPTitleLayer\Core\Sequence::ordered_post_ids( $flat_term ), 'Undo did not restore the preceding canonical order.' );
$stale_revision = \WPTitleLayer\Core\Sequence::revision( $flat_term );
$second_move    = \WPTitleLayer\Core\Sequence::move( $flat_term_id, '', $flat_c, $flat_b, 'before', $stale_revision );
wptl_sequence_assert( ! is_wp_error( $second_move ), 'The second canonical move failed.' );
wptl_sequence_error(
	'wptl_sequence_revision_conflict',
	\WPTitleLayer\Core\Sequence::move( $flat_term_id, '', $flat_b, $flat_a, 'before', $stale_revision ),
	'A stale manager revision overwrote a newer move.'
);
wptl_sequence_error(
	'wptl_sequence_rollback_stale',
	\WPTitleLayer\Core\Sequence::rollback_initialization( $flat_term_id ),
	'Initialization rollback ignored later sequence revisions.'
);

$flat_page = \WPTitleLayer\Core\Sequence::scope_page( $flat_term_id, '', 1, 2 );
wptl_sequence_same( 2, count( (array) $flat_page['rows'] ), 'The Sequence Manager service did not bound a requested page.' );
wptl_sequence_same( 2, (int) $flat_page['total_pages'], 'The Sequence Manager page count is wrong.' );
$flat_search = \WPTitleLayer\Core\Sequence::scope_page( $flat_term_id, '', 1, 20, 'Bravo' );
wptl_sequence_same( array( $flat_b ), array_map( 'absint', wp_list_pluck( (array) $flat_search['rows'], 'id' ) ), 'Cross-page title search did not locate the exact article.' );

$flat_d = wptl_sequence_post( 'WPTL Flat D appended', $flat_term_id, null );
\WPTitleLayer\Core\SequenceRuntime::flush();
$flat_order = \WPTitleLayer\Core\Sequence::ordered_post_ids( $flat_term );
wptl_sequence_same( $flat_d, (int) end( $flat_order ), 'A newly assigned managed article was not appended.' );
wptl_sequence_same( \WPTitleLayer\Core\Sequence::MISSING_SOURCE, (string) get_post_meta( $flat_d, 'wptl_sequence_source_position', true ), 'A new article did not freeze the absent-legacy sentinel.' );
update_post_meta( $flat_d, 'wptl_sequence_position', 77 );
$flat_health = ( new \WPTitleLayer\Admin\SeriesHealthReport() )->report( $flat_term_id );
wptl_sequence_same( 2, (int) $flat_health['summary']['legacy_position_changes'], 'Health did not distinguish a newly written obsolete position from an absent baseline.' );

/* Missing private ranks remain visible in the bounded Manager and are repairable. */
delete_post_meta( $flat_d, 'wptl_sequence_rank' );
$missing_rank_page = \WPTitleLayer\Core\Sequence::scope_page( $flat_term_id, '', 1, 100 );
wptl_sequence_assert( in_array( $flat_d, array_map( 'absint', wp_list_pluck( (array) $missing_rank_page['rows'], 'id' ) ), true ), 'The bounded Manager silently omitted an article with a missing private rank.' );
$missing_rank_health = ( new \WPTitleLayer\Admin\SeriesHealthReport() )->report( $flat_term_id );
wptl_sequence_same( 1, (int) $missing_rank_health['summary']['missing_positions'], 'Health did not detect a missing managed rank.' );
update_post_meta( $flat_d, 'wptl_sequence_rank', 'damaged' );
$theme_archive_query = new WP_Query(
	array(
		'post_type'             => 'post',
		'post_status'           => 'publish',
		'fields'                => 'ids',
		'posts_per_page'        => -1,
		'no_found_rows'         => true,
		'ignore_sticky_posts'   => true,
		'orderby'               => 'none',
		'tax_query'             => array( array( 'taxonomy' => 'wptl_series', 'field' => 'term_id', 'terms' => array( $flat_term_id ) ) ),
		'wptl_series_ordering'  => array( 'term_id' => $flat_term_id, 'mode' => 'ordered', 'structure' => 'flat', 'archive_sort' => 'date_desc', 'season_key' => '' ),
	)
);
wptl_sequence_same( \WPTitleLayer\Core\Sequence::ordered_post_ids( $flat_term, '', true ), array_map( 'absint', $theme_archive_query->posts ), 'Theme Archive SQL disagreed with canonical order when a managed rank was damaged.' );
$missing_rank_repair = \WPTitleLayer\Core\Sequence::move( $flat_term_id, '', $flat_d, 0, 'end', \WPTitleLayer\Core\Sequence::revision( $flat_term ) );
wptl_sequence_assert( ! is_wp_error( $missing_rank_repair ), 'The Manager could not repair a missing rank by placing the article.' );

/* A duplicate private rank is visible in Health and repaired by one rebalance. */
update_post_meta( $flat_a, 'wptl_sequence_rank', 1 );
update_post_meta( $flat_c, 'wptl_sequence_rank', 2 );
update_post_meta( $flat_b, 'wptl_sequence_rank', 3 );
update_post_meta( $flat_d, 'wptl_sequence_rank', 3 );
$damaged_health = ( new \WPTitleLayer\Admin\SeriesHealthReport() )->report( $flat_term_id );
wptl_sequence_same( 2, (int) $damaged_health['summary']['duplicate_positions'], 'Health did not flag both duplicate managed ranks.' );
$repair = \WPTitleLayer\Core\Sequence::move( $flat_term_id, '', $flat_d, 0, 'end', \WPTitleLayer\Core\Sequence::revision( $flat_term ) );
wptl_sequence_assert( ! is_wp_error( $repair ), 'A full-scope rebalance could not repair adjacent/duplicate private ranks.' );
$repaired_ranks = array();
foreach ( \WPTitleLayer\Core\Sequence::ordered_post_ids( $flat_term ) as $index => $post_id ) {
	$repaired_ranks[] = \WPTitleLayer\Core\Sequence::rank( $post_id );
	wptl_sequence_same( ( $index + 1 ) * \WPTitleLayer\Core\Sequence::RANK_STEP, \WPTitleLayer\Core\Sequence::rank( $post_id ), 'Rebalance did not restore evenly spaced private ranks.' );
}
wptl_sequence_same( count( $repaired_ranks ), count( array_unique( $repaired_ranks ) ), 'Rebalance retained duplicate ranks.' );

$flat_renderer_context = ( new \WPTitleLayer\Presentation\Renderer() )->context( $flat_b );
wptl_sequence_same( (string) \WPTitleLayer\Core\Sequence::automatic_ordinal( $flat_b, $flat_term ), $flat_renderer_context['position'], 'The title renderer still exposed the obsolete legacy position.' );
$flat_archive_context = ( new \WPTitleLayer\Reader\Archive() )->postContext( $flat_b, 'ordered', 'flat', false, false, $flat_term );
wptl_sequence_same( \WPTitleLayer\Core\Sequence::automatic_ordinal( $flat_b, $flat_term ), (int) $flat_archive_context['position'], 'The structured archive did not use the canonical automatic number.' );

/* Partial and complete initialization rollback are both value checked. */
$rollback_term_id = wptl_sequence_term( 'WPTL 010 Rollback Sequence' );
$rollback_term    = get_term( $rollback_term_id, 'wptl_series' );
$rollback_a       = wptl_sequence_post( 'WPTL Rollback A', $rollback_term_id, 10 );
$rollback_b       = wptl_sequence_post( 'WPTL Rollback B', $rollback_term_id, 20 );
\WPTitleLayer\Core\SequenceRuntime::flush();
$rollback_preview = \WPTitleLayer\Core\Sequence::initialization_preview( $rollback_term_id );
\WPTitleLayer\Core\Sequence::begin_initialization( $rollback_term_id, false, (string) $rollback_preview['fingerprint'] );
$rollback_writing = \WPTitleLayer\Core\Sequence::continue_initialization( $rollback_term_id, 1 );
wptl_sequence_same( 'writing', $rollback_writing['status'] ?? '', 'The partial rollback fixture completed unexpectedly.' );
$partial_rollback = \WPTitleLayer\Core\Sequence::rollback_initialization( $rollback_term_id );
wptl_sequence_same( 'rolled_back', $partial_rollback['status'] ?? '', 'Partial initialization did not roll back cleanly.' );
wptl_sequence_assert( ! metadata_exists( 'post', $rollback_a, 'wptl_sequence_rank' ), 'Partial rollback left a newly written rank.' );
wptl_sequence_assert( ! metadata_exists( 'post', $rollback_a, 'wptl_sequence_source_position' ), 'Partial rollback left a newly written source baseline.' );
wptl_sequence_assert( ! \WPTitleLayer\Core\Sequence::is_managed( $rollback_term ), 'Partial rollback left the activation marker.' );
$complete_rollback_init = wptl_sequence_initialize( $rollback_term_id );
wptl_sequence_same( 'complete', $complete_rollback_init['status'] ?? '', 'The rollback fixture could not be initialized a second time.' );
$commit_recovery_journal = get_option( 'wptl_seq_init_' . $rollback_term_id );
delete_term_meta( $rollback_term_id, 'wptl_sequence_schema_version' );
$commit_recovery_journal['status']       = 'writing';
$commit_recovery_journal['commit_phase'] = 'revisions';
update_option( 'wptl_seq_init_' . $rollback_term_id, $commit_recovery_journal, false );
$revision_tail_recovery = \WPTitleLayer\Core\Sequence::continue_initialization( $rollback_term_id );
wptl_sequence_same( 'complete', $revision_tail_recovery['status'] ?? '', 'An interruption after the revision commit could not resume through activation.' );
wptl_sequence_assert( \WPTitleLayer\Core\Sequence::is_managed( $rollback_term ), 'Revision-tail recovery did not restore the activation marker.' );
$commit_recovery_journal = get_option( 'wptl_seq_init_' . $rollback_term_id );
$commit_recovery_journal['status']       = 'writing';
$commit_recovery_journal['commit_phase'] = 'schema';
update_option( 'wptl_seq_init_' . $rollback_term_id, $commit_recovery_journal, false );
$schema_tail_recovery = \WPTitleLayer\Core\Sequence::continue_initialization( $rollback_term_id );
wptl_sequence_same( 'complete', $schema_tail_recovery['status'] ?? '', 'An interruption after activation could not finalize its recovery journal.' );
$complete_rollback = \WPTitleLayer\Core\Sequence::rollback_initialization( $rollback_term_id );
wptl_sequence_same( 'rolled_back', $complete_rollback['status'] ?? '', 'Untouched completed initialization did not roll back.' );
wptl_sequence_assert( ! \WPTitleLayer\Core\Sequence::is_managed( $rollback_term ), 'Completed rollback did not remove the activation marker.' );
wptl_sequence_assert( ! metadata_exists( 'post', $rollback_b, 'wptl_sequence_rank' ), 'Completed rollback left a private rank.' );

/* Ambiguous legacy values require explicit date append. */
$ambiguous_term_id = wptl_sequence_term( 'WPTL 010 Ambiguous Sequence' );
$ambiguous_term    = get_term( $ambiguous_term_id, 'wptl_series' );
$ambiguous_a       = wptl_sequence_post( 'WPTL Ambiguous A', $ambiguous_term_id, 10, '', '2024-01-01 10:00:00' );
$ambiguous_b       = wptl_sequence_post( 'WPTL Ambiguous B', $ambiguous_term_id, 10, '', '2024-01-02 10:00:00' );
$ambiguous_c       = wptl_sequence_post( 'WPTL Ambiguous C', $ambiguous_term_id, null, '', '2024-01-03 10:00:00' );
\WPTitleLayer\Core\SequenceRuntime::flush();
$ambiguous_preview = \WPTitleLayer\Core\Sequence::initialization_preview( $ambiguous_term_id );
wptl_sequence_same( 3, (int) $ambiguous_preview['unresolved'], 'Duplicate and missing legacy positions were not all unresolved.' );
wptl_sequence_error(
	'wptl_sequence_unresolved_members',
	\WPTitleLayer\Core\Sequence::begin_initialization( $ambiguous_term_id, false, (string) $ambiguous_preview['fingerprint'] ),
	'Ambiguous members were silently guessed without explicit approval.'
);
$ambiguous_init = \WPTitleLayer\Core\Sequence::begin_initialization( $ambiguous_term_id, true, (string) $ambiguous_preview['fingerprint'] );
$ambiguous_init = is_wp_error( $ambiguous_init ) ? $ambiguous_init : wptl_sequence_finish_initialization( $ambiguous_term_id );
wptl_sequence_same( 'complete', is_array( $ambiguous_init ) ? ( $ambiguous_init['status'] ?? '' ) : '', 'Approved date append did not initialize ambiguous members.' );
wptl_sequence_same( array( $ambiguous_a, $ambiguous_b, $ambiguous_c ), \WPTitleLayer\Core\Sequence::ordered_post_ids( $ambiguous_term ), 'Approved unresolved members were not appended by date then post ID.' );

/* Stable numeric Season identities survive labels, ordering, and deletion. */
$season_id_term_id = wptl_sequence_term( 'WPTL 010 Season IDs', 'seasoned' );
$season_id_term    = get_term( $season_id_term_id, 'wptl_series' );
wptl_sequence_assert(
	\WPTitleLayer\Core\Sequence::persist_seasons(
		$season_id_term,
		array(
			array( 'key' => 'arrival', 'label' => '抵达篇', 'sort' => 10 ),
			array( 'key' => 'return', 'label' => '归途篇', 'sort' => 20 ),
		)
	),
	'Initial stable Season IDs could not be persisted.'
);
$season_id_map = array();
foreach ( \WPTitleLayer\Core\Series::seasons( $season_id_term ) as $season ) {
	$season_id_map[ $season['key'] ] = (int) $season['public_id'];
}
wptl_sequence_same( array( 'arrival' => 1, 'return' => 2 ), $season_id_map, 'Season public IDs were not allocated numerically.' );
wptl_sequence_assert( false !== strpos( \WPTitleLayer\Core\Series::public_season_archive_url( $season_id_term, 'arrival' ), 'wptl_season=1' ), 'Generated Season links still expose a language-derived key.' );
wptl_sequence_same( \WPTitleLayer\Core\Series::public_season_archive_url( $season_id_term, 'arrival' ), \WPTitleLayer\Core\Series::canonical_season_url( $season_id_term, 'arrival' ), 'A legacy key did not resolve to its numeric canonical URL.' );
$numeric_resolution = \WPTitleLayer\Core\Sequence::resolve_season_token( $season_id_term, '1' );
$legacy_resolution  = \WPTitleLayer\Core\Sequence::resolve_season_token( $season_id_term, 'arrival' );
wptl_sequence_assert( is_array( $numeric_resolution ) && empty( $numeric_resolution['legacy'] ), 'Numeric Season resolution was marked legacy.' );
wptl_sequence_assert( is_array( $legacy_resolution ) && ! empty( $legacy_resolution['legacy'] ), 'Old key Season resolution was not recognized as legacy.' );

\WPTitleLayer\Core\Sequence::persist_seasons(
	$season_id_term,
	array(
		array( 'key' => 'return', 'label' => 'Return renamed', 'sort' => 10, 'public_id' => 98 ),
		array( 'key' => 'arrival', 'label' => 'Arrival renamed', 'sort' => 20, 'public_id' => 99 ),
	)
);
$renamed_map = array();
foreach ( \WPTitleLayer\Core\Series::seasons( $season_id_term ) as $season ) {
	$renamed_map[ $season['key'] ] = (int) $season['public_id'];
}
wptl_sequence_same( array( 'return' => 2, 'arrival' => 1 ), $renamed_map, 'Submitted hidden values changed stable Season identities.' );
\WPTitleLayer\Core\Sequence::persist_seasons( $season_id_term, array( array( 'key' => 'return', 'label' => 'Return renamed', 'sort' => 10 ) ) );
\WPTitleLayer\Core\Sequence::persist_seasons(
	$season_id_term,
	array(
		array( 'key' => 'return', 'label' => 'Return renamed', 'sort' => 10 ),
		array( 'key' => 'afterword', 'label' => 'Afterword', 'sort' => 20, 'public_id' => 1 ),
	)
);
$replacement_map = array();
foreach ( \WPTitleLayer\Core\Series::seasons( $season_id_term ) as $season ) {
	$replacement_map[ $season['key'] ] = (int) $season['public_id'];
}
wptl_sequence_same( 3, $replacement_map['afterword'] ?? 0, 'A deleted Season public ID was reused.' );
wptl_sequence_same( 3, (int) get_term_meta( $season_id_term_id, 'wptl_season_public_id_highwater', true ), 'The never-reuse Season high-water mark is wrong.' );
\WPTitleLayer\Core\Sequence::persist_seasons(
	$season_id_term,
	array(
		array( 'key' => 'return', 'label' => 'Return renamed', 'sort' => 10 ),
		array( 'key' => 'afterword', 'label' => 'Afterword', 'sort' => 20 ),
		array( 'key' => '9', 'label' => 'Legacy numeric key', 'sort' => 30 ),
	)
);
$legacy_numeric_resolution = \WPTitleLayer\Core\Sequence::resolve_season_token( $season_id_term, '9' );
wptl_sequence_assert( is_array( $legacy_numeric_resolution ) && ! empty( $legacy_numeric_resolution['legacy'] ) && '9' === (string) $legacy_numeric_resolution['season']['key'], 'A pre-0.10 numeric Season key stopped resolving when no public ID matched it.' );

/* Season scope changes invalidate the old revision and append to the new one. */
$scope_term_id = wptl_sequence_term( 'WPTL 010 Season Scopes', 'seasoned' );
$scope_term    = get_term( $scope_term_id, 'wptl_series' );
\WPTitleLayer\Core\Sequence::persist_seasons(
	$scope_term,
	array(
		array( 'key' => 's1', 'label' => 'Season 1', 'sort' => 10 ),
		array( 'key' => 's2', 'label' => 'Season 2', 'sort' => 20 ),
	)
);
$scope_intro = wptl_sequence_post( 'WPTL Scope Intro', $scope_term_id, 1, 's1' );
$scope_a     = wptl_sequence_post( 'WPTL Scope A', $scope_term_id, 10, 's1' );
$scope_b     = wptl_sequence_post( 'WPTL Scope B', $scope_term_id, 20, 's1' );
$scope_c     = wptl_sequence_post( 'WPTL Scope C', $scope_term_id, 10, 's2' );
update_post_meta( $scope_intro, 'wptl_series_role', 'intro' );
\WPTitleLayer\Core\SequenceRuntime::flush();
$scope_definition_preview = \WPTitleLayer\Core\Sequence::initialization_preview( $scope_term_id );
$scope_original_seasons   = \WPTitleLayer\Core\Series::seasons( $scope_term );
$scope_changed_seasons    = $scope_original_seasons;
$scope_changed_seasons[0]['label'] = 'Season One changed after preview';
\WPTitleLayer\Core\Sequence::persist_seasons( $scope_term, $scope_changed_seasons );
wptl_sequence_error(
	'wptl_sequence_preview_changed',
	\WPTitleLayer\Core\Sequence::begin_initialization( $scope_term_id, false, (string) $scope_definition_preview['fingerprint'] ),
	'A stale preview ignored a changed Season definition.'
);
\WPTitleLayer\Core\Sequence::persist_seasons( $scope_term, $scope_original_seasons );
$scope_init = wptl_sequence_initialize( $scope_term_id );
wptl_sequence_same( 'complete', $scope_init['status'] ?? '', 'Season-scoped initialization failed.' );
wptl_sequence_same( 0, \WPTitleLayer\Core\Sequence::automatic_ordinal( $scope_intro, $scope_term ), 'A non-main role consumed an automatic article number.' );
wptl_sequence_same( 1, \WPTitleLayer\Core\Sequence::automatic_ordinal( $scope_a, $scope_term ), 'The first main article after an introduction is not number one.' );
wptl_sequence_same( 2, \WPTitleLayer\Core\Sequence::automatic_ordinal( $scope_b, $scope_term ), 'The second main article has the wrong scoped number.' );
wptl_sequence_same( 1, \WPTitleLayer\Core\Sequence::automatic_ordinal( $scope_c, $scope_term ), 'Automatic numbering did not restart in the next season.' );
$old_s1_revision = \WPTitleLayer\Core\Sequence::revision( $scope_term, 's1' );
$old_s2_revision = \WPTitleLayer\Core\Sequence::revision( $scope_term, 's2' );
update_post_meta( $scope_a, 'wptl_season_key', 's2' );
\WPTitleLayer\Core\SequenceRuntime::flush();
wptl_sequence_same( $old_s1_revision + 1, \WPTitleLayer\Core\Sequence::revision( $scope_term, 's1' ), 'Leaving a season did not invalidate its old revision.' );
wptl_sequence_same( $old_s2_revision + 1, \WPTitleLayer\Core\Sequence::revision( $scope_term, 's2' ), 'Entering a season did not advance its revision.' );
$scope_s2_order = \WPTitleLayer\Core\Sequence::ordered_post_ids( $scope_term, 's2' );
wptl_sequence_same( $scope_a, (int) end( $scope_s2_order ), 'A season-changed article was not appended to its new scope.' );
wptl_sequence_error(
	'wptl_sequence_revision_conflict',
	\WPTitleLayer\Core\Sequence::move( $scope_term_id, 's1', $scope_b, 0, 'start', $old_s1_revision ),
	'An old-season manager page remained writable after an article left.'
);

/* Role changes invalidate ordinal caches and revisions in the same request. */
$scope_b_before_role = \WPTitleLayer\Core\Sequence::automatic_ordinal( $scope_b, $scope_term );
wptl_sequence_same( 1, $scope_b_before_role, 'The remaining S1 main article did not renumber after a scope move.' );
$role_revision = \WPTitleLayer\Core\Sequence::revision( $scope_term, 's1' );
update_post_meta( $scope_b, 'wptl_series_role', 'intro' );
wptl_sequence_same( 0, \WPTitleLayer\Core\Sequence::automatic_ordinal( $scope_b, $scope_term ), 'A role edit reused a stale ordinal cache.' );
\WPTitleLayer\Core\SequenceRuntime::flush();
wptl_sequence_same( $role_revision + 1, \WPTitleLayer\Core\Sequence::revision( $scope_term, 's1' ), 'A role edit did not invalidate its sequence scope.' );
delete_post_meta( $scope_b, 'wptl_series_role' );
wptl_sequence_same( 1, \WPTitleLayer\Core\Sequence::automatic_ordinal( $scope_b, $scope_term ), 'Deleting a role did not restore main-article numbering immediately.' );
\WPTitleLayer\Core\SequenceRuntime::flush();

/* Trash and restore cannot leave a stale undo/revision boundary. */
$s2_before_trash = \WPTitleLayer\Core\Sequence::revision( $scope_term, 's2' );
wp_trash_post( $scope_c );
\WPTitleLayer\Core\SequenceRuntime::flush();
wptl_sequence_same( $s2_before_trash + 1, \WPTitleLayer\Core\Sequence::revision( $scope_term, 's2' ), 'Removing an article from editorial scope did not invalidate the season.' );
wptl_sequence_same( 1, \WPTitleLayer\Core\Sequence::automatic_ordinal( $scope_a, $scope_term ), 'The remaining public/editorial article did not renumber after trash.' );
$s2_before_restore = \WPTitleLayer\Core\Sequence::revision( $scope_term, 's2' );
wp_untrash_post( $scope_c );
\WPTitleLayer\Core\SequenceRuntime::flush();
wptl_sequence_same( $s2_before_restore + 1, \WPTitleLayer\Core\Sequence::revision( $scope_term, 's2' ), 'Restoring an article did not append a new scoped revision.' );
$scope_s2_restored_order = \WPTitleLayer\Core\Sequence::ordered_post_ids( $scope_term, 's2' );
wptl_sequence_same( $scope_c, (int) end( $scope_s2_restored_order ), 'A restored article was not appended safely.' );

$scope_context = ( new \WPTitleLayer\Presentation\Renderer() )->context( $scope_a );
wptl_sequence_assert( false !== strpos( (string) $scope_context['season_url'], 'wptl_season=2' ), 'Title presentation did not use the stable numeric Season URL.' );

/* REST can read Season definitions but cannot mutate their stable identities. */
\WPTitleLayer\Core\Series::register_rest_validation_hooks();
$term_meta_registration = get_registered_meta_keys( 'term', 'wptl_series' );
wptl_sequence_assert( empty( $term_meta_registration['wptl_sequence_schema_version']['show_in_rest'] ), 'The activation marker is directly writable through REST.' );
$season_request = new WP_REST_Request( 'POST', '/wp/v2/wptl_series/' . $scope_term_id );
$season_request->set_param( 'meta', array( 'wptl_seasons' => array() ) );
$season_guard = apply_filters( 'rest_pre_insert_wptl_series', (object) array(), $season_request );
wptl_sequence_error( 'wptl_rest_seasons_read_only', $season_guard, 'REST directly replaced stable Season definitions.' );

/* Gutenberg receives managed IDs without exposing the activation meta field. */
$editor_reflection = new ReflectionClass( \WPTitleLayer\Presentation\EditorAssets::class );
$editor_method     = $editor_reflection->getMethod( 'editorData' );
$editor_method->setAccessible( true );
$editor_data = $editor_method->invoke( null );
wptl_sequence_assert( in_array( $flat_term_id, array_map( 'absint', (array) ( $editor_data['managedSeriesIds'] ?? array() ) ), true ), 'The block editor did not receive its read-only managed-Series index.' );

/* Managed ordering remains independently available before advanced structure. */
$previous_get = $_GET;
$_GET = array( 'page' => \WPTitleLayer\Admin\SequenceManager::PAGE_SLUG, 'series_id' => (string) $flat_term_id, 'manager_view' => 'sequence', 'sequence_page' => '1' );
ob_start();
\WPTitleLayer\Admin\SequenceManager::render();
$sequence_manager_html = (string) ob_get_clean();
$_GET = $previous_get;
wptl_sequence_assert( false !== strpos( $sequence_manager_html, 'Series Sequence and Structure Manager' ) && false !== strpos( $sequence_manager_html, 'Managed sequence' ), 'A managed ordered Series lost its independent Sequence view before advanced structure activation.' );
wptl_sequence_assert( false !== strpos( $sequence_manager_html, 'data-wptl-series-combobox' ) && false !== strpos( $sequence_manager_html, 'data-wptl-series-select' ), 'The Manager omitted the searchable Series chooser or its native fallback.' );

/* Advanced structure preview and activation remain separate from Sequence. */
$previous_get = $_GET;
$_GET = array( 'page' => \WPTitleLayer\Admin\SequenceManager::PAGE_SLUG, 'series_id' => (string) $flat_term_id, 'manager_view' => 'structure' );
ob_start();
\WPTitleLayer\Admin\SequenceManager::render();
$structure_preview_html = (string) ob_get_clean();
$_GET = $previous_get;
wptl_sequence_assert( false !== strpos( $structure_preview_html, 'Read-only structure compatibility check' ) && false !== strpos( $structure_preview_html, 'Enable advanced structure for this Series' ), 'The optional advanced-structure preview did not render separately.' );

/* Manager output is bounded and never exposes private ranks as article fields. */
$flat_book_preview = \WPTitleLayer\Core\BookStructure::preview( $flat_term_id );
$flat_book_enable = \WPTitleLayer\Core\BookStructure::enable( $flat_term_id, (string) $flat_book_preview['fingerprint'] );
wptl_sequence_assert( ! is_wp_error( $flat_book_enable ), 'The already managed flat fixture could not enter the 0.11 Structure view.' );
$previous_get = $_GET;
$_GET = array( 'page' => \WPTitleLayer\Admin\SequenceManager::PAGE_SLUG, 'series_id' => (string) $flat_term_id, 'manager_view' => 'sequence', 'sequence_page' => '1' );
ob_start();
\WPTitleLayer\Admin\SequenceManager::render();
$manager_html = (string) ob_get_clean();
$_GET = $previous_get;
wptl_sequence_assert( false !== strpos( $manager_html, 'Series Sequence and Structure Manager' ) && false !== strpos( $manager_html, 'Automatic number' ), 'The managed Sequence table did not render after advanced structure activation.' );
wptl_sequence_assert( false !== strpos( $manager_html, 'Place before / after' ) && false !== strpos( $manager_html, 'Undo last move' ), 'The Manager omitted exact placement or value-checked undo.' );
wptl_sequence_assert( false === strpos( $manager_html, (string) \WPTitleLayer\Core\Sequence::RANK_STEP ), 'A private rank leaked into the Sequence Manager interface.' );
wptl_sequence_assert( false === strpos( $manager_html, 'name="wptl_sequence_rank"' ), 'The private rank was exposed as an editable article field.' );

/* Journals are explicitly non-autoloaded. */
global $wpdb;
$journal_autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'wptl_seq_init_' . $flat_term_id ) );
wptl_sequence_assert( ! in_array( $journal_autoload, array( 'yes', 'on', 'auto-on' ), true ), 'The potentially large initialization journal is autoloaded.' );
$move_autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 1", $wpdb->esc_like( 'wptl_seq_move_' . $flat_term_id . '_' ) . '%' ) );
wptl_sequence_assert( ! in_array( $move_autoload, array( 'yes', 'on', 'auto-on' ), true ), 'The latest move journal is autoloaded.' );

if ( $wptl_sequence_failures ) {
	fwrite( STDERR, "WP Title Layer Sequence Manager failures:\n- " . implode( "\n- ", $wptl_sequence_failures ) . "\n" );
	exit( 1 );
}

echo 'WP Title Layer Sequence Manager checks passed: ' . (int) $wptl_sequence_checks . "\n";
