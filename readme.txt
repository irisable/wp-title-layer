=== WP Title Layer ===
Contributors: iris
Tags: title, subtitle, series, editorial, gutenberg
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0-rc.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Structured subtitles and excerpts, one Series per post, and explicit title-layer templates without an ACF dependency.

== Description ==

WP Title Layer keeps the native WordPress post title canonical while storing
the surrounding editorial structure as separate data:

* explanatory subtitles;
* one native Series relationship per post;
* ordered or unordered Series;
* optional seasons and public sequence labels;
* an opt-in Sequence view for inserting and moving ordered articles without manual renumbering, independent of optional book-like tracks;
* explicit Series/Season scope with independent introduction, main-article, epilogue, and appendix tracks;
* optional Planning, Ongoing, Paused, or Completed Series lifecycle labels;
* a read-only Series content-health report with article-level edit links;
* named display templates and controlled post overrides;
* verified title-slot replacement for block themes and Kadence;
* manual title placement by dynamic block, shortcodes, or theme API;
* legacy guarded article-body placement;
* optional Series archives and ordered reading navigation with explicit full-Series or current-season boundaries;
* optional parent Category branches with non-blocking consistency checks;
* per-Series archive layout, Subtitle, Excerpt, and structured featured-image policies;
* optional decorative Series icons with site-wide and per-Series visibility controls;
* optional Rank Math variables for separate SEO and sharing titles;
* a read-only Series/Category landing-page and title-channel governance report;
* a native media-library Series cover selector and fail-safe Classic Editor controls;
* an accessible tabbed settings interface with a no-JavaScript fallback;
* opt-in Series-archive card Subtitles for verified official Kadence 1.5.x templates;
* non-destructive Secondary Title migration;
* carefully verified migration of a genuine ACF `subtitle` field.

ACF is not required. WP Title Layer owns its taxonomy, metadata, REST schema,
editor controls, and rendering layer, and works when ACF is absent.

WP Title Layer never sends a complete layer through a general `the_title`
filter. It can instead replace a verified singular title slot in a block theme
or Kadence, retaining the theme heading level and leaving the body untouched.
Other classic themes use manual block, shortcode, or theme-API placement.

The older automatic body placement remains an explicit fallback. It requires
eligible public post types and confirmation that the visible theme title was
disabled separately. It renders only H2 or DIV and does not remove the theme
title itself.

The Standard template displays the Series short label (or full name) and any
season/sequence context above the primary title. Public Series and season
labels link to their corresponding archives while the sequence remains plain
text. Articles outside a Series omit that line. The block editor uses a
server-rendered preview of saved data.

Template selection follows this order: post override, Series default, category
rule, then global default. A post can also disable its Title Layer.

== Installation ==

1. Upload the `wp-title-layer` directory to `/wp-content/plugins/` or install the ZIP.
2. Activate WP Title Layer.
3. If the site used Secondary Title or ACF `subtitle`, open Tools > Title Layer Migration and scan without writing.
4. Review the conflicts, then start the copy. The migration screen continues through batches automatically and can safely resume the same run after interruption.
5. Verify several posts and deactivate Secondary Title.
6. Open WP Title Layer > Control Center, then choose the verified theme-title takeover or a manual placement under Settings > WP Title Layer.
7. Test one ordinary article and one Series article, including mobile layout and exactly one intended H1.
8. Optionally enable the classic-theme Series archive or after-content ordered-Series navigation.
9. Review one Series at a time under WP Title Layer > Landing & Channels before changing canonical, robots, sitemap, or duplicate Category entry points.
10. For an existing Series, open WP Title Layer > Sequence & Structure. Every ordered Series has a Sequence view; initialize it once if needed. Use Structure tracks only when book-like roles are needed. Upgrading alone never converts old Series.

While Secondary Title is active, WP Title Layer remains in migration-only mode:
its editor and front-end output stay disabled to avoid duplicate ownership.
Migration does not delete source data or overwrite an existing canonical
subtitle, even when the existing value is empty.

== Frequently Asked Questions ==

= Does this require ACF? =

No. ACF is neither a runtime dependency nor the owner of WP Title Layer data.
It is only one possible migration source.

On an existing site, run the migration report before removing ACF. Its five
audited Series rows report non-empty field-post values and unique post IDs, not
confirmed use: values such as 0, false, or configured defaults may have been
saved when posts were edited, while exact empty strings are excluded. WP Title
Layer never imports those five fields.
Migrate any verified ACF subtitle, use site knowledge to review the old Series
footprint, and separately check whether the theme or another plugin uses other
ACF fields.

= Exactly what is copied automatically? =

Only Secondary Title's `_secondary_title` and an ACF field named `subtitle`
whose real field definition and per-post `_subtitle` field-key reference can be
verified. A raw `subtitle` meta key alone is not trusted. Disagreements and
unverified values are reported as conflicts rather than guessed.

= What are the five previous ACF Series fields in the migration screen? =

`series_key`, `series_title`, `series_order`, `series_has_order`, and
`series_stage` are non-empty-value and verified-reference audit rows. The same
post can be counted in more than one field, ACF-saved defaults are included,
and exact empty strings are excluded. Version 1.0.0-rc.1 does not map them automatically to Series relationships,
seasons, or sequence fields.

= What happens if an automatic migration request is interrupted? =

Completed batches are journaled before the browser requests the next batch.
Reload the migration screen and continue the same run; already completed work
is not copied again. Conflicts can be downloaded as a formula-safe CSV only
after the run reaches a stable terminal state.

The scan also freezes a plaintext-free candidate manifest. A source, target,
ACF trust decision, content type, status, or eligibility that changes after
review is left unchanged and reported as a conflict or left for a new scan.

= How does the Title Layer replace my visible title? =

Open Settings > WP Title Layer and choose Replace the theme post title when the
screen reports a verified integration. Block themes use their singular Post
Title block; official Kadence 1.5.x title templates use their exact singular
title hooks. The original heading level is retained and the body is not used
as the title slot. A Kadence child theme that overrides the relevant title
templates falls back to Manual mode.

Unknown classic themes stay in Manual mode. Insert the dynamic Title Layer
block, use `[wp_title_layer]`, or integrate the theme API in the desired title
template. The plugin does not hide an unknown theme title with CSS or globally
rewrite `the_title`.

Legacy body insertion is still available, but it does not remove the theme
title. Select public post types, choose H2 or DIV, and confirm that the visible
theme title was disabled separately.

= Is the Series name displayed on the public article? =

Yes, by default in the Standard template. The Series short label, or the full
name when no short label exists, links to its public Series archive and appears
with any season/sequence context above the primary title. A defined season has
its own link to that season's filtered list; the sequence remains plain text.
Non-Series articles show no Series line. Minimal intentionally omits that line;
Inline retains it by default above a compact same-line title and subtitle.

A Series may choose a small image separate from its archive cover. Display is
off by default and can be inherited, shown, or hidden per Series. The image is
decorative, sits inside the Series link but outside the article H1, and never
enters WordPress, SEO, or social-title text.

= Can the four display templates be edited? =

Their safe arrangement recipes can be customized under Settings > WP Title
Layer. Series context can appear above the title or be hidden. The subtitle can
appear below or beside the title, or be hidden. Same-line separators can use
：, ——, |, or up to eight characters of plain custom text. No HTML, CSS, or PHP
is accepted, and every template has a restore-default option. A same-line
subtitle remains outside the primary heading in the page markup.

The defaults serve different publishing situations: Standard is neutral and
theme-led; Editorial feature adds a restrained accent for long-form and Series
features; Inline keeps available Series context above a compact title/subtitle
line; Title only is the compatibility fallback.

= Can one post belong to more than one Series? =

No. Version 1.0.0-rc.1 supports at most one Series per post because season, scope,
role, and sequence metadata would otherwise be ambiguous.

= Can a Series belong to a Category? =

Yes. A Series may optionally name one parent Category as an editorial map. An
article is consistent when it belongs to that Category or one of its child
Categories. A mismatch appears as a warning in the block editor and Series
Health, but WP Title Layer never blocks saving or automatically changes the
article's Category relationships.

= What does the Series status change? =

It is descriptive only. A Series may be Planning, Ongoing, Paused, Completed,
or Not set. Existing Series remain Not set until an editor chooses a status.
Changing it does not publish, hide, lock, or reorder any article. An explicit
status appears in the Series list and the optional structured Series archive.

= How do I insert an article into an ordered Series? =

Open WP Title Layer > Sequence & Structure, then use the Sequence view. Existing ordered Series first get a read-only
preview of their legacy positions; initialization is explicit, resumable, and
commits only after every rank validates. Legacy position and label metadata are
preserved. Merely upgrading or activating 1.0.0-rc.1 does not rewrite an existing Series.

Once initialized, drag an article, use its keyboard move buttons, or place it
before or after a searched article. Later articles do not need manual
renumbering: public ordinary-article numbers are calculated from the canonical
order. New articles append safely and can then be moved. The Manager rejects a
stale page instead of overwriting a newer order and provides guarded undo for
the latest move.

Each Season also receives a stable numeric public ID. Renaming or reordering a
Season does not change links such as `?wptl_season=2`; old key-based links
remain compatible.

= How do prefaces, afterwords, and appendices work? =

Version 1.0.0-rc.1 separates scope from role. In a seasoned Series, a main article
must belong to one Season; an introduction, epilogue, or appendix may be
explicitly Series-wide or belong to one Season. Flat Series entries are always
Series-wide. Each combination has an independent track in the Structure tracks view,
so several prefaces or afterwords can be moved without consuming automatic
main-article numbers. Optional public labels such as `0-1`, `Preface II`, and
`Appendix A` remain display text rather than internal ranks.

Existing Series stay in compatibility mode until one Series passes its
read-only Structure compatibility preview. The Sequence view remains available
to every ordered Series before and after advanced-structure activation.
Unresolved legacy scope is never guessed.
New empty Series start with the canonical model immediately. Unordered Series
keep their configured main-body archive sort and do not gain invented main
previous/next navigation, while non-main structure tracks can still be moved.

= How should an external REST client publish Series fields? =

Resolve an existing Series from `/wp-json/wp/v2/wptl_series?slug=...`, then send
its term ID through the article's `wptl_series` REST field. Send the canonical
Subtitle as `meta.wptl_subtitle`; `_secondary_title` is compatibility input, not
a new-write target. WP Title Layer accepts at most one Series and an article
publisher must not create or redefine Series terms.

A seasoned article uses the internal `wptl_season_key`. The stable numeric
Season `public_id` is for public links: read `meta.wptl_seasons` from the Series
response and map that ID to its internal key. Roles are empty or `article`,
`intro`, `epilogue`, or `appendix`; non-main seasoned entries explicitly use
scope `series` or `season`. `wptl_sequence_label` is optional display text, not
an order value.

Omitted fields remain unchanged. When clearing or changing Series, also clear
season, role, scope, and label values that no longer apply. Managed ordered
Series append new REST-saved articles automatically, so clients must not send a
private rank. If an old uninitialized ordered Series returns
`wptl_missing_sequence_position`, initialize it in Sequence Manager and retry;
only an explicit legacy workflow should send a unique non-negative position.
Private rank, schema, revision, Season high-water, and book-structure fields are
never external write targets. Season definitions are readable for mapping but
REST writes are rejected.

= Can Secondary Title remain active? =

It can remain active while you scan and migrate. During that time WP Title
Layer operates in migration-only mode. Deactivate Secondary Title only after
copying and checking the data.

= What Reader features are available? =

The active theme's archive layout is the default and normally follows the same
visual system as Category archives. Ordered Series always use their reading
sequence; unordered Series honor the selected oldest/newest/title archive
sort. An administrator may instead enable the structured Series archive on
a classic theme that has no dedicated Series taxonomy template. That layout
supports seasons, sequence labels, cover/description, and an independent
article-subtitle toggle plus default-off WordPress Excerpts and featured
images. Subtitle, Excerpt, and featured-image policies can each be inherited,
shown, or hidden per Series. Its no-cover header uses the available desktop width;
a cover enables a bounded two-column header. Block themes and dedicated theme
templates keep control.

On verified official Kadence 1.5.x templates, a separate opt-in can add the
Subtitle after each Series archive card title and before its metadata. It does
not affect Category, search, home, structured, or unsupported-theme archives.

Automatic previous/next, start, and progress output after the article is also
off by default and works only for ordered Series. The manual
`[wptl_series_navigation]` shortcode is enabled by default. All Reader paths
include only public, published, non-password-protected articles. Unordered
Series do not get an invented sequence.

For an ordered Series with seasons, the Series edit screen chooses whether all
navigation controls use the Entire Series or only the Current Season. Existing
Series default to Entire Series. In Current Season mode, previous, next, start,
position, and total stop together at the season boundary. An article with no
valid season gets no guessed navigation and can be found through Series Health.

Previous/next navigation currently sorts the complete eligible Series. Archives
retain WordPress pagination and use matching SQL ordering. Sites with
exceptionally large Series should profile navigation before enabling it broadly.

Series and season links, plus previous/next navigation links, inherit the theme
color without an underline and add the underline on hover or keyboard focus.
The new-Series form starts with four season rows and can add more immediately.
Its cover uses the WordPress media library while still storing the attachment
ID in `wptl_cover_id`; the numeric field remains as a no-script fallback. The
Posts list also offers a Subtitle column through Screen Options.

Global Reader choices are defaults. Each Series may inherit or override the
theme/structured archive layout, article Subtitle visibility, Excerpt
visibility, and compact featured images in the structured layout. Missing
values inherit, so upgrading does not change existing archives. A structured
archive also links back to its optional parent Category. Its pagination uses
square controls, a solid current page, arrow previous/next controls, an unboxed
ellipsis, and no underlined links while preserving keyboard focus and full
screen-reader labels.

= Does WP Title Layer support the Classic Editor? =

Yes. When Gutenberg is actually disabled for a supported post type, one
nonce- and capability-protected meta box edits Subtitle, an existing single
Series, season/order details, kicker, and template override. It does not create
Series terms and it does not appear beside the Gutenberg sidebar. Custom
reused taxonomies retain their native relationship UI unless an integrator
explicitly gives that control to WP Title Layer.

= Which languages are included? =

English is the source and fallback language. A complete Simplified Chinese
(`zh_CN`) translation is bundled for the PHP administration screens, Classic
Editor, block-editor sidebar, reports, and front-end labels. The administration
interface follows the current user's WordPress language; public output follows
the locale of the current front-end request.

Built-in colon, em-dash, and vertical-bar separator choices use English
punctuation in English and Chinese punctuation in Simplified Chinese without
rewriting saved settings. Custom separators are left unchanged. The included
POT catalog supports normal WordPress translation workflows for other
languages. Labels supplied by WordPress, the active theme, or another plugin
remain the responsibility of that component's language pack.

= Does uninstalling remove title data? =

No. Content data is preserved by default. Any future destructive cleanup must
be a separate, explicit action.

= How can I find missing seasons or duplicate positions? =

Open WP Title Layer > Series Health, or choose Check Series health in the
Control Center. The report inspects one Series at a time and separates
published, scheduled, private, draft, pending, trashed, and password-protected
articles. It flags missing or invalid positions, missing or undefined seasons,
and duplicate positions, with native edit links where permitted.

The report is read-only. It never publishes, hides, assigns, reorders, or
repairs content. Whole-Series totals are aggregated in the database and member
results are shown 50 rows at a time. The Series overview can be searched by
name or slug, and filtered pagination preserves the search. Unordered Series
are never reported as missing a reading position.

= Can SEO and sharing titles include the subtitle? =

Yes, as an explicit Rank Math choice rather than an automatic rewrite. Version
1.0.0-rc.1 registers `%wptl_subtitle%`, `%wptl_series%`, and
`%wptl_title_with_subtitle%`. The combined variable adds the locale's title
separator and subtitle only when one exists. Use it independently in Rank Math's SEO, Facebook, or
Twitter title fields. WP Title Layer does not write Rank Math metadata or emit
its own Open Graph tags. Multiple Series memberships remain outside this
version's scope.

= How do I check duplicate Series and Category landing pages? =

Open WP Title Layer > Landing & Channels. Select a Series to compare only its
public, published, non-password-protected articles with Category archives. An
exact duplicate means both complete public article sets are identical; a
partial overlap only reports the shared counts. The report never removes a
Category or selects a canonical automatically.

When Rank Math is active, the page also reads its current Series taxonomy
title, description, robots and sitemap settings, then the selected term's
search title, canonical, robots, Facebook title, Twitter title and sitemap
exclusion. It labels defaults, explicit settings, term overrides and social
inheritance separately. Inactive Rank Math options are ignored; another or
multiple SEO providers are identified but must be checked in their own UI and
in rendered page source.

== Development ==

Run `npm run check` for 95 JavaScript, localization, editor, Sequence & Structure,
migration, channel-governance, and release-version contract checks. Run `npm run test:wp` for
60-file PHP syntax, 827 integration checks, 111 Sequence Manager
checks, and 61 book-structure checks against both ends of the supported matrix:

* WordPress 6.5 with PHP 7.4;
* WordPress 7.1 with PHP 8.3.

Official Kadence 1.5.2 templates pass 40 adapter checks. Official Rank Math
1.0.276 passes 32 real replacement-engine and channel-report checks, including
two-post cache isolation and its real taxonomy/sitemap settings.

== Changelog ==

= 1.0.0-rc.1 =

* Declared the existing title, subtitle, Series, Season, Sequence, scope/role, template, migration, and REST contracts as the 1.0 candidate for external validation without adding a new data model.
* Documented the bounded external REST contract for existing Series lookup, Season identity mapping, managed-order append, explicit cleanup, and private-field ownership.
* Kept Sequence management available for every ordered Series independently from optional book-like structure tracks, including existing Series already initialized before 0.11.0.
* Replaced the long Series selectors in Sequence & Structure and Series Health with searchable workflows; a selected Series now opens immediately while the no-JavaScript native fallback remains usable.
* Added public-repository licensing, security and contribution guidance, CI, deterministic fixed-ref packaging, and strict cross-file/package version gates.
* Removed private planning exports, local test addresses, and the reviewed third-party plugin ZIP from the public source history while retaining auditable attribution and checksums.

= 0.11.0 =

* Added explicit Series/Season scope independent of introduction, main-article, epilogue, and appendix roles, including multiple entries per structural track.
* Added a unified Sequence & Structure screen: every ordered Series keeps independent Sequence management, while optional advanced structure adds per-Series compatibility previews and role/scope tracks.
* Added a searchable Series chooser that opens on selection while retaining a native-select-and-button fallback, plus read-only Series Health overview search with filter-preserving pagination.
* Unified complete-Series and season archive grouping, ordered navigation, public numbering, and Series Health around the same scope/role model; unordered main bodies retain their configured sort.
* New empty Series now begin canonical, while every existing Series remains unchanged until explicitly reviewed and enabled; activation journals private non-main ranks and commits its marker last.
* Removed Title Layer controls from Media attachments and removed the misleading native multi-term Series widget from quick/bulk edit paths.
* Updated the English catalog, complete Simplified Chinese translation, Control Center, editor guidance, documentation, and dual-version release-package tests.

= 0.10.0 =

* Added the opt-in Series Sequence Manager with non-destructive previews, resumable initialization, stale-preview protection, and value-checked rollback.
* Added drag, keyboard, exact-title insertion, bounded paging, automatic article ordinals, safe append, revision conflict protection, rebalancing, and guarded undo without exposing internal ranks.
* Routed archives, title layers, navigation, progress, and Series Health through the same managed order while preserving every uninitialized Series on its 0.9.x behavior.
* Added stable numeric Season public IDs with non-reuse, rename/reorder stability, compatible legacy-key resolution, and canonical redirects.
* Expanded the block and Classic editors, Control Center, health reports, English source catalog, and Simplified Chinese translation for the managed workflow.

= 0.9.1 =

* Restyled the structured Series archive paginator with square controls, a solid current page, arrow previous/next controls, an unboxed ellipsis, keyboard focus, and no default underlines.
* Added default-off WordPress Excerpts to the structured archive with independent site-wide and per-Series inherit/show/hide policies; theme, Category, and Search archives remain unchanged.
* Added a separate media-library Series icon with site-wide and per-Series visibility controls; it remains decorative, outside the H1, and absent from SEO/social title variables.
* Replaced the generic Gutenberg panel/block A icon with the WP Title Layer mark and clarified unspecified versus explicitly selected main-article role labels without rewriting stored role metadata.

= 0.9.0 =

* Added an optional parent Category branch to each Series, with descendant-aware editor and Series Health warnings that never mutate article Categories.
* Added per-Series inheritance or overrides for theme/structured archive layout, archive-card Subtitles, and compact structured featured images.
* Added a parent-Category link and optional responsive featured images to the structured Series archive while preserving the previous output by default.
* Progressively enhanced the single native settings form into accessible keyboard- and hash-aware tabs, while keeping every section visible and saveable without JavaScript.

= 0.8.2 =

* Separated page pagination labels from Series article navigation so Simplified Chinese correctly uses 上一页/下一页 while Reader links retain 上一篇/下一篇.
* Increased verified Kadence Series-archive card Subtitle text to a readable responsive 17–19px range.
* Replaced the generic A-shaped Dashicon with a color-scheme-aware custom title-layer SVG menu mark.
* Changed the plugin author display name to Irisable and added the project website as Author URI.
* Reorganized the shared settings page into responsive, numbered sections for placement, presentation, Series reading, and search/sharing without changing its native save path.

= 0.8.1 =

* Added a complete Simplified Chinese translation for PHP screens, reports, Classic Editor, and the Gutenberg sidebar.
* Added the WordPress script-translation path and a POT catalog so further language packs can use the standard translation workflow.
* Made built-in colon, em-dash, and vertical-bar separators locale-aware without rewriting saved template recipes; custom separators remain unchanged.
* Replaced generated English Series enum and role labels with explicitly translatable labels.

= 0.8.0 =

* Replaced the raw Series cover attachment field with a progressive WordPress media-library selector while preserving `wptl_cover_id` and a no-script numeric fallback.
* Added a fail-safe Classic Editor meta box for canonical Title Layer fields; it appears only when Gutenberg is disabled and never creates Series terms or duplicates the block-editor panel.
* Added an independent, default-off Kadence 1.5.x Series-archive card Subtitle adapter in the verified title-to-meta hook, leaving Category, search, home, structured, and unsupported-theme archives unchanged.
* Completed the five-stage roadmap without adding global archive `the_title` HTML injection or guessing title slots in unknown classic themes.

= 0.7.0 =

* Added a capability-gated, read-only Landing & Channels report under the WP Title Layer control center.
* Classified Series/Category entry points from exact public article sets, keeping partial overlaps factual and excluding drafts, private, scheduled, trashed, and password-protected content.
* Added Rank Math inspection for taxonomy and term search titles, descriptions, canonical, robots, sitemap, Facebook/Twitter inheritance, and article-level variable use without writing provider data.
* Distinguished Rank Math defaults, inactive stored options, other SEO providers, and multiple-provider ownership; all remediation remains in WordPress or the provider UI.

= 0.6.0 =

* Added a per-Series Entire Series / Current Season boundary for ordered, seasoned reading navigation.
* Made previous, next, start, position, and total use one identical public collection, with explicit season links and labels in the navigation card.
* Preserved Entire Series as the upgrade default; flat Series and invalid settings also retain the compatible behavior.
* Failed closed when Current Season is selected but an article has no defined season, while continuing to exclude drafts, private, scheduled, and password-protected entries.

= 0.5.0 =

* Added a read-only Series Content Health page for missing or invalid positions, missing or undefined seasons, and duplicate positions.
* Separated published, scheduled, private, draft, pending, trashed, and password-protected article states, with native edit links for authorized users.
* Kept checks mode-aware: unordered Series never receive false missing-position warnings, while seasoned Series still validate season membership.
* Added bounded Series and member pagination; whole-Series counts are aggregated without loading every article into PHP or changing content.

= 0.4.0 =

* Added explicit Planning, Ongoing, Paused, and Completed lifecycle states for Series without inferring a state for existing content.
* Added lifecycle controls to the Series editor, a Status column to the Series list, protected REST term metadata, and a structured-archive status label.
* Kept lifecycle status descriptive only: changing it never publishes, hides, locks, or reorders articles.
* Added a five-stage roadmap covering Series health checks, season-scoped navigation, landing/SEO governance, and later admin/theme polish.

= 0.3.2 =

* Linked a seasoned article's season label to a byte-exact filtered season list while keeping the public sequence label outside links.
* Removed default underlines from Series Reader navigation and restored them on hover or keyboard focus.
* Improved the structured archive's desktop width and title scale, and made unordered archive sorting win over late theme query changes while ordered Series keep their canonical reading sequence.
* Added four initial season rows plus an Add another season control to the new-Series screen.
* Added an optional Subtitle column to WordPress post lists.

= 0.3.1 =

* Made Inline retain an available Series eyebrow by default while keeping the primary title and subtitle on one visual line. Non-Series posts still omit the empty eyebrow, and saved template overrides remain authoritative.

= 0.3.0 =

* Made displayed Series links theme-led by default, with the Kadence/global highlight color and underline appearing only on hover or keyboard focus.
* Gave Standard, Editorial feature, Inline, and Title only explicit publishing scenarios; Editorial now has a restrained feature accent.
* Reduced the block-editor Subtitle control to two rows without changing its multiline data model.
* Made theme-owned archive rendering the clearly recommended layout, fixed the structured archive's desktop container collision, and added a structured-archive subtitle toggle.
* Added optional Rank Math variables for subtitle, full Series name, and a conditional title-plus-subtitle while leaving SEO and social metadata under Rank Math ownership.

= 0.2.3 =

* Linked the displayed Series label to its public Series archive while keeping season and sequence text outside the link.
* Removed Gutenberg's duplicate native Series taxonomy panel on supported article types, leaving the WP Title Layer single-Series selector and Series management screen intact.

= 0.2.2 =

* Restored the Series selector when an earlier or later ACF/theme registration hid the shared taxonomy from REST, and included unassigned Series terms in the editor list.
* Added bounded per-template controls for Series visibility, stacked/inline/hidden subtitles, and safe built-in or custom separators.
* Kept inline subtitles outside the primary heading and raised responsive subtitle sizes for clearer mobile hierarchy.
* Clarified that the five old ACF Series counters describe non-empty field-post values and unique post IDs, including possible defaults, rather than confirmed Series use.

= 0.2.1 =

* Added safe in-place singular title replacement for block themes and Kadence.
* Kept unknown classic themes on explicit manual integration and retained body insertion only as a clearly labelled legacy fallback.
* Displayed Series short/full name plus season/sequence context above the Standard title.
* Added a read-only Control Center linking migration, display, Series, and Reader workflows.
* Froze each reviewed migration candidate set in a plaintext-free manifest and treated all later source, target, trust, or eligibility drift as conflicts.
* Made rollback ownership and deletion resilient to concurrent metadata changes and duplicate target rows.
* Added real Kadence 1.5.2 template smoke coverage and expanded dual-environment WordPress integration coverage.

= 0.2.0 =

* Added server-rendered Title Layer previews in the block editor.
* Added guarded, opt-in automatic title placement using H2 or DIV only.
* Added an opt-in classic-theme Series archive when the theme has no dedicated template.
* Added opt-in ordered-Series previous/next, start, and progress output plus a default-enabled manual navigation shortcode.
* Limited all Reader paths to public, published, non-password-protected articles.
* Added automatic sequential AJAX migration, safe same-run resume, and terminal-state conflict CSV export.
* Expanded the dual-environment WordPress integration suite to 191 checks.

= 0.1.0 =

* Added the native Subtitle and single-Series data model.
* Added ordered/unordered and flat/seasoned Series modes.
* Added context-aware templates with post, Series, category, and global precedence.
* Added explicit block, shortcode, and theme rendering paths without filtering `the_title` globally.
* Added non-destructive Secondary Title and verified ACF subtitle migration.
* Added audit-only reporting for five legacy ACF Series-like fields.
