# Dynamic data tags

For `template_type = "content"` (single post) and `"archive"` (post listing wrapper), Bricks resolves these tags at render time from the current post in scope.

## Post
- `{post_title}` — current post title
- `{post_content}` — full post content (the_content())
- `{post_excerpt}` — auto-trimmed excerpt
- `{post_excerpt:25}` — first 25 words
- `{post_date}` — publish date
- `{post_date:F j, Y}` — formatted (PHP date format)
- `{post_modified_date}` — last modified
- `{post_id}` — post ID
- `{post_slug}` — URL slug
- `{post_url}` — permalink
- `{post_status}` — publish / draft / private
- `{post_type}` — post / page / project / …

## Author
- `{post_author}` — login
- `{post_author_name}` — display name
- `{post_author_email}` — email
- `{post_author_url}` — author archive URL
- `{post_author_bio}` — bio
- `{post_author_avatar}` — gravatar URL

## Featured image
- `{featured_image}` — `<img>` of the featured image
- `{featured_image_url}` — URL only
- `{featured_image_alt}` — alt text

Better: use a Bricks `image` element with `settings.image.useFeaturedImage = true` — it lets you set size + lazyLoad + fetchpriority per element.

## Taxonomy
- `{post_taxonomy:category}` — comma-separated category names
- `{post_taxonomy:project_type}` — custom taxonomy

## Comments
- `{post_comments_number}` — count
- `{post_comments_url}` — link

## Meta (custom fields)
- `{wp:post_meta:key}` — raw post meta
- `{acf:field_name}` — ACF field (supports text, image, repeater, etc.)
- `{acf:field_name:size}` — ACF image at a specific size (`thumbnail`, `medium`, `large`, custom)

## ACF advanced
- `{acf:group_name.field_name}` — nested
- `{acf:repeater_name:0.field_name}` — repeater row by index
- `{acf:relationship_name:0.post_title}` — relationship field, first related post

## User (logged-in current user)
- `{wp:user_login}` — login of viewer
- `{wp:user_display_name}` — display name
- `{wp:user_email}` — email
- `{wp:user_meta:key}` — meta on the current user
- `{wp:user_roles}` — roles array

## Site
- `{site_name}` — blog name
- `{site_url}` — home URL
- `{site_tagline}` — tagline
- `{wp:current_year}` — current year (4-digit)
- `{wp:current_date}` — today (Y-m-d)
- `{wp:current_date:F j, Y}` — formatted

## Conditional
- `{post:if_has_excerpt}` — truthy when post has explicit excerpt
- `{wp:if_logged_in}` — truthy when viewer is logged in
- Use Bricks' Conditions panel for show/hide — DON'T try to do branching with raw tags inside `text`.

## Loops
Inside a `posts` element children, the active post in the loop becomes the scope for all `{post_*}` and `{acf:*}` tags. So `{post_title}` inside a child of `posts` resolves to each iterated post's title, not the parent page's title.

## Cross-template variables (advanced)
Bricks 1.10+ supports CSS variable bindings to dynamic data via `{post_meta:--brand-color}`. Use the Bricks Design System variables panel — DO NOT define dynamic CSS variables inside `_cssCustom`.

## Caveats
- These tags only resolve on **published** content templates. The Bricks editor preview uses a sample post — make sure the agent verifies the rendered output, not the editor's placeholder text.
- For `template_type = "section"`, dynamic data is in scope ONLY when the section is embedded inside a content/archive template OR the page has a query in scope.
