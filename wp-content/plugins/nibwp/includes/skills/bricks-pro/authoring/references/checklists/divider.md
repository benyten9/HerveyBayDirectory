# Divider — Bricks element checklist

## Identify
- [ ] Source has a horizontal rule, line break, or decorative separator between sections
- [ ] BEM block: `{brand}-divider` (when distinctive) or just per-element settings
- [ ] Bricks elements: `divider` (line) or `shape-divider` (SVG shape between sections)

## Settings (`divider`)
- [ ] `settings.style = "solid"` | "dashed" | "dotted" | "double"
- [ ] `settings.color = "var(--border-color-light, rgba(0,0,0,.08))"`
- [ ] `settings.thickness = "1px"` (Bricks default) — set explicitly for clarity
- [ ] `settings.width = "100%"` (default) or constrained to centered container

## Settings (`shape-divider`)
- [ ] Use only for between-section SVG shapes (waves, triangles, tilts)
- [ ] `settings.shape` = `waves` | `triangle` | `tilt` | `arrow` | `custom`
- [ ] `settings.color = "var(--surface-light, #fff)"` (the shape's fill, usually matches the NEXT section's background)
- [ ] `settings.height = "5rem"` (per-breakpoint — shrink on mobile)
- [ ] `settings.flipHorizontal` / `settings.flipVertical` per source

## Anti-patterns
- [ ] DO NOT use raw `<hr>` in an `html` element — use Bricks `divider`
- [ ] DO NOT inline `<svg>` for shape dividers — use Bricks `shape-divider`
- [ ] DO NOT use a `block` with `border-bottom` instead of `divider` — semantically wrong + harder to maintain

## Per-breakpoint
- [ ] `shape-divider` height shrinks on mobile (heroes with shape dividers look cramped at 5rem on 320px viewports — drop to 2.5rem)

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
