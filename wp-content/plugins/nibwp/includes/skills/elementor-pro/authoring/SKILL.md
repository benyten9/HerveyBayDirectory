# Elementor Pro — HTML / URL / image → native Elementor page

Turn HTML, a URL, or a screenshot into a **native, editable Elementor page** — flexbox **containers** first, then real **widgets**, styled with **controls** (not custom CSS). Every widget type and control id is checked against the **live Elementor registry on this site**, so nothing is invented. The page is persisted the way Elementor actually needs it, so it renders immediately.

## The one law
> **Build a tree of real Elementor elements (containers + widgets). Style with real control ids. Never output raw HTML for something Elementor has a widget for.**

Elementor is a structured page builder, not an HTML dumper. A wrong `widgetType` or a wrong control id is **silently ignored** and renders nothing — so you confirm both against the live registry before authoring.

## When to use
- "Rebuild this in Elementor / convert this to Elementor."
- Turning HTML, a URL, or a screenshot into a maintainable Elementor page.
- Fixing an Elementor page that renders blank (corrupted `_elementor_data`).

## Architecture (what you're building)
Elementor stores a recursive JSON tree in the `_elementor_data` post meta. Every element:
```json
{ "id": "a1b2c3d", "elType": "container|widget", "settings": {}, "elements": [] }
```
- **Container** (`elType:"container"`) = the modern flexbox layout box. Sections and columns both become **nested containers**. Controls: `flex_direction` (row/column), `flex_justify_content`, `flex_align_items`, `flex_gap`, `content_width` (boxed/full), `padding`, `margin`, `background_background`(classic)+`background_color`/`background_image`, `border_*`, `box_shadow_*`, `min_height`.
- **Widget** (`elType:"widget"` + `widgetType`) = content. heading, text-editor, image, button, icon, icon-box, image-box, video, tabs, accordion, divider, spacer… (+ Pro/add-on widgets **only if present**).
- **ids** are minted for you — never reuse one.
- **Legacy** `section`/`column` exist only for editing old pages. For new builds use containers.

## Process
1. **Preflight.** `nibwp/skill-preflight { skill_id:"elementor-pro" }` — answer target mode (new_page/new_post/update), title, template (elementor_canvas / elementor_header_footer / default). Mints `_preflight_token`.
2. **Load this playbook.** `nibwp/load-skill-playbook { skill_id:"elementor-pro" }`.
3. **Read the live registry — never skip:**
   - `nibwp/elementor-pro-list-widgets` → the real `widgetType`s on this site (+ `pro_active`, `breakpoints`, `containers_active`). If a widget you want isn't listed, it doesn't exist here — pick another or (Pro widgets) tell the user Pro is needed.
   - `nibwp/elementor-pro-widget-schema { widget }` for **each** widget you'll use → the real control ids/types/defaults + responsive flags. Style only with these ids.
4. **Analyze the source.** Sections → containers; the single H1; columns → nested containers; repeated cards → repeated containers (or a Pro `posts`/loop widget if dynamic + Pro); forms (Pro only); media.
5. **Build the tree.** Containers first (`flex_direction`), widgets inside. Style via control ids. **Responsive:** add `<id>_tablet` / `<id>_mobile` variants (stack columns, smaller type/spacing) — never desktop-only. **Images:** sideload first so each carries an attachment **id** (the persister exposes `nibwp_elementor_pro_sideload_image`, or use NIBWP media abilities) — no hotlinked URLs.
6. **Validate (dry-run).** `nibwp/elementor-pro-html-to-page { tree, target, dry_run:true, _preflight_token }`. Fix every `failed[]` (unknown widgetType, Pro widget without Pro, duplicate/missing id). Heed `warnings[]` (unknown control id, missing alt, desktop-only, legacy section/column, html widget). Read the **score**.
7. **Persist.** Resubmit `dry_run:false`. The data is saved **wp_slash'd** and the **CSS is regenerated**, with a **round-trip guard** — so the front end renders immediately (no need to open the editor first).
8. **Verify / fine-tune.** Open `edit_url` in Elementor. If an existing page ever renders blank, run `nibwp/elementor-pro-repair { post_id }`.

## Styling → controls (not CSS)
Map design to controls, in priority order: **widget control → container control → global (`__globals__`) → custom CSS (last resort)**.
- Colors/typography/spacing/border/shadow/background → the widget/container controls (from the schema).
- Global colors/fonts → `"__globals__": { "title_color": "globals/colors?id=primary" }` (the base setting emptied).
- Dynamic WP data → `"__dynamic__": { "title": "[elementor-tag id=... name=post-title settings=...]" }` (Pro).
- Only genuinely unsupported CSS (complex `@keyframes`, pseudo-elements) belongs in Custom CSS.

## Rules
**Do** — containers first; real widgetTypes + real control ids (verified live); responsive variants; sideloaded images with ids; validate before persist; prefer a Pro dynamic widget over 3+ hand-duplicated cards **when Pro is active**.

**Don't** — raw HTML / `html` widget for content with a native widget; invented widgetTypes or control ids; legacy section/column for new layouts; Pro-only widgets (form, posts, loop, woo, theme-builder) when `pro_active` is false; hotlinked images; layout poured into Custom CSS; reused ids.

## Report
**Converted:** `<source>` → Elementor (`<edit_url>`). **Build:** N containers · nested columns · native widgets · styled via controls. **Validator:** ✓ clean · **score:** `<grade>` (structure/native/responsive/visual). **Data:** slashed + CSS regenerated (renders now). **Responsive:** desktop/tablet/mobile. **Media:** sideloaded (real ids). Flag every judgment call and any Pro widget skipped because Pro was off.
