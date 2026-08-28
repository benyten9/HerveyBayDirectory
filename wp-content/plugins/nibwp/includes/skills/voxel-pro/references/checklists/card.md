# Checklist — preview card

A card renders once per search result, inside a grid cell whose width it does
not control. Everything about it follows from that.

- [ ] Field keys read from `nibwp/voxel-pro-catalog { post_type }` — not
      remembered, not guessed from another site.
- [ ] The title, the image and the link all bound with dynamic tags. A card
      with hard-coded text is the same card for every listing.
- [ ] The whole card, or at least the title and image, links to
      `@post(:url)`. People click cards.
- [ ] Optional fields either carry `.fallback(…)` or sit behind a visibility
      rule. An empty line in a grid of cards looks like a bug.
- [ ] No search form and no map inside it — those exist once per page, not
      once per result.
- [ ] Nothing assumes a fixed width. It will be rendered in one, two, three
      and four columns.
- [ ] Ratings, distance and opening status come from
      `:reviews.average`, `location.distance.kilometers`,
      `work_hours.status_label` — not from anything computed by hand.
- [ ] Images sized `medium` or smaller. A card grid loads twenty of them.
- [ ] Decide with the user: replace the post type's main card, or add a named
      alternate with `mode: "custom"`. Replacing changes every feed on the
      site at once.

{{INJECTED_FEEDBACK}}
