# Checklist — footer

- [ ] Renders on every page. No listing-specific content.
- [ ] Links that people look for in a footer: terms, privacy, contact. Voxel
      keeps pages for the first two — `@site().url` and the template slots.
- [ ] Columns collapse to one on mobile.
- [ ] Nothing in it needs a search form or a feed. If the design wants recent
      listings, `ts-post-feed` with `ts_source: "search-filters"` runs its own
      query and needs no wiring.
- [ ] Assigning to `slot: "footer"` replaces the site footer everywhere.

{{INJECTED_FEEDBACK}}
