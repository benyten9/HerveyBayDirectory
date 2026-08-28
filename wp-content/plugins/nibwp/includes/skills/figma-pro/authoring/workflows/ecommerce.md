# Workflow — Figma → WooCommerce Product Page

> Every convert workflow ends the same way: **pixel-diff verify** (Playwright render vs. Figma 2x export) → **warnings report** → **persist as draft**. Live templates are never overwritten.

Map a product design frame onto WooCommerce single-product **template regions**.
This **restyles / relayouts** the template — it does **not** replace WooCommerce
cart / checkout / pricing logic. Woo hooks and dynamic product data stay intact;
the design wraps native regions, it never hard-codes product content.

## Input

- A Figma URL of the product-page frame (`file_key` + `node-id`).
- A WooCommerce-active target site with a builder that supports Woo template parts.
- Optional viewport widths — default `1440 / 768 / 390`.

## Abilities used

| Ability | Role in this workflow |
|---|---|
| `figma-pro-convert` | Full pipeline in Woo-template mode: resolve → fetch → region-map → tokens → build → verify → draft |
| `figma-pro-tokens` | Establish the token system the template binds to |
| `figma-pro-detect-builder` | Restrict to builders that can build Woo templates |
| `figma-pro-preview` | Optional dry-run + diff score against a representative product |

Figma read integration abilities: `figma-get-file`, `figma-get-node`,
`figma-get-variables`, `figma-get-styles`, `figma-export-node`.

## Steps

1. **Resolve + fetch.** `figma-get-file` → locate the product frame;
   `figma-get-node` → subtree.
2. **Region-map.** Match design areas to Woo template regions / hooks:

   | Design area | Woo region / hook |
   |---|---|
   | Image stack | product gallery (`woocommerce_show_product_images`) |
   | Heading | product title |
   | Price block | price (`woocommerce_template_single_price`) |
   | CTA + qty | add-to-cart form (`woocommerce_template_single_add_to_cart`) |
   | Tabbed content | product tabs / reviews (`woocommerce_output_product_data_tabs`) |
   | Carousel row | related / upsells |

3. **Tokens.** `figma-get-variables` + `figma-get-styles` → token map. Raw hex
   not bound to a Variable is flagged for the warnings report.
4. **Baseline export.** `figma-export-node` 2x → reference PNG per viewport.
5. **Detect builder + delegate.** Builder must support Woo template building
   (Bricks / Elementor Pro Theme Builder / EtchWP loop-aware). Build the
   single-product template, wiring **native Woo dynamic data** into the styled
   regions — never hard-coded product content.
6. **Pixel-diff verify** against the export, using a representative product →
   diff score + region heatmap.
7. **Persist as draft** template + warnings report (e.g. gallery aspect mismatch).

## Builder applicability

| Signal | Preferred builder |
|---|---|
| Bricks active, Woo templates | **Bricks** |
| Elementor Pro (Theme Builder) active | **Elementor Pro** |
| EtchWP / ACSS active, loop-aware | **EtchWP** |
| No Woo-capable builder | **Gutenberg** — only where block-based Woo templates are viable |

## Slash commands

| Command | Invokes | Effect |
|---|---|---|
| `/figma analyze` | read-only | Reports the frame's regions / components / tokens. No writes. |
| `/figma convert <target>` | `figma-pro-convert` | Runs the Woo-template pipeline → verified draft template |
| `/figma extract tokens` | `figma-pro-tokens` | Maps Figma Variables + styles into a builder token system |
| `/figma detect builder` | `figma-pro-detect-builder` | Surfaces the recommended Woo-capable builder + reasons |

## Output

A WooCommerce single-product template whose regions (gallery, title, price,
add-to-cart, tabs / reviews, related) carry the design while live Woo logic and
dynamic data stay intact — saved as a **draft**, with a pixel-diff score and
warnings report.

**Builders:** Bricks, Elementor Pro (Theme Builder), EtchWP. Gutenberg only where
block-based Woo templates are viable.
