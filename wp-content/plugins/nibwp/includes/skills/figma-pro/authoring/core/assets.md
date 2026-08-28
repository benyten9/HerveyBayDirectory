# core/assets.md — asset pipeline (images, SVG/icons, fonts)

How every image, vector, and font referenced in a Figma design becomes **real
WordPress media / markup** — not a hotlink, not a third-party fetch. The parser
records assets on the NDO (`core/schema.md` §8); this doc is the shared pipeline
that turns those records into attachments and inline markup **before** any builder
adapter runs. Builder-specific reference swaps live in `builders/*.md`; this file
owns everything upstream of them.

NDO asset record (from `core/schema.md` §8):
```json
assets: [ { node_id, kind:"image"|"svg", src_export:"<figma export url or data>",
            local_path?, attachment_id?, alt?, optimize?:["webp"] } ]
```

## Table of contents
1. Pipeline overview
2. Where images come from — first-party sources
3. Image sideloading (URL → attachment)
4. Dedup — the source-key trick
5. Reference swap per builder
6. SVG / icons — prefer inline
7. Image optimization
8. Alt text / metadata
9. Fonts — detect, never bundle, flag
10. Rules & warnings

---

## 1. Pipeline overview

Run once, after the NDO exists and before adapters build. Each `assets[]` record
walks this path; results (`local_path`, `attachment_id`, or `inline`) are written
back onto the record so adapters read a resolved asset.

```
Figma export (PNG@2× / SVG)  ┐
user's own-site media URL     ├─►  fetch/decode ─► dedup lookup ─► HIT: reuse id
data: URI                    ┘                         │
                                                       └─ MISS:
   sideload (wp_upload_bits ─► wp_insert_attachment ─► wp_generate_attachment_metadata)
        │
        ├─ raster  ─► optimize (WebP, srcset sizes, w/h) ─► set alt/title/caption
        │              └─► write attachment_id back onto asset record
        └─ vector  ─► prefer INLINE SVG into markup (or sanitized SVG upload)
                       └─► write inline / attachment_id back onto asset record
                                        │
                                        ▼
                        adapters (builders/*.md) swap the reference
```

Every asset ends in exactly one of three states: **reused** (dedup hit),
**sideloaded** (new attachment), or **inlined** (SVG). A fourth — **failed** —
gets a warning and a placeholder so the build never hard-crashes (§10).

## 2. Where images come from — first-party sources

**NibWP keeps every asset first-party — no third-party image hosting.** Images
come from exactly two first-party sources:

| Source | How | When |
|---|---|---|
| **User's own Figma** | `nibwp/figma-export-node` renders the node as PNG at `scale=2` (SVG for vectors), streamed to a local temp path | Default — raster fills, exported frames |
| **User's own site** | an existing media-library URL already on the target install | Design references media the user already owns |

Data flows user's Figma → user's WordPress, or user's site → user's site. Nothing
round-trips through `nibwp.com` or any third party. **Call this out in the report**
(§10) — first-party media on the user's own site is a genuine trust advantage
worth surfacing to the user.

## 3. Image sideloading (URL → attachment)

**Never hotlink.** A `background-image:url(https://figma…)` or `<img src="figma…">`
rots the moment the export URL expires. Register every raster as a real attachment.

Pipeline (WordPress media APIs):

1. **Fetch** — for a URL, `wp_safe_remote_get()` (honors SSRF/host allowlist); for
   a Figma export already streamed to disk by `figma-export-node`, read the local
   file; for a `data:` URI, `base64_decode` the payload.
2. **Write the file** — `wp_upload_bits( $filename, null, $bytes )` drops the bytes
   into `uploads/` and returns `{ file, url }`.
3. **Insert the attachment** — `wp_insert_attachment()` with a post of type
   `attachment`, the resolved mime, and the file path.
4. **Generate metadata** — `require_once ABSPATH.'wp-admin/includes/image.php';`
   then `wp_generate_attachment_metadata()` → `wp_update_attachment_metadata()` to
   build the intermediate sizes + srcset data.

For a straight remote URL, `media_handle_sideload()` collapses steps 2–4, but call
the primitives directly when the source is a temp file or decoded bytes.

```php
// resolve bytes from any of the three source shapes
$bytes = str_starts_with( $src, 'data:' )
    ? base64_decode( substr( $src, strpos( $src, ',' ) + 1 ) )
    : ( is_file( $src ) ? file_get_contents( $src )
                        : wp_remote_retrieve_body( wp_safe_remote_get( $src, [ 'timeout' => 30 ] ) ) );
if ( ! $bytes ) { return new WP_Error( 'asset_fetch_failed', $src ); }

$up  = wp_upload_bits( $filename, null, $bytes );          // → uploads/
if ( $up['error'] ) { return new WP_Error( 'asset_write_failed', $up['error'] ); }

$id  = wp_insert_attachment( [
    'post_mime_type' => $up['type'],
    'post_title'     => $alt ?: pathinfo( $filename, PATHINFO_FILENAME ),
    'post_status'    => 'inherit',
], $up['file'] );

require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['file'] ) );
```

Note: WP-core / PHP-builtin calls above use **positional args only** — named args
fatal on the PHP 8 runtime (release-preflight rule).

## 4. Dedup — the source-key trick

**Make sideloading idempotent** so re-importing a design (or two nodes sharing one
image) never duplicates media.

- Compute a stable key from the source: `sha1( $source_url_or_hash )`.
- Store it as post-meta on every sideloaded attachment under
  **`_nibwp_figma_source_key`**.
- **Before** sideloading, look the key up; on a hit, reuse that attachment id and
  skip the fetch entirely.

```php
define( 'NIBWP_FIGMA_SOURCE_KEY', '_nibwp_figma_source_key' );

function nibwp_figma_find_asset( $source ) {
    $key = sha1( $source );
    $hit = get_posts( [
        'post_type'   => 'attachment',
        'post_status' => 'inherit',
        'numberposts' => 1,
        'fields'      => 'ids',
        'meta_key'    => NIBWP_FIGMA_SOURCE_KEY,
        'meta_value'  => $key,
    ] );
    return $hit ? (int) $hit[0] : 0;
}
// after a successful sideload:
update_post_meta( $id, NIBWP_FIGMA_SOURCE_KEY, sha1( $source ) );
```

Key the hash on the **content identity**, not a volatile export URL: prefer the
Figma `imageRef` / node-hash, or a sha1 of the decoded bytes, so the same image
re-exported at a fresh signed URL still resolves to one attachment. Re-running a
whole import then adds **zero** duplicate media.

## 5. Reference swap per builder

Sideloading yields an `attachment_id` (+ its URL); inlining yields SVG markup. The
**adapter** then rewrites the design's placeholder reference to the real target.
The mechanism differs per builder — keep it out of this pipeline doc and defer to
the builder reference:

| Builder | Swap mechanism | Ref |
|---|---|---|
| Elementor | attachment `{id,url}` on the Image widget control; `on_import` id remap on template import | `builders/elementor.md` |
| Bricks | `import_images` + URL string-replace to the new media URL | `builders/bricks.md` |
| Gutenberg | `core/image` with `id`+`url`; block/group `background-image` for fills | `builders/gutenberg.md` |
| Etch | `etch/element` img (sideloaded attachment) / element `background` | `builders/etchwp.md` |

This doc guarantees the adapter receives a **resolved** asset (id/URL or inline
SVG) on every `assets[]` record. How each builder consumes it is that builder's
concern.

## 6. SVG / icons — prefer inline

Vector nodes (icons, logos, simple shapes) export from Figma as SVG. Two paths;
**prefer inlining**:

**Inline (default, fidelity win).** Drop the `<svg>…</svg>` straight into the
markup. Crisper (no raster), smaller (no HTTP round-trip / no attachment row),
and **themeable** — `fill:currentColor` inherits the token color, so one icon
recolors with the surrounding text token (an uploaded SVG file loses that
inheritance). Store as `{ kind:"svg", inline:"<svg…>" }` on the NDO; adapters emit
it verbatim (Gutenberg `core/html`, Etch/Bricks raw element).

**Upload (when a file is genuinely needed** — a bespoke logo/illustration reused
across pages, or a builder control that only accepts a media id):

- WordPress blocks `image/svg+xml` uploads by default. Enable it **only for users
  with the import capability**, by adding the mime to the allowed set:
  ```php
  add_filter( 'upload_mimes', function ( $m ) {
      if ( current_user_can( 'nibwp_import' ) ) { $m['svg'] = 'image/svg+xml'; }
      return $m;
  } );
  ```
- **Sanitize on upload** — SVG is executable XML; strip `<script>`, `on*`
  handlers, external entities, and `href`/`xlink:href` javascript before the file
  lands (run through a sanitizer on the `wp_handle_upload_prefilter` hook). Never
  store unsanitized SVG.
- Then sideload exactly as §3 (dedup applies too).

Decision: icon/logo/simple vector → **inline**. Reused bespoke asset or
control-requires-id → sanitized SVG upload. Everything raster → §3.

## 7. Image optimization

Optional, applied to sideloaded rasters:

| Concern | Action |
|---|---|
| Oversized source | downscale huge exports (a 2× hero can arrive multiple MB); flag when downscaled (§10) |
| Format | convert large PNG/JPEG to **WebP** when `optimize:["webp"]` is set and the server has GD/Imagick WebP support; keep the original as a fallback size |
| Responsive | WP's `wp_generate_attachment_metadata()` already produces intermediate sizes → `srcset`/`sizes` emitted automatically; ensure registered sizes cover the design's breakpoints |
| CLS | always emit `width`/`height` (or aspect-ratio) from the attachment metadata so images reserve space and don't shift layout |

Optimization never blocks the build — on a failed WebP encode, keep the original
and continue.

## 8. Alt text / metadata

Accessibility win — derive real alt text instead of leaving it blank:

- **Alt** — from the Figma layer name (`Hero — product dashboard` → "product
  dashboard"), or nearby text content (a caption/figure label), cleaned of Figma
  cruft (trailing `/`, `Frame 123`, size suffixes).
- Set the attachment **title** (layer name), **alt** (`_wp_attachment_image_alt`
  meta), and **caption** when the design supplies one.
- Carry `alt` on the NDO asset record so the adapter puts it on the emitted
  `<img>` / widget too — not just the media library row.
- When no meaningful name exists, leave alt empty (correct for decorative images)
  rather than inventing text.

## 9. Fonts — detect, never bundle, flag

**NibWP does not bundle or ship font files.** Licensing and weight/subset variance
make silent bundling wrong.

- **Detect** the font families used in the type ramp (`core/tokens.md` §6 — the
  ramp already carries `family` per slot).
- **Check** whether the target site already serves each family (theme font,
  ACSS font, enqueued Google Font, uploaded @font-face).
- If **present**, use it — the ramp references it and the type matches.
- If **missing**, **flag it for the user** with the concrete fix: add via Google
  Fonts, an ACSS font, or the theme's font manager. **Never silently substitute** a
  fallback and call it done — the type will not match the design until the real
  family is available, and a quiet swap hides that from the user.
- Emit the ramp against the intended family regardless, so the moment the user
  installs it, the type snaps into place.

Cross-reference `core/tokens.md` §9 (assets as tokens) — fonts and images are the
two token-adjacent assets; this file owns the image/SVG mechanics, tokens.md owns
how the family name enters the ramp.

## 10. Rules & warnings

Surface these to the user-facing report (they flow through the NDO `meta.warnings`,
`core/schema.md` §10):

- ✅ **Images come from the user's Figma / own site — first-party media, no
  third-party image hosting.** State it explicitly; it is a trust advantage.
- ✅ Every raster is a **real attachment** (sideloaded) — never hotlinked.
- ✅ **Idempotent** — `_nibwp_figma_source_key` dedup means re-imports add no
  duplicate media.
- ✅ Icons/logos **inline as SVG** (crisper, themeable) over uploaded files.
- ✅ Alt/title/caption derived for accessibility.
- ⚠ **Any asset that failed to sideload** — report it, drop a visible placeholder,
  keep building (never hard-crash the import).
- ⚠ **Fonts missing on the site** — list each family + the fix; the type won't
  match until added. Never silently substitute.
- ⚠ **Huge images downscaled / converted to WebP** — note the change so the user
  isn't surprised the bytes differ from the export.
- ❌ Don't hotlink an export URL. ❌ Don't upload unsanitized SVG. ❌ Don't bundle
  font files. ❌ Don't route any asset through a third-party cloud.
