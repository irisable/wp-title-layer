# WP Title Layer

[![CI](https://github.com/irisable/wp-title-layer/actions/workflows/ci.yml/badge.svg)](https://github.com/irisable/wp-title-layer/actions/workflows/ci.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](LICENSE)

WP Title Layer keeps the native WordPress `post_title` as the canonical primary
title, then stores the surrounding editorial structure as separate data:

- an explanatory subtitle;
- one native WordPress Series relationship per post;
- ordered or unordered Series, optionally divided into seasons;
- an opt-in Sequence view for inserting and moving ordered articles without
  renumbering every later article, independent of optional book-like tracks;
- explicit Series/Season scope plus introduction, main-article, epilogue, and
  appendix tracks for book-like Series;
- an optional explicit Series lifecycle status without publishing side effects;
- a read-only, status-aware Series content-health report;
- named display templates with category, Series, and post-level selection;
- optional Series archives, ordered reading navigation, progress, and explicit
  full-Series or current-season boundaries;
- an optional parent Category branch for each Series, with non-blocking
  consistency checks that never rewrite article categories;
- per-Series archive layout, Subtitle, Excerpt, and structured featured-image
  policies;
- an optional media-library Series icon that remains decorative and outside
  article, SEO, and social-title semantics;
- optional Rank Math variables for independently composed SEO and sharing
  titles;
- a read-only landing-page and channel report for duplicate Category entry
  points, canonical, robots, sitemap, search titles, and social titles;
- a media-library Series cover control and a fail-safe Classic Editor meta box;
- opt-in archive-card subtitles for verified official Kadence 1.5.x templates;
- an accessible, progressively enhanced tabbed settings screen that keeps
  placement, presentation, reading, and search/share responsibilities separate;
- a non-destructive migration path from Secondary Title and verified ACF
  subtitle fields.

The current implementation status and production acceptance order are tracked
in the [1.0.0-rc.3 feature map](docs/feature-map.md). The completed stages and path
to 1.0 are recorded in the [development roadmap](docs/roadmap.md), with the
ordering and upgrade contract in the
[Series Sequence Manager design](docs/sequence-manager-design.md).
A focused [1.0.0-rc.3 acceptance record](docs/manual-acceptance-checklist-1.0.0-rc.3.md)
covers content-group editing, linked filtering, mobile presentation, shared
renames, and compatibility with existing ungrouped articles.

ACF is **not a dependency**. WP Title Layer registers and owns its taxonomy,
post metadata, term metadata, REST schema, editor controls, and rendering layer.
It works when ACF is absent or has been removed.

Before removing ACF from an existing site, run the migration report while ACF
is still active. The five audited Series fields report non-empty field-post
values and unique post IDs, not confirmed use: ACF-saved `0`, `false`, and
configured defaults can be included, while exact empty strings are excluded.
WP Title Layer never converts those five fields automatically. If the site
owner confirms they were unused, any verified ACF subtitle values have been
migrated, and the site has no other ACF-dependent
content, WP Title Layer itself gives no reason to keep ACF installed. The report
cannot certify unrelated fields owned by themes or other plugins.

## Install and start

1. Install the ZIP or copy the `wp-title-layer` directory to
   `/wp-content/plugins/`.
2. Activate WP Title Layer.
3. If the site used Secondary Title or an ACF field named `subtitle`, open
   **Tools → Title Layer Migration** and run the read-only scan first.
4. Review conflicts, then start the copy. Version 1.0.0-rc.3 continues through the
   batches automatically in the migration screen and can safely resume the
   same run after a reload or interrupted request.
5. Verify several posts and deactivate Secondary Title. While Secondary Title
   remains active, WP Title Layer stays in **migration-only mode**: its editor
   and front-end presentation are not enabled, preventing two plugins from
   owning the same output.
6. Open **WP Title Layer → Control Center**, then configure display under
   **Settings → WP Title Layer**. When the detected theme supports it, select
   **Replace the theme post title** so the layer occupies the original title
   slot instead of the article body.
7. Test one ordinary article and one Series article. Block themes and the
   verified official Kadence 1.5.x title templates have title-slot
   integrations. A Kadence child theme that overrides those title templates,
   and other classic themes, retain their title and use manual block/theme-API
   placement.
8. Enable Reader features separately if desired. They remain off by default.
9. Open **WP Title Layer → Landing & Channels** for one Series at a time before
   changing canonical, robots, sitemap, or duplicate Category entry points.
10. For an existing Series, open **WP Title Layer → Sequence & Structure**.
    Every ordered Series has a **Sequence** view; initialize it once if needed.
    Use **Structure tracks** only when the Series also needs book-like roles.
    Merely upgrading the plugin does not convert or rewrite any old Series.

Migration never deletes the source values and never overwrites an existing
`wptl_subtitle`, including one intentionally saved as empty.

## Optional content groups (1.0.0-rc.3)

The article editor now accepts a **Content group**, for example `W1 看见人的软弱`.
Existing names in the current Series/Season are suggested. Saving resolves or
creates a stable group ID; selecting a different name moves only this article.
The separate **Rename this group** action changes the shared name immediately
for every member while preserving existing group URLs and old-name aliases.

Structured archives insert a small heading for each consecutive group run.
They preserve article order, article numbers, ungrouped gaps, and normal
pagination. A group spanning two pages repeats its heading on the second page.
The single title layer displays Series, Season, group link, and article number.
The optional `wptl_group=ID` filter works within the owning Series and Season;
unknown or incompatible group IDs return no matching articles.

Native theme archives support the group filter but retain their own layout.
Group subheadings are supplied by the plugin's structured archive template.
Previous/next navigation retains the configured Series/Season scope.
Blank group fields retain existing output. No historical label is inferred or
migrated automatically into a group.

Publisher clients write `meta.wptl_series_group` as a string. See the
[Publisher group integration contract](docs/wp-publisher-content-groups-rc.3.md)
and [focused acceptance checklist](docs/manual-acceptance-checklist-1.0.0-rc.3.md).

## What migration copies

Version 1.0.0-rc.3 has exactly two automatic subtitle sources:

- Secondary Title's `_secondary_title` post meta;
- an ACF field whose field name is `subtitle`, but only when WP Title Layer can
  verify a real ACF field definition and the post's `_subtitle` reference
  points to that field key.

A raw meta key named `subtitle` is not sufficient proof. Unverified references,
disagreeing sources, multiple values, and existing different targets enter the
conflict report instead of being guessed or overwritten.

The migration screen also audits these five legacy Series-like ACF fields:

- `series_key`
- `series_title`
- `series_order`
- `series_has_order`
- `series_stage`

They are **non-empty-value and verified-reference counts, not confirmed usage
counts**. ACF defaults can be included, exact empty strings are excluded, and
the same post can be counted once for each field. Version 1.0.0-rc.3 does not
automatically map them to `wptl_series`, seasons, or sequence metadata.

The migration process is intentionally conservative:

1. scan without writing;
2. preview ready, matching, and conflicting records, then freeze the reviewed
   candidate IDs and state as plaintext-free, non-autoloaded manifest chunks;
3. copy only frozen candidates whose source, target, ACF trust, content type,
   status, and eligibility still match the scan, and only into an absent
   canonical target;
4. retain all source data;
5. journal each batch before the browser requests the next one;
6. resume an interrupted run without re-copying completed batches;
7. export a formula-safe conflict CSV only after the run reaches a stable
   terminal state;
8. allow guarded rollback only for unchanged rows created by that batch.

Anything added or changed after the scan is left for a new scan or recorded as
a conflict; it is never silently pulled into the reviewed batch.

## Series model and limits

`wptl_series` is one non-hierarchical taxonomy. Each Series has two independent
settings:

- `ordered` or `unordered`: whether a meaningful reading path exists;
- `flat` or `seasoned`: whether entries are grouped into seasons.

Version 1.0.0-rc.3 supports **at most one Series per post**. It does not model
multiple independent memberships.

Each Series may optionally point to one parent Category. This is an editorial
map between two independent taxonomies, not a taxonomy hierarchy. An article is
consistent when it belongs to that Category or one of its descendants. A
mismatch is shown in the block editor and Series Health, but never blocks a
save and never adds or removes an article Category automatically.

Each Series can optionally declare one descriptive lifecycle status:
**Planning**, **Ongoing**, **Paused**, or **Completed**. Existing Series remain
**Not set** until an editor makes an explicit choice; no date or article count
is used to guess a status. The value is available in the Series editor, Series
list, REST term metadata, and the structured Series archive. It never publishes,
hides, locks, or reorders articles.

An ordered Series with seasons also has an explicit reading-navigation scope:

- **Entire Series** allows previous/next, start, position, and total to cross a
  season boundary;
- **Current Season** derives all five controls from the article's exact defined
  season and stops at that season's first and last public article.

Existing Series and missing or invalid values default to Entire Series, so an
upgrade does not silently change navigation. Flat Series always use the full
Series. If Current Season is selected but an article has no valid season,
navigation fails closed for that article instead of guessing a boundary; the
read-only Series Health report identifies the season problem.

Existing ordered Series remain on their legacy `wptl_sequence_position` values
until an administrator explicitly initializes them in **WP Title Layer →
Sequence & Structure → Sequence**. Its searchable Series chooser opens a
selection immediately; without JavaScript, the matching native select and Open
button remain available. The preview freezes the reviewed membership and source
values, reports ambiguous rows, writes private ranks in resumable batches, and
commits the new schema marker only after the whole Series validates. It never
deletes or rewrites the legacy values.

After initialization, the Manager's canonical rank drives every front-end
consumer. Authors move an article before or after another article, drag it, or
use keyboard controls; they do not maintain rank integers. Visible ordinary
article numbers are calculated from the resulting order, while an optional
public label can still display values such as `21A` or `0-1`. New articles are
safely appended to their Series or Season and can then be moved. A guarded
single-step undo and revision conflict checks prevent stale screens from
overwriting later work.

Season definitions also receive stable positive public IDs. New links use
`?wptl_season=1`, `?wptl_season=2`, and so on; renaming or reordering a Season
does not change its ID, deleted IDs are not reused, and old key-based links
remain compatible.

Version 1.0.0-rc.3 separates a book-like entry's **scope** from its **role**.
Flat Series entries are Series-wide. In a seasoned Series, every main article
must belong to one Season, while an introduction, epilogue, or appendix may be
explicitly Series-wide or belong to one Season. Each combination has its own
track in **Sequence & Structure → Structure tracks**, so multiple prefaces or
afterwords can be rearranged without consuming ordinary-article numbers. Public labels such as `0-1`,
`Preface II`, or `Appendix A` remain optional display text, not ranks.

The Structure manager keeps those exact role tracks visible for safe editing.
The public structured archive uses editorial labels instead: every Season is
one section containing its entries in canonical track order, without headings
such as “Season — Main articles.” A Series-wide non-main entry receives its own
heading built from `Series` plus its Public structure label, such as “Series
Preface”; the private role is never used as public fallback text.

Existing Series are not guessed into this model. The Structure tracks view first
shows a read-only compatibility preview; any legacy non-main role with unresolved
scope must be corrected in the article editor. Activation is per Series and
commits only after its track ranks validate. The Sequence view remains available
to every ordered Series before and after that activation. Newly created empty
Series start canonical immediately. Unordered Series retain their chosen body
sort and no main previous/next path, but their non-main structural tracks can
still be placed at the appropriate archive edges.

Open **WP Title Layer → Series Health** (or use **Check Series health** in the
Control Center) to audit that soft constraint without changing content. The
report checks one Series at a time for missing or invalid positions, undefined
or missing seasons, and duplicate positions. It separates published,
scheduled, private, draft, pending, trashed, and password-protected articles,
then links each finding to the native article editor when the current user may
edit it. Draft-stage gaps are informational; published, scheduled, and private
gaps are called out separately.

The report aggregates whole-Series counts in the database and fetches only 50
member rows per page. It never publishes, hides, reorders, assigns, or repairs
an article. Its Series overview can be searched by name or slug; filtered pagination
keeps the search term without loading member rows. Unordered Series are not treated as if they were missing a reading
position, while a seasoned Series still requires a valid season assignment.

An absent or invalid Series mode is treated as `unordered`; publication date is
never invented as a logical reading order.

## Presentation and output

Template resolution is deterministic:

1. per-post template override or `disabled`;
2. Series default template;
3. first matching category rule;
4. global default template.

WP Title Layer deliberately does **not** use a general structured-HTML
`the_title` filter. Activating it therefore does not silently alter menus,
archives, widgets, feeds, or unrelated theme headings. Display is one of three
mutually exclusive modes:

- **Replace the theme post title:** recommended when a verified integration is
  detected. Block themes are replaced through their singular Post Title block;
  Kadence is replaced only inside its exact singular title hooks. The theme's
  heading level is retained and the article body is untouched.
- **Manual block or theme API:** the universal fallback and safe default.
- **Legacy body insertion:** prepends a layer to the article body only after an
  administrator confirms the theme title was disabled separately. It does not
  remove the theme title itself.

Manual entry points are:

- dynamic block: `wp-title-layer/title-layer`;
- shortcode: `[wp_title_layer]`;
- subtitle-only shortcode: `[wptl_subtitle]`;
- theme code:

```php
echo \WPTitleLayer\Presentation\Compatibility::render( get_the_ID() );
```

The block editor displays a server-rendered preview of the latest saved article
data. Save or update the article to refresh title-field changes in that preview.

The Standard template shows a Series short label (falling back to the full
Series name) and any season/sequence label as an eyebrow above the primary
title, then the subtitle below. The displayed Series label links to that
Series's public WordPress archive. In a seasoned Series, the season label has
its own link to the filtered list for that season; the sequence label remains
plain text. Both links are undecorated by default and follow the theme text
color, then use the theme highlight color plus an underline on hover or
keyboard focus.
A Series may also select a small image attachment distinct from its archive
cover. Its site-wide display default is off, and each Series may inherit, show,
or hide it. When enabled, the image is an empty-alt decorative child of the
Series link above the heading; it is never placed inside the H1 or added to any
plain-text title or Rank Math variable. A post-level custom kicker does not
masquerade as an icon-bearing Series label.
A non-Series article simply omits that eyebrow. The primary title remains the
native WordPress `post_title`.

The four built-in templates have safe recipe controls on the WP Title Layer
settings page. For each template, an administrator can show or hide the Series
context, place the subtitle below or beside the title (or hide it), and choose
the locale's colon, em dash, vertical bar, or a plain-text custom separator of
at most eight characters.
These controls do not accept HTML, CSS, or PHP, and each template can be reset
to its built-in defaults. A same-line subtitle is a visual sibling of the
heading; it is never added to the WordPress primary-title heading text.
Stacked subtitles use a responsive 20–27.2px range, while inline subtitles use
a compact 19.2–24.8px range, keeping both visibly above ordinary body text on
small screens without overriding the theme's primary-title size.

The four defaults now have explicit use cases:

- **Standard:** neutral, theme-led output for most articles;
- **Editorial feature:** the Standard hierarchy with a restrained accent rail
  for long-form and Series features;
- **Inline:** short titles and compact Series entries, retaining available
  Series context above while the title and subtitle share one visual line;
- **Title only:** a compatibility fallback for ordinary posts that need no
  surrounding title data.

True title takeover only runs for a public, published, non-password-protected
queried singular post with actual Title Layer data. It leaves Query Loops,
archives, feeds, REST/JSON, embeds, admin/AJAX, unpublished content, menus and
document titles unchanged. If the slot cannot be verified or rendering is
disabled, the original theme title is returned unchanged. A filtered custom
preset must also preserve the verified title marker, inherited heading tag,
and theme title classes; otherwise takeover fails open to the original title.

The older `auto-prepend` mode remains available for compatibility. It is
limited to `h2` or a semantic `div`, yields to an explicitly placed block or
shortcode, and stays outside the same non-page channels. Do not use it while a
visible theme title remains enabled.

The compatibility functions `get_secondary_title()`,
`the_secondary_title()`, and `has_secondary_title()` remain available after the
old plugin is deactivated. Version 1.0.0-rc.3 still does not provide multiple-Series
relationships.

## SEO and sharing titles

Visible, SEO, and social-sharing titles remain separate channels. WP Title
Layer does not rewrite the document title, generate Open Graph tags, or write
provider-owned post metadata. When Rank Math is active it registers three
optional variables:

- `%wptl_subtitle%` — subtitle only;
- `%wptl_series%` — full Series name, including on a Series archive;
- `%wptl_title_with_subtitle%` — the native WordPress title plus the locale's
  title separator and the subtitle only when one exists, so no dangling
  separator is produced.

An administrator can use the combined variable in Rank Math's SEO title,
Facebook title, or Twitter title fields independently. A typical opt-in format
is `%wptl_title_with_subtitle% %sep% %sitename%`. Leaving Rank Math unchanged
keeps the site's current SEO and sharing titles. This preserves Rank Math's
global and per-post overrides rather than silently replacing them.

Open **WP Title Layer → Landing & Channels** to inspect those boundaries without
changing them. The report compares each Series with Category archives using
only public, published, non-password-protected articles. A Category is called
an exact duplicate entry point only when its complete eligible article set is
identical to the Series; partial overlap remains a factual shared-count report.
It never removes Category relationships or chooses a canonical for you.

When Rank Math is active, the same page reports whether Series taxonomy title,
description, robots, and sitemap behavior comes from an explicit setting or a
provider default. For a selected Series it also checks term-level search title,
description, canonical, robots, Facebook title, Twitter title, sitemap
exclusion, and aggregate article-level title overrides. Rank Math's absent
social overrides are labelled as inheriting the SEO title. If Rank Math is
inactive, stale Rank Math options are not treated as authoritative. Other or
multiple detected providers are identified as an ownership hand-off that must
be verified in their own interface and in rendered page source.

## Reader features

Reader output is conservative and independently configurable:

- **Theme archive layout:** the default and recommended choice when visual
  consistency with Category archives matters. The active theme renders the
  page. Ordered Series always use their canonical reading sequence; unordered
  Series honor the configured oldest/newest/title archive sort. On verified
  official Kadence 1.5.x templates, an independent opt-in can place each
  article Subtitle in the exact card-title-to-meta slot. Other themes and all
  Category, search, and home loops remain unchanged.
- **Structured Series layout:** an opt-in classic-theme layout for seasons,
  sequence labels, cover, description, and independently configurable
  article-subtitle, WordPress Excerpt, and featured-image fields. Excerpts and
  featured images are off by default and each Series may inherit, show, or hide
  them. Without a cover it uses the full desktop content width;
  with a cover it switches to a bounded two-column header. The plugin leaves
  block themes and any classic theme with a dedicated
  `taxonomy-wptl_series*.php` template in control.
  With advanced structure enabled, one public heading represents each Season
  while its role tracks remain merged in canonical order; Series-wide
  bookends use their Public structure labels rather than internal role names.
- **After-content navigation:** opt-in and available only for an ordered Series.
  It can show previous/next entries, a start link, and reading progress. An
  ordered Series with seasons chooses Entire Series or Current Season on its
  own edit screen; the default remains Entire Series.
- **Manual navigation:** `[wptl_series_navigation]` is enabled by default, so a
  site can place ordered-Series navigation without enabling automatic appending.

The global archive choices are defaults. A Series may individually inherit or
choose the theme/structured layout, inherit/show/hide article Subtitles, and
inherit/show/hide Excerpts and compact featured images in the structured
layout. Missing metadata always means inherit, so upgrading does not alter
existing archives. The structured paginator uses square controls, a solid
current page, arrow previous/next controls, an unboxed ellipsis, and no link
underline; screen-reader labels retain full Previous page / Next page wording.
If a Series has a parent Category, the structured archive header links back to
that Category without changing any article relationship.

Season labels in a title layer link to the same Series archive filtered by that
season. Reader navigation links inherit the theme color without an underline,
then underline on hover or keyboard focus.

Every Reader path includes only public, published, non-password-protected
articles. Previous, next, start, position, and total always share the same
selected collection. Unordered Series never invent a reading path.

Previous/next navigation currently loads and sorts the complete eligible Series
to find neighboring entries. Archives keep WordPress pagination and use the same
season/position/ID ordering in SQL. Very large Series may still need profiling
and a future indexed strategy before enabling navigation at scale.

The Series add screen starts with four season rows and can append more without
requiring an initial save. Its cover and title-context icon use the native
WordPress media library while retaining separate canonical attachment IDs;
without media JavaScript the numeric ID fields remain available. In the Posts
list, the optional **Subtitle** column can be shown or hidden through WordPress
Screen Options.

When the Classic Editor plugin or another filter disables Gutenberg for a
supported post type, WP Title Layer adds one nonce- and capability-protected
meta box for Subtitle, an existing single Series, season/order fields, kicker,
and template override. It never offers term creation there and does not appear
beside the Gutenberg document panel. A deliberately reused custom taxonomy
keeps its native Classic Editor relationship UI unless an integrator explicitly
opts WP Title Layer into ownership.

## Languages and punctuation

WP Title Layer uses the current WordPress locale throughout its PHP screens,
Classic Editor controls, block-editor sidebar, reports, and front-end labels.
The administration interface therefore follows each user's WordPress profile
language when WordPress supplies that locale; public output follows the locale
selected for the current front-end request. English remains the source and
fallback language, and a complete Simplified Chinese (`zh_CN`) translation is
included in the release.

The built-in separator choices are semantic rather than stored as a fixed
Chinese glyph. The same saved recipe renders as `: `, ` — `, or ` | ` in
English and as `：`, `——`, or `｜` in Simplified Chinese. A custom separator is
always rendered exactly as the administrator saved it. Switching languages
does not rewrite `wptl_settings`, post metadata, term metadata, or article
content.

The bundled `languages/wp-title-layer.pot` catalog leaves the normal WordPress
translation path open for additional languages. Text owned by WordPress, the
active theme, or another plugin is translated by that component's own language
pack; for example, a Kadence field label is not controlled by WP Title Layer's
Chinese catalog.

## Canonical data model

| Concern | Storage |
| --- | --- |
| Primary title | WordPress `post_title` |
| Subtitle | `wptl_subtitle` post meta |
| Series membership | `wptl_series` taxonomy |
| Series mode | `wptl_series_mode` term meta |
| Series lifecycle status | `wptl_series_status` term meta |
| Series structure | `wptl_series_structure` term meta |
| Reading navigation scope | `wptl_navigation_scope` term meta |
| Season definitions | `wptl_seasons` term meta |
| Entry season | `wptl_season_key` post meta |
| Legacy sequence position | `wptl_sequence_position` post meta; preserved after initialization |
| Managed canonical rank | private `wptl_sequence_rank` post meta |
| Sequence schema / revision | private Series term metadata |
| Public sequence label | `wptl_sequence_label` post meta |
| Series role | `wptl_series_role` post meta |
| Series/Season scope | `wptl_series_scope` post meta |
| Book-structure schema | private `wptl_book_structure_version` Series term meta |
| Template override | `wptl_template_override` post meta |
| Kicker override | `wptl_kicker_override` post meta |
| Series cover | `wptl_cover_id` term meta (attachment ID) |
| Series title-context icon | `wptl_icon_id` term meta (attachment ID) |
| Parent Category branch | `wptl_parent_category_id` term meta |
| Per-Series archive layout | `wptl_archive_layout` term meta |
| Per-Series archive Subtitle policy | `wptl_archive_subtitles` term meta |
| Per-Series archive Excerpt policy | `wptl_archive_excerpts` term meta |
| Per-Series archive featured-image policy | `wptl_archive_featured_images` term meta |
| Per-Series title-icon policy | `wptl_title_icon` term meta |

## External REST contract

The `1.0.0-rc.3` contract below is declared for external-client validation. A
publisher should resolve an existing Series by slug before it creates or
updates an article:

```text
GET /wp-json/wp/v2/wptl_series?slug=incarnational-communication
```

Use the returned term `id` in the article's taxonomy field. A representative
article request is:

```json
{
  "wptl_series": [123],
  "meta": {
    "wptl_subtitle": "An explanatory subtitle",
    "wptl_season_key": "season-2",
    "wptl_series_role": "article",
    "wptl_series_scope": "",
    "wptl_sequence_label": ""
  }
}
```

The rules are intentionally narrow:

- `wptl_subtitle` is canonical. `_secondary_title` is a read-only compatibility
  source; an explicitly stored empty canonical Subtitle remains authoritative.
- `wptl_series` accepts zero or one existing term ID. Article publishing must
  not create a Series or edit its mode, structure, seasons, status, or other
  definition metadata.
- A flat main article only needs its Series. A seasoned main article also needs
  a defined internal `wptl_season_key`. The public numeric Season `public_id`
  used in archive URLs is not an article meta value: read `meta.wptl_seasons`
  from the Series response and map that public ID to the matching internal
  `key` before writing the article.
- `wptl_series_role` may be empty or `article` for a main article, or `intro`,
  `epilogue`, or `appendix`. Non-main entries in a seasoned Series must choose
  `wptl_series_scope` as `series` or `season`; a season-scoped entry also needs
  a valid Season key. `wptl_sequence_label` is optional display text, never a
  rank.
- Omitting a field leaves its existing WordPress value unchanged. To clear or
  replace a Series safely, send `wptl_series: []` or the new term ID and also
  clear any season, role, scope, and label that no longer applies. WP Title
  Layer does not guess an external client's intended cleanup.
- For a managed ordered Series, omit `wptl_sequence_position`; WP Title Layer
  appends the saved article to the appropriate canonical track. An old ordered
  Series that has not been initialized returns `wptl_missing_sequence_position`
  for a published article. The normal remedy is to initialize that Series in
  Sequence Manager and retry. A legacy position should only be sent by an
  explicit compatibility workflow and must be a unique non-negative integer in
  its Series/Season.

External clients must never write `wptl_sequence_rank`,
`wptl_sequence_source_position`, sequence schema/revisions, the Season public-ID
high-water mark, or the book-structure marker. Those fields are private and are
not exposed for REST writes. `wptl_seasons` is readable for identity mapping but
REST writes are rejected so stable Season IDs remain owned by the Series editor.

## Development and tests

Install the JavaScript/Playground dependencies, then run:

```sh
npm install
npm run check
npm run test:wp
```

`npm run check` validates the editor, Sequence & Structure, localization catalogs,
control center, presentation, migration, Series-health, theme-adapter, and
channel-governance and release-version contracts in 95 checks. `npm run test:wp` parses 61 PHP files
and runs 828 integration checks plus 111 Sequence Manager, 65
book-structure, and 42 content-group checks at
both supported ends of the test matrix:

- WordPress 6.5 with PHP 7.4;
- WordPress 7.1 with PHP 8.3.

The RC workflow pins those exact WordPress archives through
`WPTL_MIN_WP_SOURCE` and `WPTL_CURRENT_WP_SOURCE`; the local scripts retain a
floating `latest.zip` default for ordinary forward-compatibility development.

The Playground command may download WordPress and therefore requires network
access on its first run. Coding-standard checks are available separately after
`composer install`:

```sh
composer lint
```

Build the release ZIP with:

```sh
./bin/build-release.sh 1.0.0-rc.3
./bin/test-release-package.sh wp-title-layer-1.0.0-rc.3.zip
```

The build exports the fixed `HEAD` commit (or an explicit
`WPTL_RELEASE_REF`) using the repository's release exclusions. It refuses to
build when the requested version differs from the archived plugin header,
`WPTL_VERSION`, `package.json`, or `readme.txt` Stable tag. Repeated builds of
the same commit are byte-identical. The second command verifies the extracted
package version and repeats the two-environment syntax and integration matrix
against that installed artifact.

The official Kadence 1.5.2 template smoke adds 40 checks for exact singular
title-slot structure, separate Series/season archive links, sequence placement,
decorative Series icons outside both native H1 slots, loop isolation, and opt-in
Series-archive card Subtitles without Category leakage.

The optional Rank Math channel is also exercised through the official plugin's
real replacement engine, including two-post cache isolation:

```sh
./bin/test-rank-math.sh /path/to/seo-by-rank-math.zip
```

The current Rank Math 1.0.276 smoke performs 32 checks, including its real
taxonomy options, term metadata, sitemap switches, and read-only report path.

Production packages exclude repository automation, tests, private design
history, third-party archives, and development dependencies.

## License and credits

GPL-2.0-or-later. See [LICENSE](LICENSE) for the full terms and
[CREDITS.md](CREDITS.md) for Secondary Title
compatibility attribution.
