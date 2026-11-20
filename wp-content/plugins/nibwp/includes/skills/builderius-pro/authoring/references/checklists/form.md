# Checklist — form

A form that renders and collects nothing is worse than no form.

- [ ] `SmartForm`, not hand-written `input` elements.
- [ ] Fields and the submit action are configured in Builderius — say that this still has to be done.
- [ ] Every field has a label, not just a placeholder.
- [ ] The user is told where submissions go and that they are stored on the site.
- [ ] A success state and an error state both exist before you call it finished.

Before committing: `nibwp/builderius-pro-build { dry_run: true }` and read the
version plan. A commit is a version somebody else will read.
