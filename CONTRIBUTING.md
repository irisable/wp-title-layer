# Contributing

Thank you for helping improve WP Title Layer.

Before starting a substantial change, open an issue that describes the user
problem and the compatibility impact. The 1.0 release line keeps the existing
Primary Title, Subtitle, Series, Season, Sequence, scope/role, and template
contracts stable; new data models should be discussed separately from bug
fixes.

## Local checks

WP Title Layer supports WordPress 6.5 or later and PHP 7.4 or later. Node.js 20
or later is required for the repository checks.

```sh
npm ci
npm run check
npm run test:wp
```

Release work must additionally build from a fixed commit and test the extracted
ZIP rather than only the source tree. See the release section in `README.md`.

## Pull requests

- Keep changes focused and preserve existing metadata by default.
- Add or update automated checks for behavioral changes.
- Keep user-facing strings translatable with the `wp-title-layer` text domain.
- Do not commit site credentials, private URLs, production exports, third-party
  plugin archives, `node_modules`, `vendor`, or generated release ZIPs.
- Describe manual checks and label anything not exercised as untested.

Contributions are licensed under GPL-2.0-or-later, the same license as the
project.
