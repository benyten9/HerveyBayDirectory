# Tabs — Bricks element checklist

## Identify
- [ ] Source has tabbed panels — one visible at a time, switched via tab buttons
- [ ] BEM block: `{brand}-tabs` with children `__tablist`, `__tab`, `__panel`
- [ ] Bricks element: **`tabs-nested`** (preferred — children are individual nested tabs). Legacy `tabs` (items array) only for simple text panels.

## Structure
- [ ] `tabs-nested` root with `_cssGlobalClasses = ["{brand}-tabs"]`
- [ ] Each tab is a CHILD element with its own panel subtree
- [ ] Tab labels live in `settings.title` on each child OR via the tab heading inside the panel

## Behavior
- [ ] `settings.layout = "horizontal"` (default) or "vertical" (sidebar-style)
- [ ] `settings.initialTab` set (0-indexed) if not the first
- [ ] `settings.deeplink = true` to sync the active tab with URL hash (Bricks renders this)

## Accessibility
- [ ] Tab list is `role="tablist"` (Bricks renders this when configured)
- [ ] Each tab is `role="tab"` with `aria-selected` toggled
- [ ] Each panel is `role="tabpanel"` with `aria-labelledby` pointing at its tab
- [ ] Keyboard: Left/Right arrows switch tabs, Home/End jump to first/last (Bricks built-in)

## Tokens
- [ ] Active tab indicator: `border-bottom: 2px solid var(--primary, #2271b1);`
- [ ] Inactive tab text: `var(--text-muted, rgba(0,0,0,.65))`
- [ ] Active tab text: `var(--heading-color, var(--text-dark, #1a1a1a))`
- [ ] Panel padding: `var(--space-l, 2rem) var(--space-m, 1rem)`

## Per-breakpoint
- [ ] On `_mobile_portrait`, switch to vertical layout OR collapse tabs into an accordion (Bricks `_layoutMobile = "accordion"` setting)
- [ ] Tab labels truncate (`text-overflow: ellipsis`) when many tabs squeezed into narrow viewport

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
