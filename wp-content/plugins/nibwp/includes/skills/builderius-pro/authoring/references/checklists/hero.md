# Checklist — hero

A hero is one message and one action. Everything else is decoration.

- [ ] A single `h1`. If the page has another `h1`, this one is not it.
- [ ] One primary action, as an `a` with a real `href` — not `#` left in from the markup.
- [ ] A supporting line that says something the headline does not repeat.
- [ ] If there is a background image, it is on the section via `css` and the text contrast still works over it.
- [ ] Any image has `attrAlt`. A decorative one gets an empty alt deliberately, not by accident.
- [ ] Padding on the section, not margins on the children — the children get reordered.
- [ ] Site title or URL via `[[[wp.site_title]]]` / `[[[wp.site_url]]]`, never typed in.

Before committing: `nibwp/builderius-pro-build { dry_run: true }` and read the
version plan. A commit is a version somebody else will read.
