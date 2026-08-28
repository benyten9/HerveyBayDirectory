# Kadence troubleshooting — symptom → root cause → fix

Every row below is a bug that actually shipped and cost hours. Check here **before** reaching for CSS.

| Symptom | Root cause | Fix |
|---|---|---|
| `advancedheading` renders blank (styling applies, no text) | `content` is **source:html**; block was authored attribute-only | Put the text in the block markup (see `attribute-reference.md` §Content) |
| Hero image not dimmed; no `.kt-row-layout-overlay` in DOM | `overlayGradient` set but `currentOverlayTab` left at `'normal'` → `has_overlay()` false | `currentOverlayTab:"gradient"` + `overlayOpacity:100` |
| Overlay barely visible | `overlayOpacity` default is **30** | set `100`, bake alpha into gradient stops |
| Set a CSS `::before` overlay — nothing paints | `::before` does not paint over Kadence's row background | Use overlay **attributes**, not CSS |
| Styling attribute "doesn't work", tempted to use `!important` | Wrong attribute name (e.g. `size` instead of `fontSize`) — Kadence ignores unknown attrs | Verify in the registry; use the verified tables |
| Section renders `<div>` not `<section>` | `htmlTag` default is `'div'` | `htmlTag:"section"` |
| Button row centered, won't left-align | `advancedbtn` `hAlign` default is `'center'` | `hAlign:"left"` (not CSS) |
| Hero collapses short / content rides under the header | `minHeight` unset (default 0) | `minHeight` + `minHeightUnit:"vh"`; `verticalAlignment:"bottom"` |
| BEM class disappeared; block `className` became `kt-row-layout-overlay kadence-vertical-overlay` | A hand-written overlay `<div>` in innerHTML had its class parsed as the block's `className` on editor save | Never hand-write overlay divs; use overlay attributes; repair the `className` attr |
| `sb-quote--julia` became `sb-quoteu002du002djulia` | Editor round-trip escapes `-` | Avoid `--` in authored classes; repair with `str_replace(['u002du002d','u002d'],['--','-'],$c)` |
| `kadence/image` blank after recovery-save | its `save()` can return null | Use `core/image` |
| `kadence/icon` / `iconlist` empty headless | SVG/`<li>` are generated on editor save (client-side) | Run the recovery-save; or use the `kadence-dynamic-icon` span pattern for list icons |
| `parse_blocks` collapses a section to NULL | `kadence/column` serialized with empty attrs as `[]` | Emit `{}` — `empty($a) ? '{}' : wp_json_encode($a)` |
| ACF group shows **empty** in admin though `get_field()` returns data (content looks hardcoded) | repeater `acf-field` post has `post_parent = 0` instead of the group's post ID | Reparent + reset caches — see `acf-editable-content.md` |
| wp-admin redirects to `wp-login.php?reauth=1` despite a valid cookie | cookie not signed with a real session token | `WP_Session_Tokens::get_instance($uid)->create($exp)` → pass as 4th arg |
| Page CSS vanished after an editor save | you stored it somewhere Kadence regenerates | Put page CSS in `_kad_blocks_custom_css`; design in attributes |
| Styling changed but frontend stale | Kadence caches generated CSS | `wp_update_post` bumps `post_modified` (usually enough); verify on the frontend, clear cache if not |
| Image `srcset` 404s | intermediate thumbnail sizes missing | regenerate via `wp_generate_attachment_metadata` |
| `html-to-blocks` output duplicated the page | target `update` **appends**, it does not replace | delete the old top-level blocks after |
| `_preflight_token` rejected | single-use per persist | re-run `nibwp/skill-preflight` before each write |

## Diagnostic order

1. Is the attribute name real? (`WP_Block_Type_Registry`)
2. Is it the responsive variant you meant? (`fontSize` array vs `size` number)
3. Is a default overriding you? (`htmlTag`, `hAlign`, `overlayOpacity`, `currentOverlayTab`)
4. Is the field `source:html`? (content must be in markup)
5. Only then consider `_kad_blocks_custom_css`. Never Additional CSS for page styling.
