# Checklist — pricing

Pricing is a table people scan, and the thing they scan for is the difference between the columns.

- [ ] Columns have the same shape, which means they are a `Collection` candidate if the plans come from content.
- [ ] The price is marked up as text, not baked into an image.
- [ ] Each column has its own action with its own `href`.
- [ ] The recommended plan is marked with a class, not with a different structure.
- [ ] The feature list is a real `ul` of `li`, so a screen reader counts them.

Before committing: `nibwp/builderius-pro-build { dry_run: true }` and read the
version plan. A commit is a version somebody else will read.
