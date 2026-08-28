# Checklist — search page

- [ ] Filter keys read from the catalog for each post type in
      `ts_choose_post_types`. An invented key fails the build, which is the
      good outcome; the bad one is a filter that silently does nothing.
- [ ] `relations` left at `"auto"` unless the layout genuinely needs a pairing
      the position cannot imply — and even then, name ids, never paths.
- [ ] Every feed sourced from the search form has that form on the same
      document. A feed pointed at a form that is not there renders an empty
      list forever.
- [ ] `ts_search_on` decided deliberately: `filter_update` searches as the
      visitor changes a filter, `submit` waits for the button. Fewer than
      about four filters, prefer `filter_update`.
- [ ] `ts_noresults_text` written. The default says nothing useful.
- [ ] A card template chosen per post type, or `"main"` on purpose.
- [ ] Feed column counts set for tablet and mobile too — a three-column grid
      on a phone is unreadable.
- [ ] If there is a map, it is beside the results rather than under them, and
      it has a height.
- [ ] The dry run's reported wiring matches what you intended, before the
      write.

{{INJECTED_FEEDBACK}}
