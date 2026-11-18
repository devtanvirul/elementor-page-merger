# Elementor Page Merger

Merges an Elementor header, page and footer into one self-contained document, ready for Envato Template Kit export.

When a site is packaged as a Template Kit, each page template carries only the page content. The header and footer live in the Theme Builder, which does not travel with an individual page template, so a buyer who imports the About page gets the content with no header or footer around it.

This plugin creates a **new** Elementor Library document holding the header's elements, then the page's elements, then the footer's — the way a landing page works. The exported file is then complete on its own.

```
Before                              After (one single document)
──────                              ───────────────────────────
Header  (separate template)         ┌─ the header's sections
About   (page)            ──►       ├─ the About page's sections
Footer  (separate template)         └─ the footer's sections
```

It runs before [bulk-elementor-template-saver](https://github.com/devtanvirul/bulk-elementor-template-saver) and [bulk-template-kit-importer](https://github.com/devtanvirul/bulk-template-kit-importer).

## What it does

- Lists every Elementor-built document on the site (`_elementor_edit_mode` is `builder`), across **all** post types, with a filter by post type and a title search.
- One **Header** and one **Footer** picker that apply to every ticked row, plus per-row dropdowns that override them. Every dropdown starts empty — no header or footer is ever guessed.
- Saves the merged result into the Elementor Template Library, or exports it as JSON (one `.json`, or a `.zip` for several rows).
- Shows character, element and image counts for both the source and the result, and whether the write verified.

## Header and footer detection

Header and footer documents are found by shape, not by plugin name — no builder is named anywhere in the code, so a theme or add-on nobody has written yet is found on the same terms as the ones that exist today. A document is taken as a header or a footer when any of these says so:

1. the post type's own name contains `header` or `footer` (e.g. `jeg_kit_header`);
2. a short meta value says `header` or `footer`, either exactly or on a key that describes a type (e.g. `elementskit_template_type`);
3. a taxonomy term is named `header` or `footer` (e.g. Elementor's own `elementor_library_type`).

A value such as `no-header` or `hide-footer` is a layout switch on an ordinary page and is ignored. Anything the detector misses is still listed at the bottom of both dropdowns, so a document can always be picked by hand.

A **Detection** panel on the screen shows what was found and why.

## Images

A template carries no image files, only URLs, and the importer on the receiving site downloads from them. An image still pointing at a site that has moved or gone private arrives there as Elementor's grey placeholder.

The plugin gives those URLs the best chance:

- **Alias domains are normalised.** A site reached through more than one address stores whichever was in use at the time. A URL is rewritten to the canonical address only when the file is confirmed to sit in this site's own uploads folder.
- **Images hosted elsewhere are brought in.** They are downloaded into this site's Media Library and the merged copy is pointed at the local file, so the exported file only asks for images this site serves.
- **SVG is included.** WordPress does not accept SVG uploads on its own, and `media_sideload_image()` rejects it outright, which is how a set of icons goes missing without anything appearing to be wrong. Any SVG carrying script is refused and reported rather than stored.
- `wp_remote_get()` is used rather than `wp_safe_remote_get()`, which refuses any host that does not resolve from this server or that resolves to a private address — the case for two sites on one server, reported by WordPress as *"A valid URL was not provided"* before a request is even made.
- Downloaded images record where they came from, so the same image is never fetched twice, in this run or a later one.

Every failure is reported with its reason, grouped, next to the row. **Inspect** on any row shows the image count, which images are hosted elsewhere and whether they can actually be fetched, and every widget's own settings under their real names.

## Notes on the merge

- **A new document is always created.** The source page is never modified, apart from one meta value recording which merged document belongs to it. Running a row again updates that document rather than adding a duplicate.
- **Merging is top level array concatenation.** `_elementor_data` is a JSON array of top level elements, so the merged data is the header's elements, then the page's, then the footer's. Both `section` and `container` are handled.
- **Every element ID is regenerated, recursively.** Elementor requires element IDs to be unique within a document; a collision between the header and the page breaks the editor and the generated CSS in ways that are hard to trace.
- **The data is wrapped in `wp_slash()` before `update_post_meta()`.** That function unslashes what it is given, which strips a layer of backslashes out of Elementor's JSON: URLs come out shortened and any text holding a quote mark breaks the JSON outright, so the template opens as a blank canvas with no error anywhere. After writing, the value is read back and compared byte for byte, and the result is shown on screen.
- **The layout is set to Elementor Canvas**, on both the `_wp_page_template` meta and the `template` key inside the page settings, so the theme does not wrap its own header and footer around a page that already carries both.
- **Elementor's file cache is cleared once after the whole batch**, never per row. Per row, it rebuilds every CSS file on the site once per template, which on a batch of heavy templates pushes the request past PHP's time limit.
- **The JSON export runs Elementor's own export pass**, which drops attachment IDs that mean nothing on another site and leaves the URLs the importer downloads from. The library save does not, because that document lives on this site where those IDs still point at the right media.

## Things to know before you ship a kit

- **Sticky headers** come from the section's own Motion Effects, an Elementor Pro feature. The setting travels, but on a free install it is ignored.
- **Pro widgets** such as Nav Menu are unregistered on a free install: the data is there, but the frontend renders nothing in that spot.
- **Global colours and fonts** are preserved as `__globals__` references, which resolve against the active kit. Import the Global Kit first, or custom globals fall back to defaults.
- **A separate mobile header** is a separate document. Merging one means mobile gets the other.
- **Custom CSS targeting generated element IDs** stops matching, because the IDs are regenerated. IDs you typed yourself into Advanced → CSS ID are a different field and survive.
- **Canvas is load-bearing.** If a buyer switches the page back to the default template, they get two headers and two footers.

## Install

Download the repository as a ZIP and upload it under Plugins → Add New → Upload, or clone it into `wp-content/plugins/`. The admin screen is at **Page Merger**, its own top level menu — not under Elementor's, which rearranges depending on whether Pro is active.

## Tests

The merge, detection and image logic is covered by tests that stub WordPress, so they run anywhere PHP does, with no WordPress and no database:

```
php tests/test-merger.php
php tests/test-detection.php
php tests/test-images.php
php tests/test-export-pass.php
```

They are development files; WordPress ignores them.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).

---

Developed by [devtanvirul](https://devtanvirul.com)
