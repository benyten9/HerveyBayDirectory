# Checklist — style kit

A style kit is not a page. It is one widget carrying settings, on a template
whose whole purpose is to be pointed at by `templates.kit_popups` or
`templates.kit_timeline`.

- [ ] Exactly one widget in the document: `ts-test-widget-1` for popups,
      `ts-timeline-kit` for the timeline. Anything else fails the build.
- [ ] The user has been told, in plain words, that this restyles **every**
      popup on the site — every filter dropdown, every date picker, every
      menu — or the whole timeline. Then `confirm_kit: true`.
- [ ] The previous kit template id, from the response, written down. That is
      how it gets undone.
- [ ] Colors taken from `nibwp/design-direction` rather than picked, so the
      popups match the site instead of competing with it.
- [ ] Contrast checked on the popup background against its text. Popups sit
      over content and inherit nothing.
- [ ] If the goal was one popup looking different, this is the wrong tool —
      per-filter popup settings live on the search form's filter rows
      (`filt_custom_popup_enable`).

{{INJECTED_FEEDBACK}}
