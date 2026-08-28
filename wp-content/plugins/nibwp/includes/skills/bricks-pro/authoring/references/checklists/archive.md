# Archive template — Bricks checklist

## Identify
- [ ] `template_type = "archive"` — wraps the post listing (post type archive, category, tag, custom taxonomy term, search results, author)
- [ ] BEM block: `{brand}-archive` (root), `{brand}-archive__header`, `{brand}-archive__grid`, `{brand}-archive__pagination`

## Header (above the loop)
- [ ] `heading` with dynamic data: `text = "{post_taxonomy:category}"` or `{search_query}` or static "Latest posts"
- [ ] Sub-line with description: `{post_taxonomy_description}` or static
- [ ] Optional filter UI: Bricks Filters panel (when active) for category/sort dropdowns

## Loop — the main `posts` element
- [ ] `name: "posts"` with `settings.query = { inherit_current_query: true }` for "current archive context" (most archives)
- [ ] OR explicit query when this archive should pull from elsewhere: `settings.query = { post_type: ["project"], posts_per_page: 9 }`
- [ ] `settings.layout = "grid"` (most common) with `settings.columns` per-breakpoint
- [ ] `settings.noPostsMessage = "No posts found."` (or per-archive copy)

## Child subtree (the per-post card template)
- [ ] Inside the `posts` element, ONE child subtree that represents a single post card
- [ ] Featured image: `image` element with `settings.image.useFeaturedImage = true`
- [ ] Title: `heading.settings.text = "{post_title}"` + `settings.tag = "h3"` (archive cards are not h1/h2)
- [ ] Excerpt: `text.settings.text = "{post_excerpt:25}"` (sane word limit)
- [ ] Read more: `button.settings.link = { type: "internal", postId: "{post_id}" }`
- [ ] Meta (date/author): `post-date`, `post-author`, or inline `text` with dynamic tags

## Pagination
- [ ] Add a `pagination` element AFTER the `posts` element (sibling, not child)
- [ ] Auto-binds to the most recent `posts` element above it
- [ ] `settings.prevText = "Previous"`, `settings.nextText = "Next"` (translate via i18n hooks if needed)
- [ ] `settings.showNumbers = true` for numbered pagination

## Tokens
- [ ] Grid gap: `var(--space-l, 2rem)`
- [ ] Card padding: `var(--space-l, 2rem)`
- [ ] Grid auto-fit: `repeat(auto-fit, minmax(min(100%, 18rem), 1fr))` so cards reflow gracefully

## Display conditions
- [ ] Bricks template `conditions[]`: which archives use this template?
  - All archives: `main: "templateArchive"`
  - Specific CPT archive: `main: "templateArchive", value: ["archive_project"]`
  - Specific category: `main: "term", value: ["category:news"]`
  - Search results: `main: "searchResults"`

## Performance
- [ ] `settings.query.no_found_rows = true` ONLY if pagination is disabled
- [ ] Featured image size: pick `medium` or `medium_large` — never `full` for cards
- [ ] Lazy-load all card images (Bricks default for `useFeaturedImage = true`)

## Accessibility
- [ ] Card root: `tag="article"`
- [ ] Card title: `<h3>` inside an `<article>` (NOT `<h2>` unless this is a sub-archive)
- [ ] Pagination: `nav aria-label="Pagination"` (Bricks renders this when configured)
- [ ] Empty state visible + announced via `aria-live="polite"` (Bricks built-in for `noPostsMessage`)

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
