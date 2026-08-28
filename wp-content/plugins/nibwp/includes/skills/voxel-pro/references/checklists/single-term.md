# Checklist — single term layout

The page for one taxonomy term — a category, a price band, a region.

- [ ] The term's own name and description bound with `@term()`, not typed.
- [ ] A feed of the listings in that term. `ts_source: "search-filters"` with
      the term filter set, or a search form and feed wired as usual.
- [ ] Child terms offered if the taxonomy is hierarchical — `ts-term-feed`
      with `ts_parent_term_id`.
- [ ] Nothing assumes a particular term. This one template renders for all of
      them, including the ones with two listings.
- [ ] Assigned per taxonomy (`{taxonomy, tax_slot: "single"}`) or to the site
      default `term_single`. The per-taxonomy one wins.

{{INJECTED_FEEDBACK}}
