# WP Title Layer 1.0.0-rc.5

Compatibility hotfix for official Kadence 1.6.0. The old 1.5.x-only gate caused
the effective display mode to fall back to manual even when the saved setting
was still `replace-theme-title`. This release admits the verified 1.6.0 version,
retains template-signature and child-title-override checks, and rejects unknown
future releases and prereleases. No database or content migration is involved.

Validation before deployment:

- JavaScript/contracts: 97 passed, including executable PHP version/guard probes.
- Official Kadence 1.6.0 + isolated WordPress/PHP 8.3: 51 passed, covering both
  title positions, Series/season/number, whole-Series preface label, subtitle,
  single H1, unchanged stored settings, archive cards and their opt-in policy.
- WordPress 6.5/PHP 7.4 and current WordPress/PHP 8.3: each passes PHP syntax
  (61 files), integration (828), sequence (111), book structure (67), content
  groups (57), Publisher contract (46).
- Official-template test is now included in CI.

Rollback: retain the installed plugin directory and `wptl_settings` before
replacement. The unchanged rc.4 ZIP is also available as a code rollback, but
rc.4 does not restore title integration while Kadence remains at 1.6.0.

Deploy only this plugin; do not run the full primary-to-standby content sync.
Keep standby login restrictions and firewall policy unchanged. Check the raw
stored display mode before touching settings; do not infer it from the fallback
selection in the old UI. Invalidate presentation caches after deployment.
