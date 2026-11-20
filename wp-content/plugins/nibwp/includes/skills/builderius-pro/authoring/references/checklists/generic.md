# Checklist — generic

When nothing more specific fits, these are the checks that always apply.

- [ ] Every module name came from `nibwp/builderius-list-modules`.
- [ ] Every setting name came from `nibwp/builderius-pro-modules`.
- [ ] No module with no children and no content.
- [ ] Every `css` value scoped with `%local%`.
- [ ] `tagClass` is an array.
- [ ] No hardcoded site URL; `[[[wp.site_url]]]` instead.
- [ ] Labels mean something to a human reading the structure panel.
- [ ] The version plan was shown to the user before anything was committed.
- [ ] What was said afterwards distinguishes a commit from a published release.

Before committing: `nibwp/builderius-pro-build { dry_run: true }` and read the
version plan. A commit is a version somebody else will read.
