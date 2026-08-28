# Checklist — archive

The post type's own index. In practice it is a search page that already knows
its post type.

- [ ] A search form and a feed, wired — the same rules as a search page.
- [ ] The feed's post type matches the archive's. An archive listing something
      else is a page, not an archive.
- [ ] Filters chosen for browsing rather than for finding: category, price
      band, location. Keyword search belongs here too, but it is rarely first.
- [ ] Pagination decided — `load_more` for browsing, `prev_next` when people
      need to keep their place.
- [ ] `ts_noresults_text` written for the case where a filter combination has
      no matches, which on an archive is common.
- [ ] A heading that says what is being listed, bound to
      `@site().post_types.{key}.plural` rather than typed.

{{INJECTED_FEEDBACK}}
