# WP Title Layer translations

`wp-title-layer` is the plugin text domain and `/languages` is its bundled
domain path.

The release includes:

- `wp-title-layer.pot`: source catalog for translators;
- `wp-title-layer-zh_CN.po`: editable Simplified Chinese catalog;
- `wp-title-layer-zh_CN.mo`: compiled PHP/front-end catalog;
- `wp-title-layer-zh_CN-wptl-editor.json`: Jed catalog for the Gutenberg
  script handle `wptl-editor`.

English source strings are the fallback for locales without a catalog. New
translations should keep the same text domain, preserve every printf
placeholder, compile the PO file to MO, and generate a Jed JSON file for each
translated JavaScript handle.

After updating the PO catalog, rebuild the bundled Gutenberg catalog with:

```sh
npm run build:i18n-json
```

The builder reads the editor source keys and the compiled PO translations,
then writes the locale/handle-specific Jed JSON without copying unrelated PHP
screen strings into the browser payload.

The built-in separator choices are intentionally translated at render time.
Do not replace their semantic stored values (`colon`, `dash`, and `pipe`) with
locale-specific glyphs. Custom separators are user content and must not be
translated.
