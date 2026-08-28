# Form — Bricks element checklist

## FIRST — STOP and route

If the source contains a `<form>`, the server-side ability returns `requires_user_input: true` with two choices:

- **A. Plugin shortcode** — `nibwp/forms-manage list_plugins` → `create_form` → emit a Bricks `shortcode` element wrapping the plugin shortcode. Use when: real spam protection / GDPR / advanced routing matters; user already has Fluent Forms / Gravity / WPForms.
- **B. Bricks native form element** — emit a `form` element with `settings.fields = [{...}]`. Use when: simple contact / newsletter; user wants Bricks-native styling; no advanced anti-spam needed.

Default to **A** unless the user picks B.

## Option A — plugin shortcode

- [ ] `nibwp/forms-manage` called with `action: "list_plugins"` to learn which form plugin is installed
- [ ] User picked plugin OR confirmed Bricks-native
- [ ] If plugin: `nibwp/forms-manage` called with `action: "create_form", plugin, fields, ...` to create the actual form. Returns shortcode + form_id.
- [ ] Bricks payload: a single `shortcode` element with `settings.shortcode = "[fluentform id=N]"` (or Gravity / WPForms / CF7 shortcode)
- [ ] Wrap the shortcode element in a `block` with `_cssGlobalClasses = ["{brand}-form"]` so styling is consistent

## Option B — Bricks native form element

- [ ] `form` element with `settings.fields` array — each field `{ name, label, type, required, placeholder, options? }`
- [ ] Submit button: `settings.submitButtonText`, `settings.submitButtonStyle = "{brand}-button--primary"`
- [ ] Actions: `settings.actions = ["email"]` (or `webhook` / `mailchimp` / `redirect`)
- [ ] Email action: `settings.emailAction = { to, from, subject, replyTo, message }`
- [ ] Redirect action (optional): `settings.redirectAction = { url, target }`
- [ ] Confirmation / error messages set: `settings.successMessage`, `settings.errorMessage`

## Field-level a11y

- [ ] Every input has `label` (Bricks renders it as a real `<label>`)
- [ ] Required fields marked `required: true`
- [ ] Autocomplete attrs set on common fields: name → autocomplete="name", email → "email", tel → "tel", organization → "organization"
- [ ] Email field type: "email" (Bricks renders `<input type="email">` with HTML5 validation)
- [ ] Error state visible (red border + message) — Bricks form handles this; verify on dry_run

## Tokens
- [ ] Input border-color: `var(--border-color-light, #dcdcde)`
- [ ] Focus state: `outline: 2px solid var(--primary, #2271b1); outline-offset: 2px;`
- [ ] Field spacing: `var(--space-m, 1rem)` between fields
- [ ] Label color: `var(--text-dark, #1a1a1a)`

## Layout
- [ ] If 2 short fields side-by-side (e.g. first name + last name), use a `block` with `display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-m, 1rem);`
- [ ] Below `_mobile_portrait`, collapse to single column

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
