# Checklist — card-grid

A grid of identical shapes is a loop that has not been written yet.

- [ ] Three or more identical children: use a `Collection` bound to a query if the content comes from posts.
- [ ] Static copy nobody will change can stay static — say which you chose and why.
- [ ] Each card has one heading level, consistent across the grid.
- [ ] Images have `attrAlt`.
- [ ] The grid is CSS on the parent via `%local%`, not margins on each card.
- [ ] A card that is entirely clickable is one link, not a link per element.

Before committing: `nibwp/builderius-pro-build { dry_run: true }` and read the
version plan. A commit is a version somebody else will read.
