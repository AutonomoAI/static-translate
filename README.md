# Static Translate

Generate translated static HTML from English HTML and pre-translated JSON
catalogs. Write English pages normally, fill the catalogs' `target` values, and
build one static page per language. The browser needs no translation code.

Requires **PHP CLI 8.4+ with the DOM extension**. `build.sh` also needs Bash;
you can invoke `tools/translate.php` directly instead. No Composer, npm,
jQuery, translation API, or application server is required.

## Clone into an existing project

From your website's repository root:

```bash
git clone <utility-repository-url> tools/static-translate
```

The URL is the location where you host this utility repository. A local Git
repository path works too. Ignore `/tools/static-translate/` in the host's
`.gitignore`, or manage the utility as a Git submodule if you want to pin it.
Keep your site's HTML and catalogs in the **host repository**, outside the
utility checkout:

```text
my-website/
  public/
    index.html                    English source
    ar/index.html                 Generated Arabic page
  translations/
    ar.json                       Your Arabic catalog
  tools/static-translate/         This utility's checkout
```

Build from the host root:

```bash
./tools/static-translate/build.sh       # Every translations/*.json catalog
./tools/static-translate/build.sh ar    # One locale
./tools/static-translate/build.sh ar es # Selected locales
```

Deploy your web directory to any static host. PHP runs only when building.

## Create a catalog

Keep your English HTML as it is. For example, `public/index.html` may contain:

```html
<h1>Welcome</h1>
<input placeholder="Search">
```

Create `translations/ar.json`:

```json
{
  "locale": "ar",
  "direction": "rtl",
  "pages": [
    {
      "source": "public/index.html",
      "output": "public/ar/index.html",
      "replacements": [
        {
          "selector": "h1",
          "source": "Welcome",
          "target": "مرحبًا"
        },
        {
          "selector": "input",
          "attribute": "placeholder",
          "source": "Search",
          "target": "ابحث"
        }
      ]
    }
  ]
}
```

- `locale` identifies the language; `direction` is `ltr` or `rtl`.
- Each page's `source` and `output` paths are relative to the host root.
- Each CSS selector must match exactly one element in that page.
- Without `attribute`, `source` must equal one descendant text node after
  trimming surrounding whitespace. Inline elements and surrounding whitespace
  are preserved. A sentence split by an inline element needs separate entries
  for its text nodes.
- With `attribute`, `source` must equal the element's current attribute value.
  This works for descriptions, accessibility labels, titles, and links too.
- `target` is plain text, not HTML. The DOM serializer handles escaping.
  Enter `&` rather than manually encoding it as `&amp;`.

Add more objects to `pages` to translate additional pages. Output paths must be
under `public/<locale>/` by default. The generator sets the document's `lang`
and `dir` and marks the output as generated. Never edit generated pages directly.

## Translators and LLMs

The format is unchanged from UNICON: no message IDs or new template syntax.
Prepare selectors and English `source` values for your page, leaving `target`
as `""`. A small blank example lives at
[`examples/site/translations.template.json`](examples/site/translations.template.json).
Adapt its locale, direction, page paths, and replacements before using it.

Give the translator or model the **entire English page and its blank catalog**.
Ask it to fill only `target` values and preserve every other field. Keep any
language-specific terminology or style instructions in your existing prompt.
Save the completed catalog as `translations/<locale>.json`, then build it.
Empty targets intentionally fail the build; they do not fall back to English.

Source changes that invalidate existing entries fail with the catalog, page,
and replacement location. New English text without a catalog entry is left
unchanged, so update the catalogs when adding content. Successful generation
checks matching, not linguistic quality or complete translation coverage.

Add the site's language picker, `hreflang` links, and localized URL metadata
yourself. This utility does not invent navigation, rewrite every internal link,
or choose a visitor's language. Translate existing attributes through catalog
entries where appropriate. RTL layout styling remains part of your site's CSS.

## Other layouts and working directories

| Option | Default | Meaning |
| --- | --- | --- |
| `--root PATH` | Current working directory | Host project root; may be absolute or relative |
| `--catalog-dir PATH` | `translations` | Catalog directory relative to the host root |
| `--web-root PATH` | `public` | Allowed web output root relative to the host root; `.` is supported |
| `--help`, `-h` | | Usage information |

The utility's location does not determine the host root. From any directory:

```bash
/path/to/static-translate/build.sh --root /path/to/my-website ar
```

For a site with `index.html` at its repository root and catalogs in `locales/`:

```bash
./tools/static-translate/build.sh --catalog-dir locales --web-root . ar
```

That site's catalog must explicitly use `"source": "index.html"` and
`"output": "ar/index.html"`. Options do not rewrite catalog paths. Relative
paths must stay within the host layout; absolute paths and `..` in page paths
are rejected. Locale names use two or three lowercase letters, optionally
followed by an uppercase country code, such as `ar`, `bn`, `ta`, or `pt-BR`.

Each page is written using a temporary file and rename after its replacements
validate. A later failure does not roll back earlier pages in the same build.

## Try the example and run tests

From this utility's checkout:

```bash
./build.sh --root examples/site
php tests/run.php
```

The example builds Spanish and Arabic pages under `examples/site/public/`;
generated example directories are ignored by Git. The tests use temporary
projects and require no test framework.

## Origin and license

Extracted from [UNICON](https://github.com/hopeseekr/unicon.church), preserving
its translation catalog format and HTML replacement engine. The original
license and attribution files are included unchanged:
[`LICENSE.txt`](LICENSE.txt), [`LICENSE.cc_by.txt`](LICENSE.cc_by.txt), and
[`LICENSE.ossal.txt`](LICENSE.ossal.txt).
