# Architecture decisions for 1.0.0-rc.3

## Content groups (rc.3)

`wptl_series_group` is optional string post meta exposed through the existing
WordPress post REST endpoint. `_wptl_group_id` is private, derived post meta.
An internal non-public taxonomy, `wptl_content_group`, supplies stable IDs using
WordPress term storage; it has no native metabox, rewrite, or public REST route.
Each record belongs to one Series and an effective Season, or Series-wide scope.
Scope and text changes reconcile after REST writes or classic-editor saves;
low-level metadata/term writes are queued for shutdown. Reads do not create groups.

Group renames require the Series edit-terms capability. Original labels remain
aliases so older Publisher metadata cannot silently undo a shared rename.
The post REST response exposes the current display name. Renaming to an existing
group or alias in the same scope fails with HTTP 409. It does not merge groups.
The authenticated suggestions endpoint requires the Series assign-terms capability.

Public archive filtering checks the owning Series and Season, ANDs its group-ID
condition with existing query restrictions, and leaves ordering to Sequence.
Rendering splits each public archive section into consecutive runs, including
unlabelled gaps. A repeated group after another group receives another heading;
articles are never moved to make same-name groups contiguous.
The public group URL is a filtered archive, not a new taxonomy archive.
Group subheadings are limited to the plugin template; theme layouts retain control.

## Ownership and dependency boundary

WordPress `post_title` remains the only canonical primary title. WP Title Layer
does not rewrite it and does not globally filter `the_title` during normal
operation.

WP Title Layer owns and registers its runtime schema: the Series taxonomy,
canonical post and term metadata, REST exposure, editor controls, and
presentation APIs. ACF is **not a dependency** and is not required to keep the
schema registered or the site readable. It is consulted only as a possible,
strictly verified migration source.

SEO and social plugins continue to own their metadata. Version 1.0.0-rc.3 exposes
read-only Rank Math replacement variables but does not claim SEO/social editing
UI, filter final provider titles, write provider metadata, or emit Open Graph
tags.

## Runtime modes

The presence of the legacy `get_secondary_title()` function marks Secondary
Title as active. In that state WP Title Layer runs in **migration-only mode**:
the scanner and migration tools remain available, while the WP Title Layer
editor and presentation output are withheld. This prevents two active plugins
from rendering or editing the same title concern.

After migration has been reviewed and Secondary Title is deactivated, the
normal editor and presentation modules become active. The renderer still falls
back to `_secondary_title` only when canonical `wptl_subtitle` metadata does not
exist, protecting posts that have not yet been copied.

## Canonical schema

### Post data

| Key | Purpose |
| --- | --- |
| WordPress `post_title` | canonical primary title |
| `wptl_subtitle` | explanatory subtitle |
| `wptl_season_key` | selected season key |
| `wptl_sequence_position` | preserved 0.9.x position and compatibility source before initialization |
| private `wptl_sequence_rank` | canonical managed order after explicit Series initialization |
| private `wptl_sequence_source_position` | frozen baseline used to report later legacy-field drift |
| `wptl_sequence_label` | optional public label override |
| `wptl_series_role` | intro, article, epilogue, or appendix |
| `wptl_series_scope` | explicit `series` or `season` scope for book-like non-main roles |
| `wptl_template_override` | inherited preset, named preset, or `disabled` |
| `wptl_kicker_override` | optional display-only kicker |

### Series data

`wptl_series` is a native, non-hierarchical taxonomy. Term metadata stores:

| Key | Purpose |
| --- | --- |
| `wptl_series_mode` | `ordered` or `unordered` |
| `wptl_series_status` | optional `planning`, `ongoing`, `paused`, or `completed` lifecycle label |
| `wptl_series_structure` | `flat` or `seasoned` |
| `wptl_navigation_scope` | `series` or `season`; missing/invalid defaults to `series` |
| `wptl_archive_sort` | archive sorting choice |
| `wptl_seasons` | centralized season definitions, including immutable positive `public_id` values |
| private `wptl_sequence_schema_version` | whole-Series commit marker; absent/0 means legacy, 1 means managed |
| private `wptl_book_structure_version` | per-Series book-structure commit marker; absent/0 means compatibility mode, 1 means canonical tracks |
| private sequence revisions | optimistic-concurrency versions scoped to the relevant Series/Season |
| private Season public-ID high-water mark | prevents reuse after a Season is deleted |
| `wptl_default_template` | Series-level presentation preset |
| `wptl_short_label` | compact display label |
| `wptl_cover_id` | cover attachment reference |
| `wptl_icon_id` | optional decorative title-context icon attachment reference |
| `wptl_parent_category_id` | optional editorial parent Category; not a taxonomy hierarchy |
| `wptl_archive_layout` | `inherit`, `theme`, or `structured` archive policy |
| `wptl_archive_subtitles` | `inherit`, `show`, or `hide` article Subtitle policy |
| `wptl_archive_excerpts` | `inherit`, `show`, or `hide` structured Excerpt policy |
| `wptl_archive_featured_images` | `inherit`, `show`, or `hide` structured featured-image policy |
| `wptl_title_icon` | `inherit`, `show`, or `hide` title-context icon policy |

The two orthogonal mode settings form four possible Series shapes. Missing or
invalid mode values normalize conservatively to `unordered` and `flat`.
Publication chronology is not reinterpreted as a logical reading order.

Lifecycle status is separately optional and descriptive. A missing or invalid
value remains unset; existing Series are never inferred to be ongoing or
completed from dates, article counts, or sequence data. Status may inform the
editor and structured archive, but it does not change publication, visibility,
ordering, or Reader eligibility.

## Series invariants and enforcement limits

Version 1.0.0-rc.3 supports at most one Series per post. Season, scope, role, and sequence
metadata belongs to the post-to-Series relationship and would be ambiguous with
multiple memberships. REST input rejects multiple Series terms, and the native
term-assignment hook corrects a multi-term assignment to one deterministic
term. Multiple independent Series relationships require a different future
relationship model and are not promised by 1.0.0-rc.3.

The optional parent Category is a soft editorial relation between two native
taxonomies. It does not make the Series taxonomy hierarchical and never changes
an article relationship. The same branch means the parent Category itself or
any descendant. Gutenberg and Series Health may report a mismatch, but saving
and publishing remain available; the editor must decide whether the article or
Series mapping is wrong.

An ordered Series has two deliberately separated states:

- **Legacy:** no schema marker. Every reader continues to use
  `wptl_sequence_position`, including its historical soft-constraint behavior.
  Merely activating or updating the plugin writes no rank.
- **Managed:** schema marker `1`. Every reader uses the same Sequence service
  and private rank; direct changes to the legacy position are reported as drift
  but cannot alter public order.

Initialization freezes the reviewed member/source fingerprint, assigns ranks
in non-autoloaded resumable journal batches, validates the complete collection,
and writes the schema marker last. A partially written initialization therefore
still renders through the complete legacy order. Recovery deletes only values
that still equal the journaled write and never touches article content, labels,
relationships, or the legacy position.

Managed moves submit the article and server-resolved anchor, not a caller-made
rank. The service checks capability, scope membership, nonce, and current
revision, then chooses a sparse rank or rebalances the scope. Each manual move
records old and new values for one guarded undo. Automatic ordinals are derived
from canonical order and cached against the scope revision; rank integers are
not public labels or REST editor fields.

New or scope-changed managed members append at the end of their exact Series or
Season. Moving, trashing, restoring, changing Season, or changing article role
invalidates the affected revision and ordinal cache. Missing or damaged ranks
remain visible at the end of the bounded Manager page so repair does not depend
on an SQL inner join that hides the broken record.

Season `public_id` is a stable public identity independent of its storage key,
display label, and sort order. Server-side persistence retains IDs for surviving
keys, ignores submitted ID tampering, allocates only above the high-water mark,
and never reuses a deleted number. Numeric URLs are canonical; key URLs remain
resolvable for backward compatibility.

## Book-structure activation and track order

Scope and role are separate canonical dimensions. A blank role and the explicit
`article` role both resolve to a main article without rewriting old metadata.
In a flat Series, every valid role is Series-wide. In a seasoned Series, a main
article must have one valid Season; an introduction, epilogue, or appendix must
explicitly choose either the whole Series or one valid Season.

Existing Series remain in compatibility mode after upgrade. Their current
front-end order continues to render, while the Structure tracks view produces a
read-only fingerprinted preview. The separate Sequence view remains available
to every ordered Series regardless of advanced-structure activation. A seasoned non-main legacy role with no
explicit scope is never silently promoted: it must be resolved in the article
editor before activation. Invalid Seasons, a main article marked Series-wide,
and season scope on a flat Series also block activation. New, genuinely empty
Series can start directly in the canonical model; an empty ordered Series first
receives the normal Sequence marker and stable Season identities.

Each role/scope pair is an independent track. Canonical complete-Series order is:

1. Series-wide introductions;
2. for each Season: its introductions, main articles, epilogues, and appendices;
3. Series-wide epilogues;
4. Series-wide appendices.

A season-filtered collection contains only that Season's four tracks. Ordered
main tracks reuse the 0.10 sparse ranks and produce automatic ordinary-article
ordinals. Non-main tracks use the same revision, sparse-move, journal, and
value-checked undo mechanics, but their ranks remain private and they never
consume a main-article number. In an unordered Series, main articles retain the
configured archive sort and do not gain previous/next navigation; movable
non-main tracks are still pinned at the appropriate structural edges.

Role-specific labels belong to the administration model, not the public
information architecture. The structured archive merges a Season's four tracks
under the Season label while preserving canonical entry order. Each
Series-wide non-main entry is a separate public section headed by `Series` plus
its explicit Public structure label; when that label is empty, only `Series` is
shown. The internal role is never substituted into a public heading or article
title layer.

Activation writes a non-autoloaded journal before assigning missing private
non-main ranks, verifies every value, and commits the term marker last. A failed
write restores only the values recorded by that activation. After activation,
Sequence initialization rollback is refused because the book tracks depend on
that canonical base.

## Series content-health boundary

The Series Health screen is an administrative read model, not a repair engine.
It accepts only a safely claimed Series term and never calls post, term-meta,
option, or relationship mutation APIs. The page requires `manage_options` by
default; a filter may replace that capability, while individual article edit
links still require `edit_post` and the Series-definition link requires
`edit_term`.

For a managed Series it reports missing, invalid, or duplicate rank and any
post-initialization change to the preserved legacy position. Its repair link
hands off to Sequence Manager; Health itself remains mutation-free.

Whole-Series totals are calculated by aggregate SQL. Member details are fetched
in bounded pages of 50, and the Series chooser is independently paginated. Both
season and position joins select the lowest `meta_id` for each canonical key,
matching the archive's deterministic behavior when historical duplicate meta
rows exist. Request values can choose only a fixed report view and positive
page number; taxonomy, post type, term ID, statuses, season keys, limits, and
offsets are prepared or taken from internal allowlists.

Structural checks are mode-aware. Position and duplicate checks run only for
ordered Series; season checks run only for seasoned Series. Position zero is a
valid numeric value. Duplicate positions are scoped to the same season when a
Series is seasoned. Trashed/internal content remains visible in totals where
useful but does not become a structural failure. Published, scheduled, and
private issues are separated from draft, pending, and other work-in-progress
issues, and password protection is reported without reading or exposing the
password itself.

When a Series declares a parent Category, the report also checks whether each
member belongs to that Category branch. This remains a read-only structural
signal and is available as its own bounded report view; it is not a publication
gate or repair action.

## Migration trust boundary

Migration copies data into `wptl_subtitle`; it never renames or deletes source
metadata. The existence of the target key is authoritative, including an empty
target value.

There are exactly two automatic sources in 1.0.0-rc.3:

1. `_secondary_title` from Secondary Title;
2. `subtitle` only when it is proven to be a genuine ACF field.

An ACF value is trusted only when the post has an `_subtitle` reference using a
valid `field_*` key and that key resolves to a real ACF field definition whose
field name is `subtitle`. Definitions may be found through ACF's API or its
stored `acf-field` definition. Raw same-named metadata is not automatically
trusted. Unverified references, multiple source values, source disagreement,
and an existing different target are conflicts for review.

The migration screen separately audits five legacy Series-like ACF names:

```text
series_key
series_title
series_order
series_has_order
series_stage
```

For each, the detector reports definitions, posts with stored values, and
verified ACF field assignments. It also reports unique post IDs across all five
fields because summing the per-field counts can count one article up to five
times. Stored `0`, `false`, and configured defaults remain visible, but exact
empty-string values are excluded by this report; none is automatically labelled
meaningful or meaningless.
These are **audit-only** observations. No value is automatically mapped to the
`wptl_series` taxonomy, a season, a position, or a Series mode. That boundary
avoids guessing incompatible historical semantics.

Migration is dry-run-first, batched, idempotent, and journaled. After an
administrator explicitly starts a scanned run, authenticated AJAX requests
process one server-side batch at a time and the browser requests the next batch
only after the prior result is stored. Each request repeats the capability and
nonce checks and accepts only the run identifier; the server-owned run state,
batch cursor, locks, and journal remain authoritative. A reload or network
interruption can therefore resume the same run without replaying completed
batches. A guarded rollback removes only target rows created by the recorded
batch and only while their values still match the recorded hash.

A complete scan commits a chunked, non-autoloaded manifest containing only post
IDs and irreversible state fingerprints, never subtitle plaintext. A run binds
to that exact scan ID and consumes only that manifest. Source values, target
rows, ACF trust, post type, post status, and eligibility are revalidated at the
intent and write boundaries; drift becomes a conflict rather than expanding or
silently changing the reviewed batch. Legacy 0.2.0 runs without a frozen
manifest fail closed and may still use their existing rollback journal.

Conflict CSV export is capability- and nonce-protected, neutralizes spreadsheet
formula prefixes, omits raw subtitle values and hashes, and is available only
after the run reaches a stable terminal state.

## Template resolution and safe rendering

Presentation presets are named, validated records. A post cannot store arbitrary
HTML as a one-off template. Effective template selection is:

1. per-post named override or `disabled`;
2. selected Series default;
3. first matching category rule in saved rule order;
4. global default.

The renderer escapes semantic values, interpolates only supported tokens, and
passes final output through a strict HTML allow-list.

The four plugin-owned preset IDs also accept a bounded recipe stored under
`presentation.preset_customizations`, with
`presentation.preset_schema_version=1`. Its complete schema is
`series_position` (`above|hidden`), `subtitle_position`
(`stacked|inline|hidden`), `separator_style` (`colon|dash|pipe|custom`), and an
optional plain-text `custom_separator` limited to eight Unicode characters.
Invalid records fail as a whole to that preset's defaults. Default recipes and
explicit resets are not persisted. The full renderer and exact classic-theme
fragments consume the same normalizer; inline subtitles remain siblings of the
single primary heading rather than becoming part of its text.

The built-in presets have distinct product roles even where their semantic
slot order is shared. Standard is neutral and theme-led; Editorial feature
retains that hierarchy but adds a restrained accent and stronger context for
long-form work; Inline keeps available Series context above a same-line title
and subtitle; Minimal is the title-only compatibility path. The editor exposes
these use cases in the selection and customization descriptions.

Display uses one mutually exclusive mode:

1. `manual`, the universal default and fallback;
2. `replace-theme-title`, enabled only when a verified title-slot integration
   exists;
3. `auto-prepend`, retained as a legacy body-insertion fallback.

Manual entry points are:

- dynamic block `wp-title-layer/title-layer`;
- shortcode `[wp_title_layer]`;
- subtitle shortcode `[wptl_subtitle]`;
- `WPTitleLayer\Presentation\Compatibility::render()` for theme code;
- Secondary Title compatibility functions/shortcode after the old plugin no
  longer owns them.

Title takeover is intentionally slot-specific rather than global. Block themes
use `render_block_core/post-title` only for the exact queried singular post,
outside Query Loops, while retaining the original heading tag and sanitized
root attributes. The bundled classic adapter supports Kadence's exact
`kadence_single_before_entry_title` / `kadence_single_after_entry_title` pair;
its `the_title` filter remains inert unless that exact slot has armed it and it
returns only phrasing-safe title content, never a complete header or heading.
Default support is limited to verified official Kadence 1.5.x title templates;
a child theme that overrides either relevant title template and unknown classic
themes stay in manual mode. A trusted-code support filter may explicitly add a
site-specific adapter after its author verifies the same structural contract.

All takeover paths require a public, published, non-password-protected singular
post with actual Title Layer data. Admin, AJAX, REST/JSON, feeds, embeds,
XML-RPC, cron, CLI, archives, Query Loops and linked Post Title blocks are
excluded. Disabled, empty, unsupported, unreadable and failed renders return
the original theme title unchanged. Filtered block-theme output must still
contain exactly one title marker using the inherited title element and theme
classes; an invalid extension result fails open to the native title. Request
state includes the current site and post IDs.

The block editor uses WordPress server-side rendering to preview the dynamic
block. The preview is based on the latest saved article data, so saving or
updating is the synchronization boundary for changed title fields.

An administrator may still opt in to `auto-prepend` for selected public post types,
but the setting is valid only with an explicit confirmation that the theme's
visible title has been disabled. Automatic rendering deliberately permits only
`h2` or `div`, never H1. The content filter requires the queried post in the
main singular loop, renders at most once, and yields to an explicit Title Layer
block or shortcode. It rejects feeds, REST/JSON, embeds, excerpts, admin/AJAX,
unpublished content, and password-protected content.

Standard and Editorial feature render the Series short label (falling back to
the full term name) and ordered season/sequence label as an eyebrow by default.
When the claimed taxonomy is publicly queryable, the Series label links to its
term archive. A defined season label independently links to the same archive
with the canonical `wptl_season` query variable; the sequence text remains a
plain sibling. Season filtering is byte-exact and reads only the canonical
first metadata row so duplicate legacy rows cannot place one article in two
season views.
Non-Series posts omit the eyebrow. Minimal deliberately suppresses the Series
line; any bounded recipe may still explicitly show or hide Series context.

The optional Series icon is a separate image attachment, not the archive cover
and not title text. A global boolean defaults to off; `wptl_title_icon` may
inherit, show, or hide it per term. It renders only when the visible kicker is
the canonical Series short/full label, as an empty-alt, `aria-hidden`
decoration inside the Series link but outside the primary heading. A post-level
kicker override therefore never acquires the icon. The classic title-fragment
allow-list permits the image only in adjacent context fragments, while the
heading allow-list does not permit images. Rank Math and every semantic value
API continue to return plain text.

The editor requests Series terms with `hide_empty=false`. WP Title Layer also
removes Gutenberg's duplicate native taxonomy panel on supported article types,
leaving its own single-Series selector as the only relationship editor; this
does not unregister the taxonomy, alter REST, or remove the Series management
screen. The exact default
`wptl_series` key is treated as plugin-owned when an existing taxonomy is public
and has an administrative UI. A pre-existing custom key requires an explicit
`wptl_claim_existing_series_taxonomy` opt-in. Private, UI-hidden, unselected, or
unclaimed taxonomies are never exposed, attached, given plugin term metadata,
or touched by Core/Presentation/Reader behavior. Once safely claimed, a taxonomy
that ACF, a theme, or an older implementation registered without REST visibility
receives only the required REST properties and configured post attachment. A
later same-key registration is repaired without replacing its capabilities,
REST base/controller, namespace, or original object types.

The empty `wptl_series_role` value and explicit `article` value continue to be
compatible main-article states. Version 0.10.0 changes only their interface
labels to distinguish “unspecified, treated as main” from “explicit main”; it
does not backfill or rewrite either post-meta form.

When `use_block_editor_for_post()` reports that Gutenberg is disabled, a
separate Classic Editor meta box edits the same canonical Subtitle, one existing
Series relationship, season/order fields, kicker, and template override. It is
nonce- and object-capability protected, writes an explicit empty canonical
Subtitle when the field is cleared, and never creates Series terms. It is not
registered in migration-only mode and never renders beside Gutenberg. The
default plugin taxonomy can use the single-Series control; a deliberately
reused custom taxonomy keeps its native relationship UI unless trusted code
opts in through `wptl_classic_editor_owns_series_control`.

## Localization boundary

`wp-title-layer` is the single text domain for PHP and JavaScript. The plugin
loads its PHP catalog from `/languages` and explicitly registers the
`wptl-editor` script translation path with WordPress. Administration strings
therefore follow the current user's locale, while front-end strings and
formatting follow the locale of the current request. English is the source and
fallback language; the release includes `zh_CN` PO, compiled MO, and editor
Jed JSON files. The source POT remains the public extension point for all other
languages.

Template recipes store only `colon`, `dash`, `pipe`, or a bounded custom text
value. They never store a locale-specific built-in glyph. Rendering resolves
those semantic choices to `: `, ` — `, and ` | ` for English, or `：`, `——`,
and `｜` for Simplified Chinese. The SEO title composition path calls the same
locale policy. Custom separators are not translated. Locale switching is
read-only with respect to options, post metadata, term metadata, and content.

Translations from WordPress core, the active theme, or another plugin remain
owned by those components. WP Title Layer does not capture or override foreign
text domains merely to make one administration page visually uniform.

## SEO and social channel boundary

WP Title Layer registers `%wptl_subtitle%`, `%wptl_series%`, and
`%wptl_title_with_subtitle%` through Rank Math's documented custom replacement
API. The combined value contains the canonical WordPress title and conditionally
adds the current locale's title separator plus the subtitle; a Series archive
resolves to the Series term name rather than the first post in its loop. Values
are plain text.

Registration is opt-in at the Rank Math field level: the site owner places a
variable in an SEO, Facebook, or Twitter title template. WP Title Layer does
not hook `rank_math/frontend/title` or Rank Math Open Graph title filters and
does not write `rank_math_title`, `rank_math_facebook_title`, or
`rank_math_twitter_title`. This preserves provider-level global and per-object
overrides. Multiple Series per post remain outside the 1.0.0-rc.3 contract.

### Landing-page and channel governance

`Admin\ChannelHealthReport` is a read-only observer. It never calls WordPress
metadata, option, term-relationship, redirect, robots, canonical, or sitemap
write APIs. Its administration page is capability-gated (default
`manage_options`), contains no mutation form, and is not registered while the
legacy Secondary Title plugin keeps WP Title Layer in migration-only mode.

Duplicate entry-point classification is based on exact public sets, not names,
slugs, or fuzzy similarity. The eligible set is the intersection of public
post types attached to both Series and Category, limited to `publish` status
and an empty password. `exact` means the shared count equals both complete
eligible sets and is nonzero; any nonzero but unequal set is `partial`.
Aggregates stay in SQL, overlap rows are paginated, and no complete membership
list is loaded into PHP merely to render the report.

Provider activation is detected from the running provider, never merely from
stored options. Rank Math is the only provider whose local schema 1.0.0-rc.3
interprets. For it, taxonomy defaults remain distinct from explicitly saved
taxonomy settings; term metadata can then override title, description, robots,
canonical, Facebook, and Twitter channels. Sitemap inclusion requires the
module, taxonomy switch, and absence from the global term-exclusion list.
Search and social templates are inspected for the three registered WPTL
variables but are never rewritten. If another provider or multiple providers
are detected, the report stops at ownership identification and directs the
administrator to the provider UI and rendered page source.

## Settings interface boundary

The Presentation, Reader, and optional integration modules register native
WordPress Settings API sections and fields against one shared page, form, nonce,
Save Changes action, and `wptl_settings` option. In 0.10.0 each registered
section is a numbered responsive group and JavaScript progressively enhances
those groups into a WAI-ARIA tablist. Arrow keys, Home/End, focus state, and the
URL hash are supported. The shell discovers later registered sections instead
of hard-coding current integrations.

Without JavaScript every section remains visible in native registration order
and the same form remains fully saveable. Tabs do not introduce AJAX, separate
persistence endpoints, new capabilities, or new ownership of the shared
option; they are navigation only.

## Reader boundary

Reader features share one canonical ordering service so archives, navigation,
and progress do not disagree. Only posts with `publish` status and no password
are eligible, regardless of the current visitor's administrative capabilities.
Unordered Series never expose previous/next navigation or progress.

Theme-owned archive rendering is the default and recommended mode for visual
consistency with Category archives. Ordered Series always use their canonical
season/position/ID reading order. Unordered Series honor their configured date
ascending, date descending, or title sort, applied after ordinary theme query
callbacks. The plugin-owned structured Series archive
is opt-in and is considered only for the `wptl_series` taxonomy on a classic
theme when the theme has neither a term-specific nor taxonomy-wide dedicated
Series template. Its article subtitles have an independent on/off setting. The
template uses a plugin-specific container ID so theme selectors for `#primary`
cannot collapse it into an unrelated desktop content column. Its no-cover
header uses the available desktop width; the two-column header is enabled only
when a cover exists. Block themes and
dedicated classic templates remain theme-owned. Category, tag, and other
archives are never replaced.

Global Reader values are defaults. A Series may explicitly inherit or override
theme versus structured layout, article Subtitle visibility, and compact
featured images in the structured list. Missing or invalid term metadata
normalizes to `inherit`, preserving every pre-0.9.0 archive. Featured images
remain globally off by default. A parent Category link is metadata-driven and
appears only in the plugin-owned structured header; it never changes the
archive query or article Category assignments. Structured Excerpts are a
separate default-off global value with the same per-Series inheritance model.
They are fetched only while building the plugin-owned structured view model;
the verified theme adapter, Category, Search, Home, and other archives never
receive an Excerpt injection path. Disabled Excerpts remain empty in the view
model rather than being calculated speculatively.

Theme-owned archive-card Subtitle output is a separate, default-off adapter
contract. Version 0.10.0 implements only the verified official Kadence 1.5.x
`kadence_loop_entry_header` title-to-meta slot at priority 25. It requires the
main claimed Series taxonomy query, a public published non-password post in the
current loop, intact parent templates, and no child override of those loop
templates. Category, search, home, plugin-structured, block-theme, and unknown
classic-theme archives are untouched; no global `the_title` filter is used.
The inserted Subtitle uses a bounded responsive 17–19px size so it remains
subordinate to the card title without dropping below ordinary body readability.

Automatic after-content previous/next, start, and progress output is separately
opt-in. The manual `[wptl_series_navigation]` shortcode is registered by
default, allowing deliberate placement without enabling automatic appending.
Both paths use the same published, non-password-protected visibility rule and
render only for an ordered Series.

An ordered, seasoned Series may explicitly scope navigation to the entire
Series or the current season. This is term-owned editorial policy, not a global
Reader preference and not a display-template inference. Missing, invalid, and
pre-0.6.0 values normalize to the full Series; flat Series also remain full
Series. In current-season mode, previous, next, first, position, and total are
all derived from the same exact-season subset of the canonical public order.
An absent or undefined season returns no navigation for that article rather
than crossing a boundary or inventing membership.

Previous/next navigation currently loads and sorts the complete eligible Series
to select neighboring entries. Archives retain WordPress pagination and apply
the same season/position/ID order in SQL. This keeps season boundaries and
navigation consistent, but full-Series navigation remains a known performance
boundary for exceptionally large Series; an indexed strategy would be required
before treating such collections as unbounded.

Article navigation and page pagination intentionally use different source
strings. Reader cards use Previous / Next as article relationships, while
structured archives and administrative reports use Previous page / Next page.
This keeps languages such as Simplified Chinese from conflating 上一篇/下一篇
with 上一页/下一页.

The structured paginator owns its visual contract instead of inheriting
underlined theme links: square controls, a solid current page, arrow-only
visible previous/next controls, an unboxed ellipsis, explicit focus outline,
and no underline in any interaction state. Full localized Previous page / Next
page text remains visually hidden inside the arrow links for assistive
technology.

The Series management UI starts a new term with four season rows and can append
more client-side before the first save. Its cover control progressively
enhances the canonical numeric attachment ID to the WordPress image media
library. If media scripts are absent, the numeric field remains visible and
existing data is retained. WordPress post list tables expose a Subtitle column
immediately after Title; users may hide it through the native Screen Options
control.

## Verification matrix

Run the local static/editor checks with:

```sh
npm run check
```

Run syntax and integration smoke tests in WordPress Playground with:

```sh
npm run test:wp
```

The Playground script parses 61 PHP files and runs 828 integration
checks plus 111 Sequence Manager, 65 book-structure, and 42 content-group checks at both supported ends of the 1.0.0-rc.3
matrix:

- WordPress 6.5 with PHP 7.4;
- WordPress 7.1 with PHP 8.3.

The verified Kadence adapter is additionally exercised against the official
Kadence 1.5.2 theme templates:

```sh
./bin/test-kadence.sh /path/to/kadence.1.5.2.zip
```

That real-template smoke currently performs 40 checks, including separate
Series/season archive-link placement outside the native H1, sequence-label
separation, decorative Series icons outside both verified native H1 slots,
Inline's Series-eyebrow hierarchy, and opt-in Series-archive card Subtitles
without Category leakage.

The optional Rank Math boundary is tested against the official plugin rather
than only a local API double:

```sh
./bin/test-rank-math.sh /path/to/seo-by-rank-math.zip
```

The current Rank Math 1.0.276 smoke performs 32 checks, including two posts
using the same variable string in one request so a cached first-post value
cannot leak into later replacements, plus its real taxonomy options, term
metadata, sitemap switches, and read-only governance report.

After building a release, the same dual-environment matrix must also boot the
extracted production artifact rather than the development directory:

```sh
./bin/test-release-package.sh wp-title-layer-1.0.0-rc.3.zip
```

The first Playground run may require network access to obtain WordPress.
