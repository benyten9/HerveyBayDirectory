# Mosaic elements

Read from the Mosaic 1.0 source (`Mosaic/NodeTypes/*`). `nibwp/mosaic-list-elements`
returns the same list with `available` checked against this install.

Every element also accepts: `name`, `hidden`, `locked`, `conditions`, `attributes`,
`cssClasses`, `attrID`, `style`, `interactions`.

## Layout

| type | children | data | use |
|---|---|---|---|
| `section` | any | `tagName` | A full-width band. `tag: header / footer / nav / main / aside` for landmarks. |
| `div` | any | `tagName`, `dynamicSources` | Rows, columns, grids, cards, the width-capped inner of a section. |
| `list` | `list-item` | `tagName` (`ol`), `listType`, `listStart` | ul/ol. |
| `list-item` | any | `mode` | One li. `text: "…"` shorthand gives it its words. |

## Words

| type | children | data | use |
|---|---|---|---|
| `text` | inline runs only | `tagName` | Headings, paragraphs, labels, quotes. `text: "…"` shorthand. |
| `wysiwyg-text` | none | `text`, `format` (`["bold"]`, `["italic"]`) | A run of words. |
| `wysiwyg-link` | inline runs | `url`, `target`, `rel` | A link inside a text. |
| `wysiwyg-span` | inline runs | — | A styled phrase (highlight, colour). |
| `wysiwyg-linebreak` | none | — | `<br>`. |

Bold-then-plain in one paragraph:

```json
{ "type": "text", "tag": "p", "children": [
  { "type": "wysiwyg-text", "data": { "text": "Free cancellation", "format": ["bold"] } },
  { "type": "wysiwyg-text", "data": { "text": " up to 24 hours before." } }
] }
```

## Actions and media

| type | children | data | use |
|---|---|---|---|
| `button` | text (+ icon) | `url`, `target`, `rel`, `inactive` | A link or button. `url` + `text` shorthand. No url = `<button>`. |
| `image` | none | `image` (URL or attachment), `alt`, `width`, `height`, `loading`, `fetchPriority`, `srcset`, `sizes` | Always give `alt`. |
| `icon` | none | `svg`, `colors`, `autoScale` | Inline SVG. |
| `video` | none | `video`, `sources`, `autoplay`, `muted`, `loop`, `controls`, `playsinline`, `poster`, … | Self-hosted video. |
| `youtube` | none | `youtubeUrl`, `autoplay`, `muted`, `controls`, `privacy`, `lazyLoad`, `startTime` | |
| `vimeo` | none | `vimeoUrl`, … | |
| `googlemaps` | none | `query`, `zoom`, `maptype`, `lazyLoad` | |
| `openstreetmap` | none | `latitude`, `longitude`, `zoom`, `layer` | |
| `code` | none | `content`, `insertLocation`, `processShortcodes` | Mosaic Pro. Last resort only. |

## Widgets

Send these **with no children**: Mosaic builds its own working structure, then fill the
words with refine.

| type | parts | data |
|---|---|---|
| `accordion` | `accordion-item` > `accordion-title` + `accordion-content` | `openMode`, `firstOpened`, `requireOneOpen`, `animation` |
| `tabs` | `tabs-menu` > `tabs-tab`; `tabs-content` > `tabs-tab-pane` | `defaultTabIndex` |
| `navbar` | `navigation`, `navbar-toggle` | `buttonBreakpoint`, `animation`, `disableScroll` |
| `slider` | `slider-slides` > `slider-slide`, arrows, `slider-navigation` | `duration`, `isAutoplay`, `isCarousel`, … (send the parts yourself) |

## Reuse

| type | use |
|---|---|
| `component-instance/<id>` | An instance of a component (`nibwp/mosaic-list-components`). No children; edit the component. |

## Mosaic's own nodes — never author

`template-internal`, `template-external`, `master-root`, `document`, `body`,
`component-internal`, `component-external`, `component-root`, `styleguide-root`.
