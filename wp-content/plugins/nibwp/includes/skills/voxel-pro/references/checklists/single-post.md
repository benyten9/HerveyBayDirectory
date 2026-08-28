# Checklist — single post layout

The page one listing gets to itself. It can afford everything the card had to
leave out.

- [ ] Gallery, description, work hours, contact fields, location — all bound
      to real field keys from the catalog.
- [ ] Every optional field is behind a visibility rule or carries a fallback.
      Half the listings will not have filled everything in.
- [ ] Actions the visitor expects: directions, phone, website, share, follow,
      direct message. `ts-advanced-list` carries these.
- [ ] Reviews or the timeline included if the post type has them switched on —
      check `nibwp/voxel-info` rather than assuming.
- [ ] Related listings use a `ts-post-feed` with `ts_source` of
      `search-filters`, not a hand-built list.
- [ ] The author is credited with `@author()` where that matters.
- [ ] Anything only the owner should see (edit, statistics, promote) sits
      behind a `user:can_edit_post` visibility rule.
- [ ] The preview post the editor uses is a listing with its fields actually
      filled in — pass `preview_post_id` if the newest one is sparse.

{{INJECTED_FEEDBACK}}
