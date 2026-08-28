# Checklist — header

- [ ] It is the site's header, so it renders on every page. Nothing in it may
      assume a listing is being viewed.
- [ ] `ts-navbar` for the menu, `ts-user-bar` for the account side — messages,
      notifications, cart, avatar menu.
- [ ] A mobile menu chosen as well as a desktop one, and a breakpoint where
      the burger takes over.
- [ ] Logged-in and logged-out states both designed. `user:logged_in`
      visibility rules, or the user bar's own components.
- [ ] The "add a listing" call to action points at the post type's `form`
      page, which is `@site().post_types.{key}.create`.
- [ ] If a search sits in the header, decide whether it submits to an archive
      or to a page — a header search cannot post to a feed that is not there.
- [ ] Assigning to `slot: "header"` replaces the site header everywhere. The
      response reports what was replaced; keep that id.

{{INJECTED_FEEDBACK}}
