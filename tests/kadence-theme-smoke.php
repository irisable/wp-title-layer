<?php
/**
 * Real-template smoke test for the bundled Kadence title adapter.
 *
 * Run through bin/test-kadence.sh with an official Kadence theme ZIP.
 *
 * @package WPTitleLayer
 */

require_once '/wordpress/wp-load.php';

$failures = array();
$checks   = 0;

$assert = static function ( bool $condition, string $message ) use ( &$failures, &$checks ): void {
	++$checks;
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( 'kadence' === get_template(), 'Kadence was not the active parent theme.' );
$assert( function_exists( 'Kadence\\kadence' ), 'Kadence theme functions did not load.' );

$post_id = wp_insert_post(
	array(
		'post_title'   => 'Kadence Primary Title',
		'post_content' => '<p>Article body sentinel.</p>',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	)
);
$term = wp_insert_term( 'Kadence Integration Series', 'wptl_series' );
$term_id = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
$assert( is_int( $post_id ) && $post_id > 0 && $term_id > 0, 'Could not create the Kadence fixture content.' );

update_term_meta( $term_id, 'wptl_short_label', 'Kadence Series' );
update_term_meta( $term_id, 'wptl_series_mode', 'ordered' );
update_term_meta( $term_id, 'wptl_series_structure', 'seasoned' );
update_term_meta( $term_id, 'wptl_seasons', array( array( 'key' => 'arrival', 'label' => 'Arrival season', 'sort' => 10 ) ) );
wp_set_object_terms( $post_id, array( $term_id ), 'wptl_series' );
update_post_meta( $post_id, 'wptl_subtitle', 'Kadence subtitle sentinel' );
update_post_meta( $post_id, 'wptl_season_key', 'arrival' );
update_post_meta( $post_id, 'wptl_sequence_position', 4 );
update_option(
	'wptl_settings',
	array(
		'presentation' => array(
			'default_template' => 'standard',
			'display_mode'     => 'replace-theme-title',
			'auto_post_types'  => array( 'post' ),
		),
		'reader' => array(
			'archive_template_enabled'      => false,
			'archive_show_subtitles'        => true,
			'theme_archive_show_subtitles'  => true,
			'after_content_enabled'          => false,
			'shortcode_enabled'              => true,
		),
	)
);

$query = new WP_Query( array( 'p' => $post_id ) );
$query->the_post();
$GLOBALS['wp_query']     = $query;
$GLOBALS['wp_the_query'] = $query;
$GLOBALS['post']         = get_post( $post_id );
$series_url    = esc_url( (string) get_term_link( $term_id, 'wptl_series' ) );
$series_anchor = '<a class="wptl-series-link" href="' . $series_url . '" rel="tag">Kadence Series</a>';
$season_url    = esc_url( add_query_arg( 'wptl_season', 'arrival', (string) get_term_link( $term_id, 'wptl_series' ) ) );
$season_anchor = '<a class="wptl-season-link" href="' . $season_url . '" rel="tag">Arrival season</a>';

ob_start();
get_template_part( 'template-parts/content/entry_title' );
$in_content = ob_get_clean();
$assert( 1 === substr_count( strtolower( $in_content ), '<h1' ), 'Kadence in-content title did not keep exactly one H1.' );
$assert( false !== strpos( $in_content, 'class="entry-title"' ), 'Kadence native entry-title wrapper was not retained.' );
$assert( false !== strpos( $in_content, 'Kadence Series' ), 'Kadence in-content title did not show Series context.' );
$assert( false !== strpos( $in_content, '04' ), 'Kadence in-content title did not show the padded sequence.' );
$assert( false !== strpos( $in_content, $series_anchor . ' · ' . $season_anchor . ' · 04' ), 'Kadence in-content title did not link Series and season separately before sequence 04.' );
$in_content_has_h1 = 1 === preg_match( '/<h1\b[^>]*>(.*?)<\/h1>/is', $in_content, $in_content_heading );
$assert( $in_content_has_h1, 'Kadence in-content title lost its native H1 content.' );
$assert( ! $in_content_has_h1 || false === strpos( $in_content_heading[1], 'wptl-series-link' ), 'Kadence in-content title placed the Series archive link inside its native H1.' );
$assert( ! $in_content_has_h1 || false === strpos( $in_content_heading[1], 'wptl-season-link' ), 'Kadence in-content title placed the season archive link inside its native H1.' );
$assert( false !== strpos( $in_content, 'Kadence subtitle sentinel' ), 'Kadence in-content title did not show the subtitle.' );

ob_start();
get_template_part( 'template-parts/title/title' );
$above_content = ob_get_clean();
$assert( 1 === substr_count( strtolower( $above_content ), '<h1' ), 'Kadence above-content title did not keep exactly one H1.' );
$assert( false !== strpos( $above_content, 'Kadence Series' ), 'Kadence above-content title lost Series context.' );
$assert( false !== strpos( $above_content, $series_anchor . ' · ' . $season_anchor . ' · 04' ), 'Kadence above-content title did not link Series and season separately before sequence 04.' );
$above_content_has_h1 = 1 === preg_match( '/<h1\b[^>]*>(.*?)<\/h1>/is', $above_content, $above_content_heading );
$assert( $above_content_has_h1, 'Kadence above-content title lost its native H1 content.' );
$assert( ! $above_content_has_h1 || false === strpos( $above_content_heading[1], 'wptl-series-link' ), 'Kadence above-content title placed the Series archive link inside its native H1.' );
$assert( ! $above_content_has_h1 || false === strpos( $above_content_heading[1], 'wptl-season-link' ), 'Kadence above-content title placed the season archive link inside its native H1.' );
$assert( false !== strpos( $above_content, 'Kadence subtitle sentinel' ), 'Kadence above-content title lost the subtitle.' );

/* A Series icon remains decorative and outside both verified Kadence H1 slots. */
$icon_upload = wp_upload_bits(
	'wptl-kadence-series-icon.png',
	null,
	base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true )
);
$icon_id = ! empty( $icon_upload['error'] )
	? new WP_Error( 'wptl_kadence_icon_upload_failed', (string) $icon_upload['error'] )
	: wp_insert_attachment(
		array(
			'post_title'     => 'Kadence decorative Series icon',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/png',
		),
		(string) $icon_upload['file'],
		$post_id,
		true
	);
$assert( ! is_wp_error( $icon_id ), 'Could not create the Kadence Series icon fixture.' );
$icon_id = is_wp_error( $icon_id ) ? 0 : (int) $icon_id;
$icon_downsize = static function ( $downsize, $attachment_id ) use ( $icon_id ) {
	return $icon_id === (int) $attachment_id
		? array( 'https://example.test/wptl-kadence-series-icon.png', 32, 32, true )
		: $downsize;
};
add_filter( 'image_downsize', $icon_downsize, 10, 2 );
update_term_meta( $term_id, 'wptl_icon_id', $icon_id );
update_term_meta( $term_id, 'wptl_title_icon', 'show' );

ob_start();
get_template_part( 'template-parts/content/entry_title' );
$in_content_with_icon = ob_get_clean();
$assert( false !== strpos( $in_content_with_icon, 'class="wptl-series-icon" aria-hidden="true"' ), 'Kadence in-content title lost the enabled decorative Series icon.' );
$in_content_icon_h1 = 1 === preg_match( '/<h1\b[^>]*>(.*?)<\/h1>/is', $in_content_with_icon, $in_content_icon_heading );
$assert( $in_content_icon_h1 && false === strpos( $in_content_icon_heading[1], '<img' ), 'Kadence in-content title placed the decorative Series icon inside its H1.' );

ob_start();
get_template_part( 'template-parts/title/title' );
$above_content_with_icon = ob_get_clean();
$assert( false !== strpos( $above_content_with_icon, 'class="wptl-series-icon" aria-hidden="true"' ), 'Kadence above-content title lost the enabled decorative Series icon.' );
$above_content_icon_h1 = 1 === preg_match( '/<h1\b[^>]*>(.*?)<\/h1>/is', $above_content_with_icon, $above_content_icon_heading );
$assert( $above_content_icon_h1 && false === strpos( $above_content_icon_heading[1], '<img' ), 'Kadence above-content title placed the decorative Series icon inside its H1.' );

remove_filter( 'image_downsize', $icon_downsize, 10 );
delete_term_meta( $term_id, 'wptl_icon_id' );
delete_term_meta( $term_id, 'wptl_title_icon' );
if ( 0 < $icon_id ) {
	wp_delete_attachment( $icon_id, true );
}

update_post_meta( $post_id, 'wptl_template_override', 'inline' );
ob_start();
get_template_part( 'template-parts/content/entry_title' );
$inline_content = ob_get_clean();
$inline_series_position   = strpos( $inline_content, 'Kadence Series' );
$inline_heading_position  = stripos( $inline_content, '<h1' );
$inline_subtitle_position = strpos( $inline_content, 'Kadence subtitle sentinel' );
$assert( 1 === substr_count( strtolower( $inline_content ), '<h1' ), 'Kadence Inline changed the native H1 count.' );
$assert( false !== $inline_series_position, 'Kadence Inline removed the Series eyebrow.' );
$assert( false !== strpos( $inline_content, 'wptl-theme-title-inline' ), 'Kadence Inline did not keep the subtitle in its inline sibling.' );
$assert(
	false !== $inline_heading_position
	&& false !== $inline_subtitle_position
	&& $inline_series_position < $inline_heading_position
	&& $inline_heading_position < $inline_subtitle_position,
	'Kadence Inline did not retain the Series, title, subtitle hierarchy.'
);
delete_post_meta( $post_id, 'wptl_template_override' );

$assert( 'Menu sentinel' === apply_filters( 'the_title', 'Menu sentinel', $post_id ), 'The Kadence adapter changed an unarmed title call.' );

ob_start();
get_template_part( 'template-parts/content/entry_loop_title' );
$loop_title = ob_get_clean();
$assert( false === strpos( $loop_title, 'Kadence subtitle sentinel' ), 'The Kadence adapter leaked into a loop title.' );

/* The opt-in archive adapter uses Kadence's exact title-to-meta slot. */
$archive_support = \WPTitleLayer\Reader\ThemeArchiveIntegration::support();
$assert( ! empty( $archive_support['supported'] ) && 'kadence' === $archive_support['adapter'], 'Official Kadence archive templates were not recognized.' );
$archive_query = new WP_Query(
	array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => 10,
		'tax_query'      => array(
			array(
				'taxonomy' => 'wptl_series',
				'field'    => 'term_id',
				'terms'    => array( $term_id ),
			),
		),
	)
);
$archive_query->is_tax = true;
$archive_query->queried_object = get_term( $term_id, 'wptl_series' );
$archive_query->queried_object_id = $term_id;
$GLOBALS['wp_query']     = $archive_query;
$GLOBALS['wp_the_query'] = $archive_query;
$archive_query->the_post();
ob_start();
get_template_part( 'template-parts/content/entry_loop_header' );
$archive_card = (string) ob_get_clean();
$assert( false !== strpos( $archive_card, 'class="entry-title"' ), 'Kadence Series archive card lost its native title.' );
$assert( false !== strpos( $archive_card, 'wptl-theme-archive-subtitle--kadence' ), 'Kadence Series archive card omitted the opt-in Subtitle.' );
$assert( false !== strpos( $archive_card, 'Kadence subtitle sentinel' ), 'Kadence Series archive card rendered the wrong Subtitle.' );
$assert( strpos( $archive_card, 'Kadence Primary Title' ) < strpos( $archive_card, 'Kadence subtitle sentinel' ), 'Kadence archive Subtitle did not follow the card title.' );

update_term_meta( $term_id, 'wptl_archive_subtitles', 'hide' );
$archive_query->rewind_posts();
$archive_query->the_post();
ob_start();
get_template_part( 'template-parts/content/entry_loop_header' );
$archive_card_term_hidden = (string) ob_get_clean();
$assert( false === strpos( $archive_card_term_hidden, 'Kadence subtitle sentinel' ), 'A per-Series hide override did not suppress the Kadence archive Subtitle.' );

$settings = get_option( 'wptl_settings', array() );
$settings['reader']['theme_archive_show_subtitles'] = false;
update_option( 'wptl_settings', $settings );
update_term_meta( $term_id, 'wptl_archive_subtitles', 'show' );
$archive_query->rewind_posts();
$archive_query->the_post();
ob_start();
get_template_part( 'template-parts/content/entry_loop_header' );
$archive_card_term_shown = (string) ob_get_clean();
$assert( false !== strpos( $archive_card_term_shown, 'Kadence subtitle sentinel' ), 'A per-Series show override did not enable the Kadence archive Subtitle.' );

delete_term_meta( $term_id, 'wptl_archive_subtitles' );
$archive_query->rewind_posts();
$archive_query->the_post();
ob_start();
get_template_part( 'template-parts/content/entry_loop_header' );
$archive_card_disabled = (string) ob_get_clean();
$assert( false === strpos( $archive_card_disabled, 'Kadence subtitle sentinel' ), 'Disabling theme archive-card Subtitles had no effect.' );

$settings['reader']['theme_archive_show_subtitles'] = true;
update_option( 'wptl_settings', $settings );
$category = wp_insert_term( 'Kadence control category', 'category' );
$category_id = is_wp_error( $category ) ? 0 : (int) $category['term_id'];
if ( $category_id ) {
	wp_set_object_terms( $post_id, array( $category_id ), 'category', false );
}
$category_query = new WP_Query( array( 'post_type' => 'post', 'cat' => $category_id, 'posts_per_page' => 10 ) );
$category_query->queried_object = get_term( $category_id, 'category' );
$category_query->queried_object_id = $category_id;
$GLOBALS['wp_query']     = $category_query;
$GLOBALS['wp_the_query'] = $category_query;
$category_query->the_post();
ob_start();
get_template_part( 'template-parts/content/entry_loop_header' );
$category_card = (string) ob_get_clean();
$assert( false === strpos( $category_card, 'Kadence subtitle sentinel' ), 'The Series adapter leaked Subtitle into a Category archive card.' );
$assert( false !== strpos( $category_card, 'Kadence Primary Title' ), 'The Category control card lost its native title.' );

if ( $failures ) {
	fwrite( STDERR, "Kadence integration failures:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo 'Kadence title integration checks passed: ' . (int) $checks . "\n";
