const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

const read = ( file ) => fs.readFileSync( path.join( __dirname, '..', file ), 'utf8' );
const schema = read( 'includes/Core/Schema.php' );
const meta = read( 'includes/Core/Meta.php' );
const book = read( 'includes/Core/BookStructure.php' );
const sequence = read( 'includes/Core/Sequence.php' );
const runtime = read( 'includes/Core/SequenceRuntime.php' );
const series = read( 'includes/Core/Series.php' );
const manager = read( 'includes/Admin/SequenceManager.php' );
const health = read( 'includes/Admin/SeriesHealthReport.php' );
const editorAssets = read( 'includes/Presentation/EditorAssets.php' );
const editor = read( 'assets/editor.js' );
const editorCss = read( 'assets/editor.css' );
const classic = read( 'includes/Admin/ClassicEditor.php' );
const classicJs = read( 'assets/classic-editor.js' );
const archive = read( 'includes/Reader/Archive.php' );
const archiveTemplate = read( 'templates/series-archive.php' );
const navigation = read( 'includes/Reader/Navigation.php' );

test( 'scope and role are independent plugin-owned fields with a private per-Series activation marker', () => {
	assert.match( schema, /META_SERIES_SCOPE\s*=\s*'wptl_series_scope'/ );
	assert.match( schema, /TERM_META_BOOK_STRUCTURE_VERSION\s*=\s*'wptl_book_structure_version'/ );
	assert.match( schema, /SCOPE_SERIES\s*=\s*'series'/ );
	assert.match( schema, /SCOPE_SEASON\s*=\s*'season'/ );
	assert.match( meta, /register_post_field\( Schema::META_SERIES_SCOPE/ );
	assert.match( meta, /register_private_term_field\( \$taxonomy, Schema::TERM_META_BOOK_STRUCTURE_VERSION/ );
	assert.match( meta, /sanitize_content_scope/ );
} );

test( 'legacy activation is previewed per Series and refuses ambiguous scope', () => {
	assert.match( book, /function preview/ );
	assert.match( book, /fingerprint/ );
	assert.match( book, /hash_equals/ );
	assert.match( book, /scope_unresolved/ );
	assert.match( book, /wptl_book_unresolved/ );
	assert.ok(
		book.indexOf( 'update_term_meta( $term_id, Schema::TERM_META_BOOK_STRUCTURE_VERSION' )
			> book.indexOf( 'foreach ( $changes as $change )' ),
		'the activation marker must be committed after all per-track ranks are verified'
	);
	assert.match( manager, /Read-only structure compatibility check/ );
	assert.match( manager, /Enable advanced structure for this Series/ );
} );

test( 'book tracks cover flat and seasoned Series without ordering an unordered body', () => {
	assert.match( book, /Series introductions/ );
	assert.match( book, /Main articles/ );
	assert.match( book, /Series epilogues/ );
	assert.match( book, /Series appendices/ );
	assert.match( book, /Schema::ROLE_ARTICLE === \$role && Series::is_ordered/ );
	assert.match( sequence, /function move_track/ );
	assert.match( sequence, /function track_page/ );
	assert.match( sequence, /'posts_per_page'\s*=>\s*\$per_page/ );
	assert.doesNotMatch( sequence.match( /public static function track_page[\s\S]*?public static function scope_page_clauses/ )[ 0 ], /posts_per_page'\s*=>\s*-1/ );
	assert.match( sequence, /track_revision/ );
	assert.match( runtime, /BookStructure::is_enabled/ );
	assert.match( manager, /Structure track/ );
	assert.match( manager, /Main articles in an unordered Series keep the Series archive sort setting/ );
} );

test( 'new empty ordered Series start canonical while nonempty Series cannot bypass migration', () => {
	assert.match( sequence, /function activate_empty/ );
	assert.match( sequence, /wptl_sequence_not_empty/ );
	assert.match( sequence, /update_term_meta\( \(int\) \$term->term_id, Schema::TERM_META_SEQUENCE_SCHEMA_VERSION/ );
	assert.match( series, /Sequence::activate_empty\( \$term \)/ );
	assert.match( series, /current_filter\(\).*created_/s );
} );

test( 'archives, navigation, numbering, and health consume the same scope-role model', () => {
	assert.match( archive, /BookStructure::tracks/ );
	assert.match( archive, /\['track'\]/ );
	assert.match( archive, /'role'\s*=>\s*\(string\) \$definitions\[ \$track_key \]\['role'\]/ );
	assert.match( archive, /'ordered'\s*=>\s*Series::is_ordered\( \$term \) \|\| Schema::ROLE_ARTICLE !==/ );
	assert.match( archiveTemplate, /! empty\( \$wptl_group\['ordered'\] \)/ );
	assert.match( series, /order_book_structure_clauses/ );
	assert.match( series, /wptl_book_scope_pm/ );
	assert.match( navigation, /BookStructure::context/ );
	assert.match( sequence, /BookStructure::ordered_post_ids/ );
	assert.match( health, /BookStructure::is_enabled/ );
	assert.match( health, /unresolved_scope/ );
	assert.match( health, /invalid_role/ );
	assert.match( health, /duplicate_expression[\s\S]*book_structure/ );
} );

test( 'both editors expose explicit scope but keep private ranks in Sequence and Structure', () => {
	assert.match( editorAssets, /bookStructureSeriesIds/ );
	assert.match( editorAssets, /'scopes'/ );
	assert.match( editor, /Book structure scope/ );
	assert.match( editor, /structureTrackKey/ );
	assert.match( editor, /termId !== editor\.selectedTermId[\s\S]*schema\.seasonKey[\s\S]*schema\.seriesScope/ );
	assert.match( editor, /Move in Sequence & Structure/ );
	assert.match( editor, /! mainArticle && bookStructureEnabled && selectedTrack[\s\S]*manager_view=structure[\s\S]*manager_view=sequence/ );
	assert.doesNotMatch( editor, /setMeta\( schema\.sequenceRank/ );
	assert.match( classic, /Book structure scope/ );
	assert.match( classic, /Managed structure track/ );
	assert.match( classic, /'manager_view' => \$current_main \? 'sequence' : 'structure'/ );
	assert.match( classicJs, /book && ! mainArticle[\s\S]*manager_view=structure[\s\S]*manager_view=sequence/ );
	assert.doesNotMatch( classic, /name=[^\n]*wptl_sequence_rank/ );
} );

test( 'Media attachments and native multi-term quick edit remain outside the WPTL editor contract', () => {
	assert.match( editorAssets, /'attachment' !== \$post_type->name/ );
	assert.match( editorAssets, /if \( ! empty\( \$editor_data\['nativeSeriesPanel'\] \) \)[\s\S]*wp_add_inline_style/ );
	assert.doesNotMatch( editorCss, /taxonomy-panel-wptl_series/ );
	assert.match( classic, /'attachment' !== \$post_type->name/ );
	assert.match( series, /show_in_quick_edit'\s*=>\s*false/ );
	assert.match( series, /\$object->show_in_quick_edit\s*=\s*false/ );
} );
