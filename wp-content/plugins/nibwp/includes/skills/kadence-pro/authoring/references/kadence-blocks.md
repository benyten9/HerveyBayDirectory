# Kadence Blocks — reference

Verified from Kadence Blocks 3.7.x (`dist/blocks/*/block.json`). `nibwp/kadence-pro-list-blocks` returns the machine-readable version. Styling is done via **attributes** (Kadence generates the CSS, keyed by `uniqueID`).

## Structural blocks

### kadence/rowlayout — a section (container)
Holds `kadence/column` children.

**`colLayout` and `columns` are required, not optional.** `colLayout` defaults to
`""`, and the editor reads an empty one as "this row was never configured": it
replaces the whole row with its layout picker — the *Select Your Layout* /
*Design Library* placeholder. The page still renders on the front end, so this
looks fine until someone opens the editor and finds a page they cannot touch.
`columns` defaults to `2`, so a three-column row silently lays out as two.

Set `columns` to the number of `kadence/column` children and `colLayout` to a key
Kadence offers for that count. `"equal"` is valid for every count and is the
right default when the design has no strong opinion:

| columns | valid `colLayout` |
|---|---|
| 1 | `equal`, `row` |
| 2 | `equal`, `left-golden`, `right-golden`, `row` |
| 3 | `equal`, `left-half`, `right-half`, `center-half`, `center-wide`, `center-exwide`, `first-row`, `last-row`, `two-grid`, `row` |
| 4 | `equal`, `left-forty`, `right-forty`, `two-grid`, `row` |
| 5–6 | `equal`, `two-grid`, `three-grid`, `row` |

A key that does not fit the count is as broken as an empty one — the editor
falls back to the same picker. The converter fills both in from the children it
can see and the validator fails the build if they disagree, but author them
correctly: it is the difference between a layout you chose and one you were given.

Other key attrs: `htmlTag` ("section"/"div"), `minHeight`, `maxWidth`,
`topPadding`/`bottomPadding`/`leftPadding`/`rightPadding` (+ `…M` mobile variants),
`topMargin`/`bottomMargin`, `bgColor`, `bgImg`, `bgImgID`, `bgImgSize`,
`bgImgPosition`, `columnGutter`, `rowGutterType`.

### kadence/column — a column (container, ONLY inside rowlayout)
Needs its own `uniqueID` (its generated CSS is keyed to it) and `id` (its 1-based
position in the row). The converter stamps both; without them a styled column
renders unstyled, because the rule written for it matches no element.

`topPadding`/`bottomPadding`/`leftPadding`/`rightPadding` (+ mobile), `topMargin`/`bottomMargin`, `background`, `backgroundOpacity`, `border`, `borderWidth`, `zIndex`.

## Content blocks

### kadence/advancedheading — heading (static-save)
`content` (string, may hold inline HTML), `level` (1–6), `htmlTag` (use "p" for a styled paragraph), `align`, `color`, `size`, `sizeType`, `lineHeight`, `letterSpacing`, `typography`, `fontWeight`, `topMargin`/`bottomMargin`.

### core/paragraph — body text
`content`, `align`, `dropCap`. (Kadence has no paragraph block; core paragraphs are theme-styled.)

### kadence/advancedbtn — button wrapper (container)
`hAlign`, `btnCount`, `btns`. Holds `kadence/singlebtn`.

### kadence/singlebtn — a button (ONLY inside advancedbtn; rebuilds from attrs)
`text`, `link`, `target`, `noFollow`, `download`, `style`, `sizePreset`, `width`.

### kadence/image — image (rebuilds from attrs)
`url`, `alt` (required for a11y), `id` (media-library attachment), `caption`, `width`, `height`, `sizeSlug`, `ratio`, `align`.

### kadence/infobox — icon/title/text card
`link`, `hAlign`, `containerBackground`, `containerBorder`, `mediaType` (icon/image/number), plus icon/title/text sub-attributes. One per column for feature grids.

### kadence/iconlist — icon list
`items` (array of `{text, icon, …}`), `listStyles`, `columns`, `listGap`.

### kadence/icon — icon(s)
`icons` (array of icon defs), `iconCount`, `textAlignment`.

### kadence/spacer — space / divider
`spacerHeight`, `spacerHeightUnits`, `dividerEnable`, `dividerStyle`, `dividerColor`, `dividerWidth`.

## Widgets

- **kadence/advancedgallery** — `images` (array), `columns`, `type` (grid/carousel/…), `imageRatio`, `lightSize`.
- **kadence/testimonials** — `style`, `layout` (grid/carousel), `columns`, `autoPlay`. Child `kadence/testimonial`.
- **kadence/accordion** — `paneCount`, `openPane`, `startCollapsed`. Child `kadence/pane`.
- **kadence/tabs** — `tabCount`, `layout`, `currentTab`. Child `kadence/tab`.
- **kadence/posts** — DYNAMIC post grid: `columns`, `postsToShow`, `postType`, `postTax`, `order`, `orderBy`, `categories`. Use instead of repeating static cards.
- **kadence/table**, **kadence/countup**, **kadence/countdown**, **kadence/progress-bar**, **kadence/tableofcontents**, **kadence/lottie**, **kadence/googlemaps**, **kadence/form** / **kadence/advanced-form**.

## Nesting rules (hard)

| Child | Must be inside |
|---|---|
| kadence/column | kadence/rowlayout |
| kadence/singlebtn | kadence/advancedbtn |
| kadence/tab | kadence/tabs |
| kadence/pane | kadence/accordion |
| kadence/testimonial | kadence/testimonials |
| kadence/listitem | kadence/iconlist |
| kadence/table-row | kadence/table |
| kadence/table-data | kadence/table-row |

## uniqueID

Every `kadence/*` block needs a unique `uniqueID` string (the converter mints `"<n>_<hex>"`). Kadence uses it as the CSS selector suffix (`.kt-row-layout-id{uniqueID}`, `.kt-adv-heading{uniqueID}`, …). Never duplicate one — the validator rejects duplicates.
