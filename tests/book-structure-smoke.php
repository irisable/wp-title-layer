<?php
/**
 * WP Title Layer 0.11 book-structure integration checks.
 *
 * @package WPTitleLayer
 */

require_once '/wordpress/wp-load.php';

$wptl_book_checks   = 0;
$wptl_book_failures = array();

function wptl_book_assert( $condition, $message ) {
	global $wptl_book_checks, $wptl_book_failures;
	++$wptl_book_checks;
	if ( ! $condition ) {
		$wptl_book_failures[] = $message;
	}
}

function wptl_book_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		$message .= sprintf( ' (expected %s, got %s)', var_export( $expected, true ), var_export( $actual, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
	}
	wptl_book_assert( $expected === $actual, $message );
}

function wptl_book_error( $code, $result, $message ) {
	wptl_book_same( $code, is_wp_error( $result ) ? $result->get_error_code() : '', $message );
}

function wptl_book_term( $name, $mode, $structure ) {
	$result = wp_insert_term( $name, 'wptl_series' );
	if ( is_wp_error( $result ) ) {
		wptl_book_assert( false, 'Could not create Series fixture: ' . $result->get_error_message() );
		return null;
	}
	$term_id = (int) $result['term_id'];
	update_term_meta( $term_id, 'wptl_series_mode', $mode );
	update_term_meta( $term_id, 'wptl_series_structure', $structure );
	$term = get_term( $term_id, 'wptl_series' );
	if ( $term instanceof WP_Term && 'seasoned' === $structure ) {
		\WPTitleLayer\Core\Sequence::persist_seasons(
			$term,
			array(
				array( 'key' => 's1', 'label' => 'Season One', 'sort' => 10 ),
				array( 'key' => 's2', 'label' => 'Season Two', 'sort' => 20 ),
			)
		);
	}
	return $term instanceof WP_Term ? $term : null;
}

function wptl_book_post( $title, WP_Term $term, $position = null, $season = '', $role = '', $scope = '', $date = '' ) {
	$args = array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $title );
	if ( '' !== $date ) {
		$args['post_date'] = $date;
		$args['post_date_gmt'] = get_gmt_from_date( $date );
	}
	$post_id = wp_insert_post( $args, true );
	if ( is_wp_error( $post_id ) ) {
		wptl_book_assert( false, 'Could not create article fixture: ' . $post_id->get_error_message() );
		return 0;
	}
	wp_set_object_terms( (int) $post_id, array( (int) $term->term_id ), $term->taxonomy, false );
	if ( null !== $position ) {
		update_post_meta( (int) $post_id, 'wptl_sequence_position', $position );
	}
	if ( '' !== $season ) {
		update_post_meta( (int) $post_id, 'wptl_season_key', $season );
	}
	if ( '' !== $role ) {
		update_post_meta( (int) $post_id, 'wptl_series_role', $role );
	}
	if ( '' !== $scope ) {
		update_post_meta( (int) $post_id, 'wptl_series_scope', $scope );
	}
	return (int) $post_id;
}

function wptl_book_initialize( WP_Term $term ) {
	$preview = \WPTitleLayer\Core\Sequence::initialization_preview( (int) $term->term_id );
	$result = \WPTitleLayer\Core\Sequence::begin_initialization( (int) $term->term_id, false, (string) ( $preview['fingerprint'] ?? '' ) );
	for ( $attempt = 0; ! is_wp_error( $result ) && 'writing' === ( $result['status'] ?? '' ) && 100 > $attempt; ++$attempt ) {
		$result = \WPTitleLayer\Core\Sequence::continue_initialization( (int) $term->term_id, 2 );
	}
	return $result;
}

function wptl_book_enable( WP_Term $term ) {
	$preview = \WPTitleLayer\Core\BookStructure::preview( (int) $term->term_id );
	return \WPTitleLayer\Core\BookStructure::enable( (int) $term->term_id, (string) ( $preview['fingerprint'] ?? '' ) );
}

function wptl_book_archive_ids( WP_Term $term, $season_key = '' ) {
	$args = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'fields'              => 'ids',
		'posts_per_page'      => -1,
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
		'orderby'             => 'none',
		'tax_query'           => array( array( 'taxonomy' => $term->taxonomy, 'field' => 'term_id', 'terms' => array( (int) $term->term_id ) ) ),
		'wptl_series_ordering'=> array(
			'term_id'        => (int) $term->term_id,
			'mode'           => \WPTitleLayer\Core\Series::mode( $term ),
			'structure'      => \WPTitleLayer\Core\Series::structure( $term ),
			'archive_sort'   => \WPTitleLayer\Core\Series::archive_sort( $term ),
			'season_key'     => $season_key,
			'book_structure' => true,
		),
	);
	if ( '' !== $season_key ) {
		$args['meta_query'] = array( array( 'key' => 'wptl_season_key', 'value' => $season_key, 'compare' => '=' ) );
	}
	$query = new WP_Query( $args );
	return array_map( 'absint', (array) $query->posts );
}

$admin_id = wp_insert_user(
	array(
		'user_login' => 'wptl-book-admin',
		'user_pass'  => wp_generate_password( 24, true, true ),
		'user_email' => 'wptl-book-admin@example.invalid',
		'role'       => 'administrator',
	)
);
wptl_book_assert( ! is_wp_error( $admin_id ), 'Could not create the book-structure administrator.' );
if ( ! is_wp_error( $admin_id ) ) {
	wp_set_current_user( (int) $admin_id );
}

wptl_book_same( 1, \WPTitleLayer\Core\BookStructure::VERSION, 'The book-structure schema version changed unexpectedly.' );
wptl_book_assert( taxonomy_exists( 'wptl_series' ), 'The Series taxonomy is unavailable.' );

/* Legacy non-main entries in a seasoned Series are never silently guessed. */
$ambiguous_term = wptl_book_term( 'WPTL 011 Ambiguous Preview', 'unordered', 'seasoned' );
$ambiguous_intro = wptl_book_post( 'Ambiguous old introduction', $ambiguous_term, null, 's1', 'intro' );
$ambiguous_preview = \WPTitleLayer\Core\BookStructure::preview( (int) $ambiguous_term->term_id );
wptl_book_same( array( $ambiguous_intro ), array_map( 'absint', wp_list_pluck( (array) $ambiguous_preview['ambiguous'], 'id' ) ), 'A legacy seasoned introduction was silently assigned a scope.' );
wptl_book_error( 'wptl_book_unresolved', wptl_book_enable( $ambiguous_term ), 'Book structure enabled while a legacy scope remained ambiguous.' );
update_post_meta( $ambiguous_intro, 'wptl_series_scope', 'season' );
wptl_book_assert( ! is_wp_error( wptl_book_enable( $ambiguous_term ) ), 'An explicitly resolved legacy introduction could not enable book structure.' );

/* Unknown legacy role/scope values block activation instead of becoming main articles. */
$invalid_term = wptl_book_term( 'WPTL 011 Invalid Legacy Structure', 'unordered', 'flat' );
$invalid_role = wptl_book_post( 'Unknown legacy role', $invalid_term );
$invalid_scope = wptl_book_post( 'Unknown legacy scope', $invalid_term, null, '', 'intro' );
global $wpdb;
$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $invalid_role, 'meta_key' => 'wptl_series_role', 'meta_value' => 'mystery-role' ), array( '%d', '%s', '%s' ) );
$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $invalid_scope, 'meta_key' => 'wptl_series_scope', 'meta_value' => 'mystery-scope' ), array( '%d', '%s', '%s' ) );
wp_cache_delete( $invalid_role, 'post_meta' );
wp_cache_delete( $invalid_scope, 'post_meta' );
$invalid_preview = \WPTitleLayer\Core\BookStructure::preview( (int) $invalid_term->term_id );
wptl_book_same( array( $invalid_role, $invalid_scope ), array_map( 'absint', wp_list_pluck( (array) $invalid_preview['invalid'], 'id' ) ), 'Unknown legacy role or scope values were silently normalized during preview.' );
wptl_book_error( 'wptl_book_unresolved', wptl_book_enable( $invalid_term ), 'Book structure enabled while an unknown legacy role or scope remained.' );

/* Activation fingerprints freeze every ordering source, not only track counts. */
$stale_term = wptl_book_term( 'WPTL 011 Stale Book Preview', 'unordered', 'flat' );
$stale_intro = wptl_book_post( 'Legacy preface reordered after preview', $stale_term, 1, '', 'intro' );
\WPTitleLayer\Core\SequenceRuntime::flush();
$stale_preview = \WPTitleLayer\Core\BookStructure::preview( (int) $stale_term->term_id );
update_post_meta( $stale_intro, 'wptl_sequence_position', 2 );
wptl_book_error(
	'wptl_book_preview_changed',
	\WPTitleLayer\Core\BookStructure::enable( (int) $stale_term->term_id, (string) $stale_preview['fingerprint'] ),
	'A changed legacy ordering source did not invalidate the book-structure preview.'
);

/* Flat + ordered: bookends surround main articles without consuming ordinals. */
$flat_ordered = wptl_book_term( 'WPTL 011 Flat Ordered', 'ordered', 'flat' );
$fo_intro_a = wptl_book_post( 'Flat preface A', $flat_ordered, 1, '', 'intro' );
$fo_intro_b = wptl_book_post( 'Flat preface B', $flat_ordered, 2, '', 'intro' );
$fo_main_a  = wptl_book_post( 'Flat main A', $flat_ordered, 10 );
$fo_main_b  = wptl_book_post( 'Flat main B', $flat_ordered, 20 );
$fo_end     = wptl_book_post( 'Flat afterword', $flat_ordered, 30, '', 'epilogue' );
update_post_meta( $fo_intro_b, 'wptl_sequence_label', '0-2' );
\WPTitleLayer\Core\SequenceRuntime::flush();
wptl_book_same( 'complete', wptl_book_initialize( $flat_ordered )['status'] ?? '', 'The flat ordered Series did not initialize.' );
wptl_book_assert( ! is_wp_error( wptl_book_enable( $flat_ordered ) ), 'The flat ordered Series could not enable book structure.' );
wptl_book_same( array( $fo_intro_a, $fo_intro_b, $fo_main_a, $fo_main_b, $fo_end ), \WPTitleLayer\Core\Sequence::ordered_post_ids( $flat_ordered ), 'Flat ordered tracks produced the wrong reading order.' );
wptl_book_same( 0, \WPTitleLayer\Core\Sequence::automatic_ordinal( $fo_intro_a, $flat_ordered ), 'A preface consumed a main-article number.' );
wptl_book_same( 1, \WPTitleLayer\Core\Sequence::automatic_ordinal( $fo_main_a, $flat_ordered ), 'The first main article was not numbered one.' );
wptl_book_same( 2, \WPTitleLayer\Core\Sequence::automatic_ordinal( $fo_main_b, $flat_ordered ), 'The second main article was not numbered two.' );
wptl_book_same( '0-2', (string) get_post_meta( $fo_intro_b, 'wptl_sequence_label', true ), 'Activation changed a public structure label.' );
$fo_main_rank = \WPTitleLayer\Core\Sequence::rank( $fo_main_a );
$fo_main_revision = \WPTitleLayer\Core\Sequence::track_revision( $flat_ordered, 'series' );
update_post_meta( $fo_main_a, 'wptl_series_role', 'article' );
\WPTitleLayer\Core\SequenceRuntime::flush();
wptl_book_same( $fo_main_rank, \WPTitleLayer\Core\Sequence::rank( $fo_main_a ), 'Writing the explicit default article role moved an article.' );
wptl_book_same( $fo_main_revision, \WPTitleLayer\Core\Sequence::track_revision( $flat_ordered, 'series' ), 'Writing the explicit default article role advanced the track revision.' );
delete_post_meta( $fo_main_a, 'wptl_series_role' );
\WPTitleLayer\Core\SequenceRuntime::flush();
wptl_book_same( $fo_main_rank, \WPTitleLayer\Core\Sequence::rank( $fo_main_a ), 'Removing the explicit default article role moved an article.' );
wptl_book_same( $fo_main_revision, \WPTitleLayer\Core\Sequence::track_revision( $flat_ordered, 'series' ), 'Removing the explicit default article role advanced the track revision.' );
update_post_meta( $fo_main_a, 'wptl_series_scope', 'series' );
\WPTitleLayer\Core\SequenceRuntime::flush();
wptl_book_same( $fo_main_rank, \WPTitleLayer\Core\Sequence::rank( $fo_main_a ), 'Writing a redundant flat-Series scope moved an article.' );
wptl_book_same( $fo_main_revision, \WPTitleLayer\Core\Sequence::track_revision( $flat_ordered, 'series' ), 'Writing a redundant flat-Series scope advanced the track revision.' );
delete_post_meta( $fo_main_a, 'wptl_series_scope' );
\WPTitleLayer\Core\SequenceRuntime::flush();
wptl_book_same( $fo_main_rank, \WPTitleLayer\Core\Sequence::rank( $fo_main_a ), 'Removing a redundant flat-Series scope moved an article.' );
wptl_book_same( $fo_main_revision, \WPTitleLayer\Core\Sequence::track_revision( $flat_ordered, 'series' ), 'Removing a redundant flat-Series scope advanced the track revision.' );
$fo_bounded_page = \WPTitleLayer\Core\Sequence::track_page( (int) $flat_ordered->term_id, 'series', 1, 1 );
wptl_book_same( array( 2, 2 ), array( (int) $fo_bounded_page['total'], (int) $fo_bounded_page['total_pages'] ), 'The main structure track did not keep database-level pagination totals.' );
wptl_book_same( 1, count( (array) $fo_bounded_page['rows'] ), 'A one-row structure page loaded more than its requested page size.' );
$fo_intro_track = \WPTitleLayer\Core\BookStructure::track_key( $flat_ordered, 'series', '', 'intro' );
$fo_revision = \WPTitleLayer\Core\Sequence::track_revision( $flat_ordered, $fo_intro_track );
wptl_book_assert( ! is_wp_error( \WPTitleLayer\Core\Sequence::move_track( (int) $flat_ordered->term_id, $fo_intro_track, $fo_intro_b, $fo_intro_a, 'before', $fo_revision ) ), 'Two flat prefaces could not be reordered.' );
wptl_book_same( array( $fo_intro_b, $fo_intro_a ), \WPTitleLayer\Core\BookStructure::track_post_ids( $flat_ordered, \WPTitleLayer\Core\BookStructure::track( $flat_ordered, $fo_intro_track ) ), 'The preface track move was not canonical.' );
$fo_intro_revision = \WPTitleLayer\Core\Sequence::track_revision( $flat_ordered, $fo_intro_track );
wp_trash_post( $fo_intro_a );
\WPTitleLayer\Core\SequenceRuntime::flush();
wptl_book_same( $fo_intro_revision + 1, \WPTitleLayer\Core\Sequence::track_revision( $flat_ordered, $fo_intro_track ), 'Trashing a non-main entry invalidated the main track instead of its own track.' );
wp_untrash_post( $fo_intro_a );
\WPTitleLayer\Core\SequenceRuntime::flush();
wptl_book_same( $fo_intro_revision + 2, \WPTitleLayer\Core\Sequence::track_revision( $flat_ordered, $fo_intro_track ), 'Restoring a non-main entry did not advance its own track revision.' );
$unresolved_archive_groups = ( new \WPTitleLayer\Reader\Archive() )->groupPosts( $flat_ordered, array( array( 'id' => $fo_main_a, 'track' => '' ) ) );
wptl_book_same( $fo_main_a, (int) ( $unresolved_archive_groups[0]['posts'][0]['id'] ?? 0 ), 'A damaged active structure silently removed its article from the structured archive.' );
$fo_health = ( new \WPTitleLayer\Admin\SeriesHealthReport() )->report( (int) $flat_ordered->term_id );
wptl_book_same( 0, (int) $fo_health['summary']['duplicate_positions'], 'Equal ranks in different flat tracks were reported as duplicates.' );
wptl_book_error( 'wptl_sequence_book_structure_active', \WPTitleLayer\Core\Sequence::rollback_initialization( (int) $flat_ordered->term_id ), 'A 0.10 initialization rollback was allowed after 0.11 activation.' );

/* Seasoned + ordered: Series-wide and per-season tracks have exact boundaries. */
$seasoned_ordered = wptl_book_term( 'WPTL 011 Seasoned Ordered', 'ordered', 'seasoned' );
$so_series_intro = wptl_book_post( 'Whole-book preface', $seasoned_ordered, 1, 's1', 'intro' );
$so_s1_intro     = wptl_book_post( 'Season one preface', $seasoned_ordered, 2, 's1', 'intro' );
$so_s1_main      = wptl_book_post( 'Season one main', $seasoned_ordered, 10, 's1' );
$so_s1_end       = wptl_book_post( 'Season one afterword', $seasoned_ordered, 20, 's1', 'epilogue' );
$so_s2_main      = wptl_book_post( 'Season two main', $seasoned_ordered, 10, 's2' );
$so_series_end   = wptl_book_post( 'Whole-book afterword', $seasoned_ordered, 20, 's2', 'epilogue' );
wptl_book_same( 'complete', wptl_book_initialize( $seasoned_ordered )['status'] ?? '', 'The seasoned ordered Series did not initialize.' );
update_post_meta( $so_series_intro, 'wptl_series_scope', 'series' );
update_post_meta( $so_s1_intro, 'wptl_series_scope', 'season' );
update_post_meta( $so_s1_end, 'wptl_series_scope', 'season' );
update_post_meta( $so_series_end, 'wptl_series_scope', 'series' );
\WPTitleLayer\Core\SequenceRuntime::flush();
wptl_book_assert( ! is_wp_error( wptl_book_enable( $seasoned_ordered ) ), 'The seasoned ordered Series could not enable book structure.' );
$so_full = array( $so_series_intro, $so_s1_intro, $so_s1_main, $so_s1_end, $so_s2_main, $so_series_end );
wptl_book_same( $so_full, \WPTitleLayer\Core\Sequence::ordered_post_ids( $seasoned_ordered ), 'Seasoned full-Series track order is wrong.' );
wptl_book_same( array( $so_s1_intro, $so_s1_main, $so_s1_end ), \WPTitleLayer\Core\Sequence::ordered_post_ids( $seasoned_ordered, 's1' ), 'A season reading collection leaked Series-wide or another-season entries.' );
wptl_book_same( '', ( new \WPTitleLayer\Presentation\Renderer() )->context( $so_series_intro )['season_key'], 'A Series-wide preface exposed its stale legacy Season.' );
$so_series_intro_context = ( new \WPTitleLayer\Presentation\Renderer() )->context( $so_series_intro );
wptl_book_same( '', (string) ( $so_series_intro_context['sequence_item'] ?? '' ), 'A private Series role leaked into the public title layer without an explicit public structure label.' );
update_post_meta( $so_series_intro, 'wptl_sequence_label', 'Series preface' );
$so_series_intro_context = ( new \WPTitleLayer\Presentation\Renderer() )->context( $so_series_intro );
wptl_book_same( 'Series preface', (string) ( $so_series_intro_context['sequence_item'] ?? '' ), 'An explicit public structure label did not reach the public title layer.' );
$so_series_track = \WPTitleLayer\Core\BookStructure::track_key( $seasoned_ordered, 'series', '', 'intro' );
wptl_book_assert( ! is_wp_error( \WPTitleLayer\Core\Sequence::move_track( (int) $seasoned_ordered->term_id, $so_series_track, $so_series_intro, 0, 'end', \WPTitleLayer\Core\Sequence::track_revision( $seasoned_ordered, $so_series_track ) ) ), 'A Series-wide track in a seasoned Series was incorrectly rejected for lacking a Season.' );
update_term_meta( (int) $seasoned_ordered->term_id, 'wptl_navigation_scope', 'season' );
$so_navigation = new \WPTitleLayer\Reader\Navigation();
wptl_book_same( array( $so_s1_intro, $so_s1_main, $so_s1_end ), $so_navigation->scopedPostIds( $seasoned_ordered, 's1' ), 'Season-scoped navigation disagreed with canonical book tracks.' );
wptl_book_assert( empty( $so_navigation->context( $so_series_intro )['enabled'] ), 'A Series-wide preface invented a current-season navigation context.' );

/* Flat + unordered: only non-main tracks are manually ordered. */
$flat_unordered = wptl_book_term( 'WPTL 011 Flat Unordered', 'unordered', 'flat' );
update_term_meta( (int) $flat_unordered->term_id, 'wptl_archive_sort', 'date_desc' );
$fu_intro_a = wptl_book_post( 'Unordered preface A', $flat_unordered, null, '', 'intro', '', '2025-01-01 10:00:00' );
$fu_intro_b = wptl_book_post( 'Unordered preface B', $flat_unordered, null, '', 'intro', '', '2025-01-02 10:00:00' );
$fu_main_old = wptl_book_post( 'Unordered main old', $flat_unordered, null, '', '', '', '2025-02-01 10:00:00' );
$fu_main_new = wptl_book_post( 'Unordered main new', $flat_unordered, null, '', '', '', '2025-03-01 10:00:00' );
$fu_end = wptl_book_post( 'Unordered afterword', $flat_unordered, null, '', 'epilogue', '', '2025-04-01 10:00:00' );
wptl_book_assert( ! is_wp_error( wptl_book_enable( $flat_unordered ) ), 'The flat unordered Series could not enable book structure.' );
$fu_main_track = \WPTitleLayer\Core\BookStructure::track( $flat_unordered, 'series' );
wptl_book_assert( empty( $fu_main_track['movable'] ), 'Unordered main articles became manually ordered.' );
$fu_intro_track = 'series_intro';
$fu_revision = \WPTitleLayer\Core\Sequence::track_revision( $flat_unordered, $fu_intro_track );
wptl_book_assert( ! is_wp_error( \WPTitleLayer\Core\Sequence::move_track( (int) $flat_unordered->term_id, $fu_intro_track, $fu_intro_b, $fu_intro_a, 'before', $fu_revision ) ), 'An unordered Series preface track could not be moved.' );
wptl_book_same( array(), \WPTitleLayer\Core\Sequence::ordered_post_ids( $flat_unordered ), 'An unordered Series invented previous/next order.' );
wptl_book_same( array( $fu_intro_b, $fu_intro_a, $fu_main_new, $fu_main_old, $fu_end ), wptl_book_archive_ids( $flat_unordered ), 'The unordered archive did not keep bookends at the edges and body date sorting in the middle.' );

/* Seasoned + unordered: filtered archives exclude Series-wide bookends. */
$seasoned_unordered = wptl_book_term( 'WPTL 011 Seasoned Unordered', 'unordered', 'seasoned' );
update_term_meta( (int) $seasoned_unordered->term_id, 'wptl_archive_sort', 'title' );
$su_series_intro = wptl_book_post( 'Whole collection preface', $seasoned_unordered, null, 's1', 'intro', 'series' );
$su_s1_intro = wptl_book_post( 'S1 preface', $seasoned_unordered, null, 's1', 'intro', 'season' );
$su_s1_b = wptl_book_post( 'Bravo body', $seasoned_unordered, null, 's1' );
$su_s1_a = wptl_book_post( 'Alpha body', $seasoned_unordered, null, 's1' );
$su_s1_end = wptl_book_post( 'S1 afterword', $seasoned_unordered, null, 's1', 'epilogue', 'season' );
$su_series_end = wptl_book_post( 'Whole collection afterword', $seasoned_unordered, null, 's1', 'epilogue', 'series' );
wptl_book_assert( ! is_wp_error( wptl_book_enable( $seasoned_unordered ) ), 'The seasoned unordered Series could not enable book structure.' );
wptl_book_same( array( $su_s1_intro, $su_s1_a, $su_s1_b, $su_s1_end ), wptl_book_archive_ids( $seasoned_unordered, 's1' ), 'A filtered unordered Season archive leaked Series-wide bookends or lost body title sorting.' );
$su_archive = new \WPTitleLayer\Reader\Archive();
$su_contexts = array_map(
	static function ( $post_id ) use ( $su_archive, $seasoned_unordered ) {
		return $su_archive->postContext( (int) $post_id, 'unordered', 'seasoned', false, false, $seasoned_unordered );
	},
	array( $su_series_intro, $su_s1_intro, $su_s1_b, $su_s1_a, $su_s1_end, $su_series_end )
);
$su_contexts[0]['sequence_label'] = 'Collection preface';
$su_contexts[5]['sequence_label'] = 'Collection afterword';
$su_groups = $su_archive->groupPosts( $seasoned_unordered, $su_contexts );
$su_group_semantics = array();
foreach ( $su_groups as $su_group ) {
	$su_group_semantics[ (string) $su_group['key'] ] = (bool) $su_group['ordered'];
}
wptl_book_same( false, $su_group_semantics['season_s1'] ?? null, 'A seasoned unordered main-article group rendered as an ordered list.' );
wptl_book_same(
	array( $su_s1_intro, $su_s1_b, $su_s1_a, $su_s1_end ),
	array_map( 'absint', wp_list_pluck( (array) ( $su_groups[1]['posts'] ?? array() ), 'id' ) ),
	'A public Season group did not merge its role tracks in canonical order.'
);
wptl_book_same( 'Season One', (string) ( $su_groups[1]['label'] ?? '' ), 'A public Season group exposed a role-specific track label.' );
wptl_book_same( 'Series prefaces', (string) ( $su_groups[0]['label'] ?? '' ), 'A Series-wide introduction did not use its stable public heading.' );
wptl_book_same( 'Series afterwords', (string) ( $su_groups[2]['label'] ?? '' ), 'A Series-wide epilogue did not use its stable public heading.' );
wptl_book_same( true, (bool) ( $su_groups[0]['show_sequence_labels'] ?? false ), 'A Series-wide article lost its public structure label.' );
$extra_intro = $su_contexts[0];
$extra_intro['id'] = 987654;
$extra_intro['sequence_label'] = 'Preface II';
$multi_contexts = $su_contexts;
array_splice( $multi_contexts, 1, 0, array( $extra_intro ) );
$multi_groups = $su_archive->groupPosts( $seasoned_unordered, $multi_contexts );
wptl_book_same( 3, count( $multi_groups ), 'Two Series introductions created two season-level headings.' );
wptl_book_same( array( 'Collection preface', 'Preface II' ), wp_list_pluck( $multi_groups[0]['posts'], 'sequence_label' ), 'Merged introductions lost independent public labels or order.' );
$su_template = file_get_contents( WPTL_PATH . 'templates/series-archive.php' );
wptl_book_assert( false !== strpos( $su_template, "! empty( \$wptl_group['ordered'] )" ), 'The archive template stopped consuming the group semantic flag.' );
$su_health = ( new \WPTitleLayer\Admin\SeriesHealthReport() )->report( (int) $seasoned_unordered->term_id );
wptl_book_same( 0, (int) $su_health['summary']['duplicate_positions'], 'Equal ranks in different seasoned tracks were reported as duplicates.' );
delete_post_meta( $su_s1_intro, 'wptl_sequence_rank' );
$su_damaged_health = ( new \WPTitleLayer\Admin\SeriesHealthReport() )->report( (int) $seasoned_unordered->term_id );
wptl_book_same( 1, (int) $su_damaged_health['summary']['position_issues'], 'Health did not detect a missing non-main rank in an unordered Series.' );

/* Empty new ordered Series can start canonical; existing members cannot bypass preview. */
$new_ordered = wptl_book_term( 'WPTL 011 New Empty Ordered', 'ordered', 'seasoned' );
wptl_book_same( true, \WPTitleLayer\Core\Sequence::activate_empty( $new_ordered ), 'An empty new ordered Series could not activate canonical order.' );
update_term_meta( (int) $new_ordered->term_id, 'wptl_book_structure_version', 1 );
$new_main = wptl_book_post( 'First new canonical article', $new_ordered, null, 's1' );
\WPTitleLayer\Core\SequenceRuntime::flush();
wptl_book_same( 1, \WPTitleLayer\Core\Sequence::automatic_ordinal( $new_main, $new_ordered ), 'The first article in a new Series fell back to legacy position order.' );
$nonempty_ordered = wptl_book_term( 'WPTL 011 Existing Nonempty Ordered', 'ordered', 'flat' );
wptl_book_post( 'Existing member', $nonempty_ordered, 1 );
wptl_book_error( 'wptl_sequence_not_empty', \WPTitleLayer\Core\Sequence::activate_empty( $nonempty_ordered ), 'A nonempty existing Series bypassed the migration preview.' );

/* Editor boundaries: attachments and native quick/bulk taxonomy UI stay out. */
$taxonomy_object = get_taxonomy( 'wptl_series' );
wptl_book_assert( $taxonomy_object instanceof WP_Taxonomy && empty( $taxonomy_object->show_in_quick_edit ), 'The native multi-Series quick/bulk control remains enabled.' );
$editor_reflection = new ReflectionClass( \WPTitleLayer\Presentation\EditorAssets::class );
$editor_method = $editor_reflection->getMethod( 'editorData' );
$editor_method->setAccessible( true );
$editor_data = $editor_method->invoke( null );
wptl_book_assert( ! in_array( 'attachment', (array) ( $editor_data['supportedPostTypes'] ?? array() ), true ), 'The block-editor Title Layer panel still targets Media attachments.' );
$classic_reflection = new ReflectionClass( \WPTitleLayer\Admin\ClassicEditor::class );
$classic_method = $classic_reflection->getMethod( 'postTypes' );
$classic_method->setAccessible( true );
wptl_book_assert( ! in_array( 'attachment', (array) $classic_method->invoke( null ), true ), 'The Classic Editor Title Layer box still targets Media attachments.' );

if ( $wptl_book_failures ) {
	fwrite( STDERR, "WP Title Layer book-structure failures:\n- " . implode( "\n- ", $wptl_book_failures ) . "\n" );
	exit( 1 );
}

echo 'WP Title Layer book-structure checks passed: ' . (int) $wptl_book_checks . "\n";
