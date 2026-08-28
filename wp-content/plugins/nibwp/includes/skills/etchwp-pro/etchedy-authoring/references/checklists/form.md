# form — checklist

## 🛑 STOP — read this first

**If the source HTML contains a `<form>` element, you MUST NOT convert it to Etch blocks.** Forms have submission, validation, spam protection, conditional logic, accessibility, and entry storage that a raw `<form>` cannot replicate. The validator rejects payloads that include raw `<form>` HTML when any form plugin is installed.

### Required routine when `<form>` is detected

1. Call `nibwp/forms-manage` with `action: "list_plugins"`. The response lists every supported form plugin and whether it is installed/active.
2. Ask the user **which installed plugin** to use AND **which form ID** (the same call returns each plugin's forms; otherwise call `action: "list_forms", plugin: "<slug>"`).
3. Synthesize the payload with an `etch/shortcode` block (or `wp:shortcode` fallback) wrapping the chosen plugin's shortcode:
   - Gravity Forms: `[gravityform id="3" title="false" description="false" ajax="true"]`
   - WPForms: `[wpforms id="123"]`
   - Fluent Forms: `[fluentform id="5"]`
   - Contact Form 7: `[contact-form-7 id="42"]`
   - Ninja Forms: `[ninja_form id="7"]`
   - Formidable: `[formidable id="9"]`
   - Forminator: `[forminator_form id="11"]`
   - Happy Forms: `[happyforms id="13"]`
   - JetFormBuilder: `[jet_form id="15"]`
4. Style-hoist any wrapper classes (`{brand}-form__wrap`, etc.) — see anti-patterns.md §15.
5. Re-call `nibwp/etchwp-pro-html-to-component` with the resolved `payload._form_decision = { plugin, form_id, shortcode_tag }`.

### What if NO form plugin is installed?

Ask the user which plugin they prefer; offer Fluent Forms (lightweight, free) as the recommended default. Direct them to install it, then re-run the conversion. Do not emit raw `<form>` HTML as a workaround.

---

## Identify (after a form plugin has been chosen)

- [ ] Form decision recorded in `payload._form_decision`.
- [ ] BEM block name for the wrapper: `{brand}-form` or `{brand}-{section}__form`.

## Tokens
- [ ] Wrapper `padding`: `--space-l` to `--space-xl`.
- [ ] Wrapper `background`: `--surface-light` / `--white`.
- [ ] Wrapper `border-radius`: `--radius-m`.
- [ ] Section heading `font-size`: `--text-xl` (1.5rem). **Never `clamp()`**.

## Structure
- [ ] Wrapper around the shortcode includes a section heading + optional intro paragraph (NOT inside the shortcode).
- [ ] Form-success / form-error messages are handled by the form plugin — do NOT emit custom success blocks.
- [ ] Wrapper classes are style-hoisted in a hidden `wp:etch/element` so Etch enqueues their CSS (see anti-patterns.md §15).

## Behavior
- [ ] Form submission handled by the form plugin (AJAX recommended; `ajax="true"` for Gravity).
- [ ] Spam protection (honeypot / reCAPTCHA / hCaptcha) configured on the form plugin side — do NOT recreate manually.
- [ ] Conditional fields handled by the form plugin.

## Responsive
- [ ] Wrapper width: `max-inline-size: min(100%, var(--content-width-narrow, 42rem))` — forms don't need to stretch full-width.
- [ ] Padding shrinks at narrow container widths.

## Pixel-perfect
- [ ] Spacing between heading → intro → shortcode wrap matches source within ±2px.
- [ ] Background contrast vs page background matches source.

## Accessibility
- [ ] Wrapper has a clear `<h2>` or `<h3>` so the form has an accessible label.
- [ ] If the chosen plugin lacks built-in `aria-required`, flag this in the conversion summary — do NOT patch it inside Etch (it would be lost on rebuild).

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
