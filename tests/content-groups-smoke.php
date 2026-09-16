<?php
/** Exercise public groups through WordPress saves, REST, queries and rendering. */
require_once '/wordpress/wp-load.php';

use WPTitleLayer\Core\ContentGroups as Groups;
use WPTitleLayer\Core\Series;
use WPTitleLayer\Core\Sequence;
use WPTitleLayer\Reader\Archive;
use WPTitleLayer\Presentation\Renderer;

$checks = 0;
$failures = array();
function group_check( $ok, $message ) {
	global $checks, $failures;
	++$checks;
	if ( ! $ok ) { $failures[] = $message; }
}
function group_post( $series, $season, $label, $position, $status = 'publish' ) {
	$id = wp_insert_post( array( 'post_title' => 'Group article ' . $position, 'post_status' => $status,
		'meta_input' => array( 'wptl_season_key' => $season, 'wptl_series_group' => $label, 'wptl_sequence_position' => $position ),
		'tax_input' => array( 'wptl_series' => array( $series->term_id ) ),
	) );
	Groups::flush();
	\WPTitleLayer\Core\SequenceRuntime::flush();
	return $id;
}
function group_query( $series, $group, $season = '', $page = 1, $size = 20 ) {
	global $wp_query, $wp_the_query;
	$old_query = $wp_query;
	$old_main = $wp_the_query;
	$wp_the_query = new WP_Query();
	$wp_query = $wp_the_query;
	$wp_query->query( array( 'wptl_series' => $series->slug, 'wptl_group' => (string) $group,
		'wptl_season' => $season, 'posts_per_page' => $size, 'paged' => $page ) );
	$result = $wp_query;
	$wp_query = $old_query;
	$wp_the_query = $old_main;
	return $result;
}

$admin = wp_insert_user( array( 'user_login' => 'groups-admin', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
wp_set_current_user( $admin );
$term = wp_insert_term( 'Rainbow', 'wptl_series' );
$series = get_term( $term['term_id'], 'wptl_series' );
update_term_meta( $series->term_id, 'wptl_series_mode', 'ordered' );
update_term_meta( $series->term_id, 'wptl_series_structure', 'seasoned' );
Sequence::persist_seasons( $series, array( array( 'key' => 's1', 'label' => 'First season', 'sort' => 1 ), array( 'key' => 's2', 'label' => 'Second season', 'sort' => 2 ) ) );
$a = group_post( $series, 's1', 'W1 看见人的软弱', 1 );
$b = group_post( $series, 's1', 'W1 看见人的软弱', 2 );
$c = group_post( $series, 's1', 'W2 看见自己的有限', 9 );
$d = group_post( $series, 's2', 'W1 看见人的软弱', 1 );
$plain = group_post( $series, 's1', '', 10 );
$draft = group_post( $series, 's1', 'W1 看见人的软弱', 3, 'draft' );
$group = Groups::for_post( $a );
group_check( $group instanceof WP_Term, 'Saving a text group did not create its stable identity.' );
if ( ! $group ) { throw new RuntimeException( implode( '; ', $failures ) ); }
group_check( Groups::for_post( $b )->term_id === $group->term_id, 'Same-scope labels did not reuse the group.' );
group_check( Groups::for_post( $d )->term_id !== $group->term_id, 'Same labels in different seasons shared identity.' );
group_check( null === Groups::for_post( $plain ), 'Blank group acquired a public group.' );
$url = Groups::url( $group, $series );
group_check( false !== strpos( $url, 'wptl_group=' . $group->term_id ) && false !== strpos( $url, 'wptl_season=1' ), 'Group URL omitted stable group or season identity.' );
$renderer = new Renderer();
$context = $renderer->context( $a );
group_check( 'Rainbow · First season · W1 看见人的软弱 · 01' === $context['eyebrow'], 'Title layer did not compose group independently from number: ' . $context['eyebrow'] );
group_check( '' === $renderer->context( $plain )['group'], 'Ungrouped title changed.' );
$archive = new Archive();
$posts = array_map( static function ( $id ) use ( $archive, $series ) { return $archive->postContext( $id, 'ordered', 'seasoned', false, false, $series ); }, array( $a, $b, $c, $plain, $a ) );
$runs = $archive->contentSegments( $posts );
group_check( 4 === count( $runs ) && 2 === count( $runs[0]['posts'] ), 'Consecutive groups were not split correctly.' );
group_check( '' === $runs[2]['label'] && $runs[3]['id'] === $group->term_id, 'Ungrouped gaps or repeated nonconsecutive groups changed article order.' );
$query = group_query( $series, $group->term_id, '1', 1, 1 );
group_check( 2 === (int) $query->found_posts && 2 === (int) $query->max_num_pages, 'Group filtering leaked other seasons, groups or drafts, or broke pagination.' );
group_check( array( $a ) === wp_list_pluck( $query->posts, 'ID' ), 'Filtered first page lost canonical order.' );
$query2 = group_query( $series, $group->term_id, '1', 2, 1 );
group_check( array( $b ) === wp_list_pluck( $query2->posts, 'ID' ), 'Filtered second page lost canonical order.' );
group_check( false !== strpos( $archive->pagination( $query ), 'wptl_group=' . $group->term_id ), 'Pagination dropped the active group filter.' );
$saved_query = $GLOBALS['wp_query'];
$GLOBALS['wp_query'] = group_query( $series, '', '1' );
ob_start();
include WPTL_PATH . 'templates/series-archive.php';
$html = ob_get_clean();
$GLOBALS['wp_query'] = $saved_query;
group_check( 2 === substr_count( $html, 'class="wptl-content-group__title"' ), 'Real archive template did not render exactly two group headings.' );
group_check( false !== strpos( $html, '<h4 class="wptl-series-list__title"' ), 'Grouped articles lack subordinate heading semantics.' );
group_check( false !== strpos( $html, 'wptl_group=' . $group->term_id ), 'Archive group heading did not include its filter URL.' );
group_check( 0 === count( group_query( $series, $group->term_id, '2' )->posts ), 'Group URL accepted an incompatible season.' );
group_check( 0 === count( group_query( $series, 'bogus' )->posts ), 'Invalid group token broadened to all articles.' );
$other = wp_insert_term( 'Other series', 'wptl_series' );
group_check( 0 === count( group_query( get_term( $other['term_id'], 'wptl_series' ), $group->term_id )->posts ), 'Foreign Series accepted a group.' );

$server = rest_get_server();
$rename = new WP_REST_Request( 'POST', '/wp-title-layer/v1/groups/' . $group->term_id );
$rename->set_param( 'label', 'W1 新组名' );
$response = $server->dispatch( $rename );
group_check( 200 === $response->get_status(), 'Group rename failed.' );
group_check( 'W1 新组名' === Groups::for_post( $b )->name && $url === Groups::url( Groups::for_post( $b ), $series ), 'Rename changed identity or failed to update other article display.' );
$get = $server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $b ) );
group_check( 'W1 新组名' === ( $get->get_data()['meta']['wptl_series_group'] ?? '' ), 'REST read returned the obsolete label after rename.' );
$new = group_post( $series, 's1', 'W1 新组名', 4 );
group_check( $group->term_id === Groups::for_post( $new )->term_id, 'Renamed label created another group.' );
Groups::sync( $a );
group_check( $group->term_id === Groups::for_post( $a )->term_id, 'Old Publisher text undid a group rename.' );
$rename->set_param( 'label', 'W2 看见自己的有限' );
group_check( 409 === $server->dispatch( $rename )->get_status(), 'Rename merged conflicting groups silently.' );

$edit = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $plain );
$edit->set_param( 'meta', array( 'wptl_series_group' => 'W1 新组名' ) );
group_check( 200 === $server->dispatch( $edit )->get_status() && $group->term_id === Groups::for_post( $plain )->term_id, 'Publisher REST text did not resolve a group before response.' );
$edit->set_param( 'meta', array( 'wptl_subtitle' => 'Independent subtitle' ) );
$server->dispatch( $edit );
group_check( $group->term_id === Groups::for_post( $plain )->term_id, 'Omitted group field cleared the group.' );
$edit->set_param( 'meta', array( 'wptl_series_group' => '' ) );
$server->dispatch( $edit );
group_check( null === Groups::for_post( $plain ) && ! get_post_meta( $plain, '_wptl_group_id', true ), 'Explicit clearing left a filterable group membership.' );
update_post_meta( $a, 'wptl_season_key', 's2' );
Groups::flush();
group_check( $group->term_id !== Groups::for_post( $a )->term_id, 'Changing season retained the former scope identity.' );
wp_set_current_user( 0 );
group_check( 401 === $server->dispatch( $rename )->get_status(), 'Anonymous caller could rename groups.' );
group_check( 401 === $server->dispatch( new WP_REST_Request( 'GET', '/wp-title-layer/v1/groups' ) )->get_status(), 'Anonymous caller could list editorial groups.' );

wp_set_current_user( $admin );
foreach ( array( 'ordered', 'unordered' ) as $mode ) {
	foreach ( array( 'flat', 'seasoned' ) as $structure ) {
		$result = wp_insert_term( 'Shape ' . $mode . $structure, 'wptl_series' );
		$shape = get_term( $result['term_id'], 'wptl_series' );
		update_term_meta( $shape->term_id, 'wptl_series_mode', $mode );
		update_term_meta( $shape->term_id, 'wptl_series_structure', $structure );
		if ( 'seasoned' === $structure ) { Sequence::persist_seasons( $shape, array( array( 'key' => 's1', 'label' => 'Season', 'sort' => 1 ) ) ); }
		$season = 'seasoned' === $structure ? 's1' : '';
		$first = group_post( $shape, $season, 'Unit A', 1 );
		$second = group_post( $shape, $season, 'Unit B', 2 );
		$unit = Groups::for_post( $first );
		group_check( $unit && array( $first ) === wp_list_pluck( group_query( $shape, $unit->term_id )->posts, 'ID' ), 'Group filtering failed for ' . $mode . '/' . $structure );
		// Activate advanced structure without altering the grouping model.
		update_term_meta( $shape->term_id, 'wptl_book_structure_version', 1 );
		$intro = group_post( $shape, $season, 'Unit A', 0 );
		update_post_meta( $intro, 'wptl_series_role', 'intro' );
		update_post_meta( $intro, 'wptl_series_scope', 'seasoned' === $structure ? 'season' : 'series' );
		Groups::flush();
		\WPTitleLayer\Core\SequenceRuntime::flush();
		$ids = wp_list_pluck( group_query( $shape, $unit->term_id )->posts, 'ID' );
		group_check( array( $first ) === $ids, 'Non-main articles leaked into group for ' . $mode . '/' . $structure );
		group_check( null === Groups::for_post( $intro ) && 'Unit A' === get_post_meta( $intro, 'wptl_series_group', true ), 'Inactive group was displayed or destructively cleared.' );
		update_post_meta( $intro, '_wptl_group_id', $unit->term_id );
		group_check( array( $first ) === wp_list_pluck( group_query( $shape, $unit->term_id )->posts, 'ID' ), 'Pre-upgrade group ID leaked a non-main article.' );
		update_post_meta( $intro, 'wptl_series_role', 'article' );
		Groups::flush();
		group_check( Groups::for_post( $intro ) && $unit->term_id === Groups::for_post( $intro )->term_id, 'Returning to main article did not restore its saved group.' );
	}
}

update_term_meta( $shape->term_id, 'wptl_series_structure', 'flat' );
Groups::flush();
group_check( Groups::for_post( $first ) && '' === Groups::post_scope( $first, $shape ), 'Changing Series structure left existing groups unresolved.' );

$author = wp_insert_user( array( 'user_login' => 'group-author', 'user_pass' => wp_generate_password(), 'role' => 'author' ) );
wp_set_current_user( $author );
$suggestions = new WP_REST_Request( 'GET', '/wp-title-layer/v1/groups' );
$suggestions->set_param( 'series', $series->term_id );
$suggestions->set_param( 'season', 's1' );
group_check( 200 === $server->dispatch( $suggestions )->get_status(), 'Assign-only author could not load group suggestions.' );
group_check( 403 === $server->dispatch( $rename )->get_status(), 'Assign-only author could rename a shared group.' );
wp_set_current_user( $admin );

$cap_request = new WP_REST_Request( 'GET', '/wp/v2/wptl_series/' . $shape->term_id );
$cap_response = $server->dispatch( $cap_request );
group_check( array( 'version' => 1, 'book_structure' => true, 'content_groups' => 'article-only' ) === ( $cap_response->get_data()['wptl_capabilities'] ?? null ), 'Enabled Series did not expose the semantic public capability.' );
$cap_write = new WP_REST_Request( 'POST', '/wp/v2/wptl_series/' . $shape->term_id );
$cap_write->set_param( 'wptl_capabilities', array( 'version' => 1, 'book_structure' => false ) );
$server->dispatch( $cap_write );
group_check( true === ( $server->dispatch( $cap_request )->get_data()['wptl_capabilities']['book_structure'] ?? null ), 'Client could overwrite computed capability.' );
$plain_cap = $server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/wptl_series/' . $other['term_id'] ) );
group_check( false === ( $plain_cap->get_data()['wptl_capabilities']['book_structure'] ?? null ), 'Non-enabled Series advertised advanced capability.' );

if ( $failures ) { fwrite( STDERR, implode( "\n", $failures ) . "\n" ); exit( 1 ); }
echo 'WP Title Layer content-group checks passed: ' . $checks . "\n";
