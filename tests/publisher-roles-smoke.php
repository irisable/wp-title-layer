<?php
/** Dispatch request fixtures generated and checked by the real Publisher client. */
require_once '/wordpress/wp-load.php';

$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/publisher-rc4-requests.json' ), true );
$checks = 0;
$errors = array();
function publisher_check( $ok, $message ) {
	global $checks, $errors;
	++$checks;
	if ( ! $ok ) { $errors[] = $message; }
}
wp_set_current_user( 1 );
$created = wp_insert_term( $fixture['term']['name'], 'wptl_series', array( 'slug' => $fixture['term']['slug'] ) );
$term = get_term( $created['term_id'], 'wptl_series' );
foreach ( $fixture['term']['meta'] as $key => $value ) {
	if ( 'wptl_seasons' !== $key ) { update_term_meta( $term->term_id, $key, $value ); }
}
\WPTitleLayer\Core\Sequence::persist_seasons( $term, $fixture['term']['meta']['wptl_seasons'] );
\WPTitleLayer\Core\Sequence::activate_empty( $term );
update_term_meta( $term->term_id, 'wptl_book_structure_version', 1 );
$server = rest_get_server();
$definition = $server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/wptl_series/' . $term->term_id ) )->get_data();
publisher_check( $fixture['term']['wptl_capabilities'] === $definition['wptl_capabilities'], 'Publisher capability fixture differs from live term response.' );
foreach ( $fixture['requests'] as $case ) {
	$payload = $case['payload'];
	$payload['wptl_series'] = array( $term->term_id );
	$path = '/wp/v2/posts';
	if ( isset( $case['initialMeta'] ) ) {
		$id = wp_insert_post( array( 'post_title' => 'Existing Publisher article', 'post_status' => 'draft', 'meta_input' => $case['initialMeta'] ) );
		$path .= '/' . $id;
	}
	$request = new WP_REST_Request( 'POST', $path );
	$request->set_body_params( $payload );
	$response = $server->dispatch( $request );
	publisher_check( in_array( $response->get_status(), array( 200, 201 ), true ), $case['name'] . ': write failed ' . wp_json_encode( $response->get_data() ) );
	$data = $response->get_data();
	if ( empty( $data['id'] ) ) { continue; }
	$id = (int) $data['id'];
	$get = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id );
	$get->set_param( 'context', 'edit' );
	$data = $server->dispatch( $get )->get_data();
	foreach ( $payload['meta'] as $key => $value ) {
		publisher_check( $value === ( $data['meta'][ $key ] ?? null ), $case['name'] . ': readback differs for ' . $key );
	}
	if ( isset( $case['initialMeta'] ) ) {
		publisher_check( $case['initialMeta']['wptl_series_group'] === get_post_meta( $id, 'wptl_series_group', true ), 'Non-main save erased historical group.' );
		publisher_check( null === \WPTitleLayer\Core\ContentGroups::for_post( $id ), 'Non-main historical group remains effective.' );
	}
	// Also exercise the stricter publication path, not only draft persistence.
	$publish = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $id );
	$publish->set_param( 'status', 'publish' );
	publisher_check( 200 === $server->dispatch( $publish )->get_status(), $case['name'] . ': publishing failed.' );
	\WPTitleLayer\Core\SequenceRuntime::flush();
	$rank = get_post_meta( $id, 'wptl_sequence_rank', true );
	$_POST = array( 'wptl_classic_editor_nonce' => wp_create_nonce( 'wptl_save_classic_editor' ), 'wptl_classic' => array(
		'_present' => '1', 'series_id' => $term->term_id,
		'series_role' => $payload['meta']['wptl_series_role'], 'series_scope' => $payload['meta']['wptl_series_scope'],
		'season_key' => $payload['meta']['wptl_season_key'], 'sequence_label' => 'Updated label',
	) );
	\WPTitleLayer\Admin\ClassicEditor::savePost( $id, get_post( $id ), true );
	\WPTitleLayer\Core\SequenceRuntime::flush();
	publisher_check( $rank === get_post_meta( $id, 'wptl_sequence_rank', true ), $case['name'] . ': label-only classic save changed private rank.' );
	$_POST = array();
}
if ( $errors ) { fwrite( STDERR, implode( "\n", $errors ) . "\n" ); exit( 1 ); }
echo 'WP Title Layer Publisher contract checks passed: ' . $checks . "\n";
