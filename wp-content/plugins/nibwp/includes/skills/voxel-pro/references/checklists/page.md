# Checklist — page

An ordinary page that happens to live on a Voxel site.

- [ ] If it lists anything, it uses a `ts-post-feed` rather than blocks typed
      out by hand. Static listings go stale the day after they are written.
- [ ] A feed with no search form on the page needs `ts_source` of
      `search-filters`, `manual` or `archive` — the default expects a form.
- [ ] Site-level content bound with `@site()` where it would otherwise be
      duplicated: the site title, the create-listing links, page URLs.
- [ ] Design taken from `nibwp/design-direction` so the page belongs to the
      site rather than to whichever reference it was built from.
- [ ] Responsive values set. Containers and column counts, at both
      breakpoints.
- [ ] If the page is meant to be a destination Voxel knows about — the auth
      page, the orders page, the inbox — assign it to that slot rather than
      only linking to it.

{{INJECTED_FEEDBACK}}
