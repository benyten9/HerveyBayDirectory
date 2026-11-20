# Checklist — footer

A footer is mostly links and one line of fine print.

- [ ] Wrapper `HtmlElement` with `tag: footer`.
- [ ] Menus are `MenuBuilder` modules.
- [ ] The copyright year is not typed as a number — use `Time`, or accept that it is wrong next January and say so.
- [ ] Site title via `[[[wp.site_title]]]`.
- [ ] Social links have accessible names, not just icons.

Before committing: `nibwp/builderius-pro-build { dry_run: true }` and read the
version plan. A commit is a version somebody else will read.
