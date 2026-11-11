# WordPress.org submission preparation

Date: 2026-11-11. Candidate: 1.0.1. Directory account: `irisable`.

This is a submission candidate, not an assertion of approval or availability in
the plugin directory. Published GitHub 1.0.0 remains unchanged. Production is
not redeployed as part of this preparation.

## Submission attempt outcome

The owner explicitly authorized the directory declarations and upload. The
upload endpoint rejected the name **WP Title Layer**, stating that `wp` cannot
begin the plugin permalink or display name. There is no accepted submission or
review-queue acknowledgement. This live validation overrides the more permissive
comment in Plugin Check's source. No automatic rename or repeated upload was
attempted; owner choice is required before changing the public identity/slug.

Candidate commit: `4d436c8`. ZIP SHA-256:
`c00afbc20033dabdb3b8c33dbb16ed0f011c87c41e36fd9346567cfb31b9a901`.
Final official static result: **0 errors, 128 reviewed warnings**. All 99
JavaScript/contracts passed. Both source and exact packaged-payload matrices
passed on WordPress 6.5/PHP 7.4 and current WordPress/PHP 8.3: 61 PHP files,
828 integration, 111 sequence, 67 book structure, 57 content group and 46
Publisher checks per environment.

## Distribution changes

- Remove `Update URI: false` so directory-distributed installations can use
  native WordPress.org updates after approval and SVN publication.
- Correct the readme contributor to `irisable`.
- Add privacy, external-service, readable-source and compatibility-credit notes.
- Add missing translator comments. Rank Math's literal `%...%` tokens are not
  printf placeholders and retain their existing translations.
- Escape the fixed h3/h4 archive heading name at output and remove the obsolete
  second `term_description()` argument.
- Sanitize/unslash the migration request-method string and rollback confirmation.
- Document narrowly scoped static-analysis false positives for prepared SQL
  and streaming CSV downloads. No database schema or content migration.

## Official Plugin Check

Tool: official WordPress.org Plugin Check 2.2.0, downloaded from
https://downloads.wordpress.org/plugin/plugin-check.2.2.0.zip

Run in an isolated current-WordPress/PHP 8.3 Playground against the installable
payload, not the repository's tests or development tools. All 31 default static
checks are selected through the tool's `CLI_Runner`; AI analysis is disabled.
This is not a claim that Plugin Check runtime checks ran. Runtime behavior is
covered separately by this project's WordPress integration matrix.

Initial 1.0.0 result: 29 errors and 133 warnings. Candidate results and exact ZIP
hash are recorded in the local submission evidence under `build/wporg-preflight/`.

### Reviewed remaining warnings

These warnings remain visible rather than being globally suppressed. Counts
refer to diagnostic locations, not distinct defects. They need to be disclosed
to a reviewer if requested; the review team can require additional changes.

| Warning family | Review and disposition |
| --- | --- |
| Nonce verification in read-only GET reports / editor context | Health filters, pagination, notice text, and editor post selection do not perform a content mutation. Administrative reports retain capability gates; values are sanitized and output is escaped. |
| Nonce verification in migration / sequence POST helpers | `AdminPage::authorize()`, `AjaxController::authorize()`, `SequenceManager::verify_action()` and `verify_ajax()` perform capability and action-specific nonce checks before mutation. Static analysis does not follow these helpers. |
| InputNotSanitized on composite form input | Classic Editor and Series term forms sanitize each recognized field with its specific sanitizer before persistence; indiscriminate sanitization of the entire structured payload would change intended values. |
| InputNotSanitized on run IDs / health IDs | `Config::clean_run_id()` applies the run-ID allowlist. ChannelHealth validates scalar values then applies `absint()` before use. |
| Interpolated SQL / unfinished prepare | Variable IN clauses contain only generated `%s` placeholders. Values go through `$wpdb->prepare()`. Book-track role/scope expressions are fixed source strings; SQL aliases are fixed internal arguments additionally restricted to ASCII letters, digits and underscores. |
| Direct queries / no caching | Migration scans need current data, metadata row IDs and duplicate detection. Atomic compare-and-swap/delete operations protect concurrent author edits and invalidate the relevant WordPress cache. Source ACF key lookup is memoized per resolver. These are intentionally not replaced by stale cached counts or non-atomic read/write operations. |
| tax_query / meta_query / post__not_in | Required for scoped Series/Season/group membership, sequence management and adjacent navigation. These generic potential-performance warnings do not identify a broken query; retain existing pagination/batching and integration coverage. |
| Unprefixed functions / hooks | Guarded Secondary Title compatibility functions and legacy hooks preserve existing integrations. Rollback emits WordPress core metadata hooks for its guarded atomic deletion. New plugin-owned interfaces use the plugin prefix or namespace. |
| load_plugin_textdomain | Keep bundled Simplified Chinese working for existing manual/GitHub installations too; directory language packs can still be used. |
| CREDITS.md | Intentionally retain human-readable third-party review provenance and attribution; no third-party ZIP or development fixture is distributed. |
| `wp` trademark warning | Plugin Check's `Trademarks_Check::TRADEMARK_SLUGS` says `wp` is allowed but warns; however, the live upload endpoint rejected the prefix. This warning is now an unresolved submission blocker, pending the owner's naming decision. |

No telemetry, tracking cookies, outbound content submission, remote executable
download, license gate, trial limit, or external account requirement was found
in the plugin. Optional ACF, Secondary Title, Rank Math and theme integrations
operate on installed local software/data. Repository and author links are
ordinary user-initiated links, not background services.

## Submission sequence

1. Finish candidate regression tests and check the exact final ZIP.
2. Have the owner confirm the submission declarations and directory conditions.
3. Upload the candidate ZIP; retain the acknowledgement and requested slug.
4. Respond to review feedback in the same review email thread.
5. After approval, publish the reviewed source to assigned SVN trunk/tag;
   verify the generated download, readme and public directory page.

Approval does not itself mean the downloadable directory release is live.
Do not overwrite the already published GitHub 1.0.0 archive or tag.

Official references:
- https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/
- https://wordpress.org/plugins/plugin-check/
- https://wordpress.org/plugins/developers/add/
