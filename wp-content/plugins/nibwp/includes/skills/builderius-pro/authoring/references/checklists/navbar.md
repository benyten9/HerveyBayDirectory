# Checklist — navbar

A navbar is the part the client will want to change without you.

- [ ] Wrapper `HtmlElement` with `tag: header`.
- [ ] Logo links to `[[[wp.site_url]]]`, and the logo itself is `SvgCode` where it is a vector.
- [ ] The menu is a `MenuBuilder`, not hand-written links.
- [ ] A `MenuToggle` exists if the menu collapses, and the collapsed state is actually styled.
- [ ] The logo link has an `aria-label` when it has no text.
- [ ] If this becomes a site-wide template, the user is told it replaces what the theme provides.

Before committing: `nibwp/builderius-pro-build { dry_run: true }` and read the
version plan. A commit is a version somebody else will read.
