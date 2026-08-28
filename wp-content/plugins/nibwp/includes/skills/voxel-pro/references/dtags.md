# Dynamic tags

## Shape

```
@tags()@post(property.sub).modifier(argument)@endtags()
```

A value must **begin** with `@tags()`, or Voxel treats the whole string as
literal text. Close with `@endtags()`.

Escaping inside a property: `\` before `)`, `.` or `\`. Inside a modifier
argument: before `(`, `)`, `,` or `\`.

## Groups

| Group | Bound to |
|---|---|
| `@post()` | The listing being rendered |
| `@author()` | That listing's author |
| `@user()` | The logged-in visitor |
| `@site()` | The site itself |
| `@term()` | The current term, on term templates |
| `@value()` | A literal, used inside `.then()` / `.else()` |

## Post properties

Two kinds. Aliases start with a colon and cover what WordPress owns:

```
:id  :title  :content  :excerpt  :slug  :url  :edit_url
:date  :modified_date  :expiry_date  :status  :priority
:reviews  :timeline  :wall  :followers  :stats  :post_type
```

Everything else is the post type's own field keys, which differ per site.
Read them:

```
nibwp/voxel-pro-catalog { topic: "widgets", post_type: "places" }
```

Nested access follows the field's own shape — `location.address`,
`location.latitude`, `_thumbnail_id.id`, `work_hours.status_label`,
`:reviews.average`, `:reviews.total`.

`[]` reads a list: `@post(taxonomy-2.name[]).list(\, ,\, )`.

## Modifiers

Formatting: `append` `prepend` `capitalize` `lowercase` `uppercase`
`currency_format` `date_format` `number_format` `round` `abbreviate`
`truncate` `truncate_words` `replace` `pluralize` `time_diff` `to_age`
`fallback`.

Lists: `list` `count` `first` `last` `nth` `shuffle` `loop_index`.

Conditionals, which chain into `.then()` and `.else()`: `is_empty`
`is_not_empty` `is_equal_to` `is_not_equal_to` `is_greater_than`
`is_less_than` `is_between` `is_checked` `is_unchecked` `contains`
`does_not_contain`.

```
@tags()@post(:reviews.total).is_equal_to(1).then(@value(\) review).else(@value(\) reviews)@endtags()
```

## The failure worth knowing about

Voxel renders a property it does not recognize as an **empty string**. It does
not warn, and it does not print the tag. A misspelled field key gives you a
card with a blank heading on every single listing.

That is why the build ability checks every property name against the group's
own vocabulary and refuses a document containing one that does not exist. It
also renders every tag against a real listing and reports the ones that come
back empty as warnings — sometimes correct, for an optional field, and
sometimes the sign of a key that is subtly wrong.

To check one expression yourself:

```
nibwp/voxel-render { action: "render", content: "@post(location.address)", post_id: 123 }
```

## Where tags are allowed

Any widget setting Voxel has taken over — text, textarea, WYSIWYG, code,
color, number, date, URL (`url` key), media and gallery (`id` key), icons
(`value` key), select, post and term pickers, repeaters.

Also the element-level extras: `_voxel_dynamic_css`, `_voxel_dynamic_class`,
`_voxel_dynamic_attrs`, `_voxel_loop`, and inside visibility rules of type
`dtag`.
