# Client-editable content with ACF (repeaters that actually appear in admin)

Use for FAQs, testimonials, pricing rows — anything the client must edit without touching blocks.

## The failure that looks like "you hardcoded it"

**Symptom:** `get_field('faqs', $pid)` returns rows, the frontend shows them, but the field group renders **empty** on the page edit screen → the client cannot edit → it looks hardcoded.

**Root cause:** the top-level repeater's `acf-field` post has `post_parent = 0` instead of the field-group post ID. (Sub-fields correctly parent to the repeater, so the data works — only the UI is missing.) `acf_get_fields('group_key')` returns empty.

**Fix:**

```php
$grp = get_page_by_path( 'group_sb_pricing_faq', OBJECT, 'acf-field-group' );
$fld = get_posts([ 'post_type'=>'acf-field', 'name'=>'field_sb_faqs', 'posts_per_page'=>1, 'post_status'=>'any' ]);
wp_update_post([ 'ID' => $fld[0]->ID, 'post_parent' => $grp->ID ]);

acf_get_store('fields')->reset();
acf_get_store('field-groups')->reset();
wp_cache_flush();

// verify — must list: faqs:repeater subs=2
$check = acf_get_fields('group_sb_pricing_faq');
```

**Always verify with `acf_get_fields()`** — data existing in postmeta proves nothing about the UI.

## Structure that works

- `acf-field-group` post (`post_name` = group key), location rule → the target page.
- Repeater `acf-field` post: `post_parent` = **group post ID**.
- Sub-fields (`question`, `answer`): `post_parent` = **repeater post ID**.

## Display

Keep the frontend driven by the repeater — a small shortcode/block that loops `get_field()`. This is correct, not a hack; the anti-pattern is hardcoding the rows.

```php
add_shortcode( 'sb_faq', function () {
    $faqs = get_field( 'faqs', get_the_ID() );
    if ( empty( $faqs ) || ! is_array( $faqs ) ) { return ''; }
    $o = '<div class="sb-faq__list">';
    foreach ( $faqs as $f ) {
        $o .= '<details class="sb-faq__item"><summary class="sb-faq__q">'
            . esc_html( $f['question'] ) . '</summary><div class="sb-faq__a">'
            . wp_kses_post( $f['answer'] ) . '</div></details>';
    }
    return $o . '</div>';
} );
```

Place it on the page as a **`core/shortcode` block** (not `core/html`). Style it via the page's `_kad_blocks_custom_css` (loop/accordion internals can't be block attributes).

## Checklist

- [ ] `acf_get_fields('group_key')` lists the repeater + sub-field count
- [ ] The repeater renders on the page edit screen with existing rows
- [ ] Editing a row in admin changes the frontend
- [ ] Display block is `core/shortcode`, not `core/html`
