# Dynamic data and CSS

## `[[[wp.*]]]` tokens

WordPress data is referenced inside a setting value with a triple-bracket token:

```json
{"name": "htmlAttribute", "value": [{"name": "href", "value": "[[[wp.site_url]]]"}]}
{"name": "content", "value": "[[[wp.post_title]]]"}
```

The token is substituted at render time. It works anywhere a string value goes:
`content`, an `htmlAttribute` value, `mediaUrl`, `attrAlt`.

Rules worth keeping:

- A hardcoded site URL is wrong the moment the site is cloned to staging. Use
  `[[[wp.site_url]]]`.
- Post-level tokens only resolve where there is a post. In a header that applies
  site-wide, `[[[wp.post_title]]]` is empty on an archive.
- `nibwp/builderius-pro-convert` and `nibwp/builderius-pro-inspect` both report
  every token found, so you can see at a glance what a config depends on.

## Per-module CSS and `%local%`

CSS goes in a `css` setting on the module, scoped with `%local%`:

```json
{"name": "css", "value": "%local% {\n    position: relative;\n    --header-height: 90px;\n}"}
```

`%local%` is replaced with a selector that matches that one module. Consequences:

- A real selector in a `css` setting either leaks to every other module or matches
  nothing. The validator warns when `%local%` is missing.
- Descendant rules are written as `%local% .child { … }`.
- `css` is not accepted on every module — `HtmlCode` and `Template` are among the
  exclusions. `nibwp/builderius-pro-modules` says which.
- Media queries are written in the CSS as media queries. Breakpoints themselves
  are a template-level setting, not a module one.

## Classes, not inline styles

There is no inline-style setting. An inline `style` attribute in converted markup
becomes a `css` setting on `%local%`; that is the honest translation, not a
preference. Where the site already has a class system, put the class in `tagClass`
and leave the CSS where the rest of it lives.
