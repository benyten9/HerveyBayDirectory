# The module library

The authority is the install, not this file. One call:

```
nibwp/builderius-list-modules          → the module types
nibwp/builderius-pro-modules {list}    → the same, plus what each one accepts
```

The grouping below is editorial — the names and descriptions come from the
integration's own library (`includes/premium/integrations/builderius/lib/config.php`,
`nibwp_builderius_module_library()`), which is itself derived from Builderius's
module views. An add-on can extend the set, which is why you read it rather than
trust a list.

## Structure and content

| Module | For |
| --- | --- |
| `HtmlElement` | The workhorse. Any tag, via the `tag` setting. Text via `content`. |
| `HtmlCode` | A raw HTML block, via `contentHtml`. Nothing inside it is editable as modules. |
| `SvgCode` | Inline SVG, via `contentSvg`. |
| `Time` | A dynamic date or time, via `contentTime` and `dateTimeFormat`. |

## Repetition

| Module | For |
| --- | --- |
| `Collection` | A query loop. One module instead of five hand-built cards. |
| `SubCollection` | A loop nested inside a Collection. |

## Reuse

| Module | For |
| --- | --- |
| `Component` | A reference to a reusable Builderius component. |
| `Template` | Includes another template. |
| `RecursiveTemplate` | A template that includes itself — menus, trees. |

## Navigation and interaction

| Module | For |
| --- | --- |
| `MenuBuilder` | Renders a WordPress nav menu, so the client can edit it. |
| `MenuToggle` | The responsive toggle for it. |
| `Accordion` | Collapsible sections. |
| `SmartForm` | A Builderius form. Submissions are stored as a post type. |
| `Notification` | A dismissible bar. |
| `CookieNotice`, `Cookieconsent` | Consent notice and consent manager. |

## Choosing

- Anything structural or textual: `HtmlElement` with the right `tag`.
- An icon or logo: `SvgCode`, not an `img` to an SVG file.
- Something repeated that comes from content: `Collection`.
- A menu: `MenuBuilder`. Hand-written links in a `nav` are a maintenance job you
  are handing to someone else.
- A form: `SmartForm`. Hand-written `input` elements render and collect nothing.
- Markup you genuinely cannot model: `HtmlCode`, and say that it will not be
  editable as modules afterwards.

## Settings

Settings are declared per module, and `nibwp/builderius-pro-modules` answers for
this install. The ones that apply to every module are `tagClass` (an array),
`tagId`, `htmlAttribute` (a list of `{name, value}`), `visibilityCondition`,
`dataVars` and `css` — `css` excluded from a few, including `HtmlCode` and
`Template`.

The setting that holds text on an `HtmlElement` is `content`. Not `textContent`.
