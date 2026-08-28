# Kadence block attribute reference (verified against the block registry)

Every value below was read from `WP_Block_Type_Registry` on a live Kadence Blocks Pro install. **Do not guess attribute names** — a wrong name is silently ignored and renders nothing. Re-verify on any site:

```php
$bt = WP_Block_Type_Registry::get_instance()->get_registered('kadence/advancedheading');
foreach ($bt->attributes as $k => $a) {
  echo $k.' : '.($a['type'] ?? '?').(isset($a['source']) ? ' /'.$a['source'] : '')
     .' | def='.var_export($a['default'] ?? null, true)."\n";
}
```

---

## Defaults that bite

| Block | Attribute | Default | Why it matters |
|---|---|---|---|
| rowlayout | `htmlTag` | `'div'` | Must set `'section'` for a real `<section>` |
| rowlayout | `currentOverlayTab` | `'normal'` | **`overlayGradient` is ignored unless this is `'gradient'`** |
| rowlayout | `overlayOpacity` | `30` | Leave it and your overlay is barely visible — set `100` and bake alpha into the gradient stops |
| rowlayout | `overlayBlendMode` | `'none'` | Leave as none/normal; `multiply` wrecks midtones |
| rowlayout | `minHeight` | `0` | No height until set; pair with `minHeightUnit` |
| advancedbtn | `hAlign` | `'center'` | This is why button rows are centered by default |
| singlebtn | `inheritStyles` | `'fill'` | Falls back to the global fill color |

---

## kadence/advancedheading (all text)

| Attribute | Type | Default | Notes |
|---|---|---|---|
| `content` | string **/html** | NULL | **source:html** — must be in the block markup, not JSON |
| `color` | string | NULL | hex or `palette{n}` |
| `typography` | string | `''` | font-family name |
| `fontWeight` | string | `''` | e.g. `"400"` |
| `fontStyle` | string | `'normal'` | `"italic"` |
| `fontSize` | **array** | `["","",""]` | **[desktop, tablet, mobile]** — the responsive one |
| `size` | **number** | NULL | ⚠️ legacy scalar. Setting this instead of `fontSize` is the classic silent mistake |
| `sizeType` | string | `'px'` | unit for fontSize |
| `lineHeight` | **number** | NULL | **not an array** |
| `mobileLineHeight` | number | NULL | responsive line-height (there is **no** `tabletLineHeight`) |
| `lineType` | string | `'px'` | use `''`/`'-'` for unitless |
| `letterSpacing` | **number** | NULL | not an array |
| `letterSpacingType` | string | `'px'` | |
| `textTransform` | string | `''` | |
| `htmlTag` | string | `'heading'` | `'heading'`,`'p'`,`'div'`,`'span'` |
| `level` | number | `2` | h1–h6 when tag is heading |
| `align` | string | NULL | |
| `margin` | **array** | `["","","",""]` | 4 values (t,r,b,l) |
| `marginType` | string | `'px'` | |
| `maxWidth` | **array** | `["","",""]` | ⚠️ array here, but a **number** on rowlayout |
| `link` | string | NULL | wraps content in `<a>` |
| `anchor`, `markSize` | — | | |

**Google fonts:** set `googleFont:false` + `loadGoogleFont:false` for self-hosted families, or Kadence emits a broken `fonts.googleapis.com` request.

## kadence/rowlayout (a section)

| Attribute | Type | Default | Notes |
|---|---|---|---|
| `htmlTag` | string | `'div'` | set `'section'` |
| `columns` | number | `2` | |
| `bgColor` | string | `''` | |
| `bgImg` | string | `''` | URL |
| `bgImgID` | number | `''` | attachment id |
| `overlay` | **string** | `''` | ⚠️ a **color string**, not an array/object |
| `currentOverlayTab` | string | `'normal'` | set `'gradient'` to use `overlayGradient` |
| `overlayGradient` | string | `''` | full `linear-gradient(...)` string |
| `overlayOpacity` | number | `30` | set `100` |
| `overlayBlendMode` | string | `'none'` | |
| `minHeight` | number | `0` | |
| `minHeightUnit` | string | `'px'` | use `'vh'` |
| `minHeightTablet` | number | `''` | exists (no need to invent it) |
| `maxWidth` | **number** | `''` | ⚠️ number here |
| `padding` | array | `["sm","","sm",""]` | supports size keywords |
| `paddingUnit` | string | `'px'` | |
| `verticalAlignment` | string | `'top'` | `'middle'`/`'bottom'` — needed once a hero is tall |

Renders overlay child `.kt-row-layout-overlay` **only** when `has_overlay()` is true, which switches on `currentOverlayTab`.

## kadence/advancedbtn + kadence/singlebtn

| Block | Attribute | Type | Default |
|---|---|---|---|
| advancedbtn | `hAlign` / `thAlign` / `mhAlign` | string | `'center'` / `''` / `''` |
| singlebtn | `text` | string | `''` — plain attribute, renders headless-safe |
| singlebtn | `link` | string | `''` |
| singlebtn | `inheritStyles` | string | `'fill'` |

## Content fields that are source:html (must be in markup)

| Block | Field |
|---|---|
| `advancedheading` | `content` |
| `infobox` | `title`, `contentText`, `number` |
| `listitem` | `text` |

Emit an advancedheading with content like this:

```html
<!-- wp:kadence/advancedheading {"uniqueID":"abc","htmlTag":"h1","fontSize":[56,40,33],"sizeType":"px","typography":"Erupha","color":"#F4F1EB","lineHeight":1.08,"lineType":"","letterSpacing":0.4} -->
<h1 class="kt-adv-headingabc wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingabc">Your text</h1>
<!-- /wp:kadence/advancedheading -->
```

Everything else (colors, sizes, overlay, icon name, button text, image id) is JSON — don't hand-write its markup; let render / the recovery-save produce it.

## Generated selectors (for the rare CSS you're allowed)

- rowlayout → `.kt-row-layout-id{uid}`, wrapper `.kt-row-column-wrap`, overlay `.kt-row-layout-overlay`
- column → `.kadence-column{uid}`, inner `.kt-inside-inner-col`
- advancedheading → `.kt-adv-heading{uid}`
- singlebtn → `.kb-btn{uid}` inside `.kb-buttons-wrap`
- posts loop → `.kb-posts-id-{uid}`, entries `.entry.loop-entry.post-{id}.category-{slug}`, `.post-thumbnail.kadence-thumbnail-ratio-{r}`, `.entry-title`, `.entry-summary`, `.entry-taxonomies`, `.entry-meta`

`className` renders on: rowlayout, advancedheading, iconlist, core/image. **Not** on: column, singlebtn, advancedbtn, nav wrapper.

## PHP pattern for editing attributes

```php
$blocks = parse_blocks( get_post( $id )->post_content );
$walk = function ( &$bs ) use ( &$walk ) {
    foreach ( $bs as &$b ) {
        if ( 'kadence/rowlayout' === $b['blockName'] && ( $b['attrs']['className'] ?? '' ) === 'sb-hero' ) {
            $b['attrs']['htmlTag']           = 'section';
            $b['attrs']['currentOverlayTab'] = 'gradient';   // REQUIRED for gradient
            $b['attrs']['overlayGradient']   = 'linear-gradient(to top,rgba(2,2,2,.85) 0%,rgba(18,14,9,.5) 50%,rgba(3,3,3,.7) 100%)';
            $b['attrs']['overlayOpacity']    = 100;
            $b['attrs']['minHeight']         = 84;
            $b['attrs']['minHeightUnit']     = 'vh';
        }
        if ( ! empty( $b['innerBlocks'] ) ) { $walk( $b['innerBlocks'] ); }
    }
};
$walk( $blocks );
$out = ''; foreach ( $blocks as $b ) { $out .= serialize_block( $b ); }
wp_update_post( [ 'ID' => $id, 'post_content' => $out ] );
```

Gotcha: an empty column attrs array serialises to `[]` and **breaks `parse_blocks`** — emit `{}` instead (`empty($a) ? '{}' : wp_json_encode($a)`).
