<?php
/**
 * Real-plugin smoke test for Rank Math replacement variables.
 *
 * Run through bin/test-rank-math.sh with an official Rank Math ZIP.
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

$same = static function ( $expected, $actual, string $message ) use ( $assert ): void {
	$assert( $expected === $actual, $message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . '.' );
};

$assert( defined( 'RANK_MATH_VERSION' ), 'The official Rank Math plugin did not load.' );
$assert( defined( 'WPTL_VERSION' ) && '1.0.0-rc.1' === WPTL_VERSION, 'WP Title Layer 1.0.0-rc.1 did not load.' );

if ( 0 === did_action( 'wp' ) ) {
	do_action( 'wp' );
}

$variables = function_exists( 'rank_math' ) && isset( rank_math()->variables )
	? rank_math()->variables->get_replacements()
	: array();
foreach ( array( 'wptl_subtitle', 'wptl_series', 'wptl_title_with_subtitle' ) as $variable_id ) {
	$assert( isset( $variables[ $variable_id ] ), 'Rank Math did not register ' . $variable_id . '.' );
	$assert( isset( $variables[ $variable_id ] ) && ! $variables[ $variable_id ]->is_cacheable(), $variable_id . ' was not marked context-dependent.' );
}

$term = wp_insert_term( 'Rank Math real Series', 'wptl_series' );
$term_id = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
$post_a = wp_insert_post(
	array(
		'post_type'   => 'post',
		'post_status' => 'publish',
		'post_title'  => 'Real title A',
	)
);
$post_b = wp_insert_post(
	array(
		'post_type'   => 'post',
		'post_status' => 'publish',
		'post_title'  => 'Real title B',
	)
);
$assert( $term_id > 0 && is_int( $post_a ) && $post_a > 0 && is_int( $post_b ) && $post_b > 0, 'Could not create Rank Math smoke fixtures.' );

update_post_meta( $post_a, 'wptl_subtitle', 'Real subtitle A' );
update_post_meta( $post_b, 'wptl_subtitle', 'Real subtitle B' );
wp_set_object_terms( $post_a, array( $term_id ), 'wptl_series' );
wp_set_object_terms( $post_b, array( $term_id ), 'wptl_series' );

$same( 'Real subtitle A', \RankMath\Helper::replace_vars( '%wptl_subtitle%', get_post( $post_a ) ), 'Rank Math resolved the wrong first subtitle.' );
$same( 'Rank Math real Series', \RankMath\Helper::replace_vars( '%wptl_series%', get_post( $post_a ) ), 'Rank Math resolved the wrong Series.' );
$same( 'Real title A: Real subtitle A', \RankMath\Helper::replace_vars( '%wptl_title_with_subtitle%', get_post( $post_a ) ), 'Rank Math did not use the English-locale title separator.' );

// Repeat the exact same variable strings for another article in this request.
// Without Rank Math's nocache contract these calls reuse article A.
$same( 'Real subtitle B', \RankMath\Helper::replace_vars( '%wptl_subtitle%', get_post( $post_b ) ), 'Rank Math leaked the first article subtitle through its replacement cache.' );
$same( 'Real title B: Real subtitle B', \RankMath\Helper::replace_vars( '%wptl_title_with_subtitle%', get_post( $post_b ) ), 'Rank Math leaked the first combined title through its replacement cache.' );

$old_query = $GLOBALS['wp_query'];
$old_post  = $GLOBALS['post'] ?? null;
$term_query = new WP_Query();
$term_query->queried_object    = get_term( $term_id, 'wptl_series' );
$term_query->queried_object_id = $term_id;
$term_query->is_tax             = true;
$GLOBALS['wp_query'] = $term_query;
$GLOBALS['post']     = get_post( $post_a );
$same( 'Rank Math real Series', \RankMath\Helper::replace_vars( '%wptl_title_with_subtitle%', get_term( $term_id, 'wptl_series' ) ), 'The Series archive replacement used an article title.' );
$same( '', \RankMath\Helper::replace_vars( '%wptl_subtitle%', get_term( $term_id, 'wptl_series' ) ), 'The Series archive replacement leaked an article subtitle.' );
$same( 'Rank Math real Series', \RankMath\Helper::replace_vars( '%wptl_title_with_subtitle%', get_post( $post_a ) ), 'An explicit loop post title overrode the Series archive term.' );
$same( '', \RankMath\Helper::replace_vars( '%wptl_subtitle%', get_post( $post_a ) ), 'An explicit loop post subtitle leaked onto the Series archive.' );
$GLOBALS['wp_query'] = $old_query;
$GLOBALS['post']     = $old_post;

/* The governance report reads Rank Math's real option and term-meta schema. */
$old_titles  = get_option( 'rank-math-options-titles', false );
$old_sitemap = get_option( 'rank-math-options-sitemap', false );
$old_modules = get_option( 'rank_math_modules', false );
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
update_option( 'rank-math-options-sitemap', array( 'tax_wptl_series_sitemap' => 'on', 'exclude_terms' => '' ) );
update_option( 'rank_math_modules', array( 'sitemap' ) );
update_term_meta( $term_id, 'rank_math_title', '%wptl_series% Official smoke' );
update_term_meta( $term_id, 'rank_math_canonical_url', 'https://example.org/rank-math-series/' );
update_term_meta( $term_id, 'rank_math_facebook_title', '%wptl_series% shared' );
update_post_meta( $post_a, 'rank_math_title', '%wptl_title_with_subtitle%' );

$governance_before = maybe_serialize(
	array(
		'options'   => array( get_option( 'rank-math-options-titles' ), get_option( 'rank-math-options-sitemap' ), get_option( 'rank_math_modules' ) ),
		'term_meta' => get_term_meta( $term_id ),
		'post_meta' => get_post_meta( $post_a ),
		'terms'     => wp_get_object_terms( $post_a, 'wptl_series', array( 'fields' => 'ids' ) ),
	)
);
$governance = new \WPTitleLayer\Admin\ChannelHealthReport();
$governance_overview = $governance->overview();
$governance_report   = $governance->report( $term_id );
$same( 'rank_math', $governance_overview['provider']['state'], 'The governance report did not detect the real Rank Math provider.' );
$assert( ! empty( $governance_overview['rank_math']['active'] ), 'Real Rank Math settings were not treated as authoritative.' );
$same( 'taxonomy_option', $governance_overview['rank_math']['taxonomy_title']['source'], 'The real taxonomy title option was reported as a provider default.' );
$same( 'title_with_subtitle', $governance_overview['rank_math']['article_title']['policy'], 'The real article title template did not recognize the WPTL variable.' );
$assert( ! empty( $governance_overview['rank_math']['sitemap']['module_active'] ) && ! empty( $governance_overview['rank_math']['sitemap']['enabled'] ), 'The real Rank Math sitemap settings were not interpreted.' );
$same( 'term_override', $governance_report['channels']['search_title']['source'], 'The real Rank Math term title did not outrank the taxonomy template.' );
$same( 'series_term', $governance_report['channels']['search_title']['policy'], 'The real Series title variable was not recognized as a Series landing-page policy.' );
$same( 'explicit', $governance_report['channels']['canonical']['state'], 'The real Rank Math canonical override was not recognized.' );
$same( 'https://example.org/rank-math-series/', $governance_report['channels']['canonical']['value'], 'The real Rank Math canonical changed during inspection.' );
$same( 'term_override', $governance_report['channels']['facebook']['source'], 'The real Rank Math Facebook title was not recognized.' );
$same( 'inherits_search_title', $governance_report['channels']['twitter']['source'], 'An absent Rank Math Twitter title did not inherit the SEO title.' );
$same( 1, $governance_report['article_overrides']['seo_titles_with_wptl'], 'The real Rank Math article-title variable was not counted.' );
$governance_after = maybe_serialize(
	array(
		'options'   => array( get_option( 'rank-math-options-titles' ), get_option( 'rank-math-options-sitemap' ), get_option( 'rank_math_modules' ) ),
		'term_meta' => get_term_meta( $term_id ),
		'post_meta' => get_post_meta( $post_a ),
		'terms'     => wp_get_object_terms( $post_a, 'wptl_series', array( 'fields' => 'ids' ) ),
	)
);
$same( $governance_before, $governance_after, 'Reading real Rank Math channel settings changed provider-owned data.' );

false === $old_titles ? delete_option( 'rank-math-options-titles' ) : update_option( 'rank-math-options-titles', $old_titles );
false === $old_sitemap ? delete_option( 'rank-math-options-sitemap' ) : update_option( 'rank-math-options-sitemap', $old_sitemap );
false === $old_modules ? delete_option( 'rank_math_modules' ) : update_option( 'rank_math_modules', $old_modules );
delete_post_meta( $post_a, 'rank_math_title' );
delete_term_meta( $term_id, 'rank_math_title' );
delete_term_meta( $term_id, 'rank_math_canonical_url' );
delete_term_meta( $term_id, 'rank_math_facebook_title' );

$assert(
	! metadata_exists( 'post', $post_a, 'rank_math_title' )
	&& ! metadata_exists( 'post', $post_a, 'rank_math_facebook_title' )
	&& ! metadata_exists( 'post', $post_a, 'rank_math_twitter_title' ),
	'The integration wrote Rank Math-owned title metadata.'
);

wp_delete_post( $post_a, true );
wp_delete_post( $post_b, true );
wp_delete_term( $term_id, 'wptl_series' );

if ( $failures ) {
	fwrite( STDERR, "Rank Math integration failures:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo 'Rank Math ' . ( defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : 'unknown' ) . ' integration checks passed: ' . (int) $checks . "\n";
