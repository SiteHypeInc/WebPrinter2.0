# WebPrinter design token system

## Overview

Design tokens control the visual identity of a generated site without touching
content or layout. Swap the tokens and the same scraped content renders in a
completely different brand feel.

Tokens are passed in the deploy payload under `design_tokens` and applied to
Elementor's kit settings (system colors + system typography).

## Payload format

```json
{
  "design_tokens": {
    "colors": {
      "primary": "#1A3A5C",
      "secondary": "#54595F",
      "text": "#2C2C2A",
      "accent": "#D85A30"
    },
    "fonts": {
      "primary": "Montserrat",
      "secondary": "Open Sans"
    }
  }
}
```

## How tokens map to Elementor

### System colors

The plugin writes these to Elementor's kit `_elementor_page_settings`:

| Token | Elementor _id | Used for |
|-------|--------------|----------|
| `primary` | `primary` | Headings, nav links, section backgrounds |
| `secondary` | `secondary` | Subheadings, borders, subtle UI |
| `text` | `text` | Body text, paragraphs |
| `accent` | `accent` | CTA buttons, highlights, badges, hover states |

Templates reference these via Elementor's global color system:
`"__globals__": { "title_color": "globals/colors?id=primary" }`

This means changing the token changes every element that references
`primary` — no per-element updates needed.

### System typography

| Token | Elementor _id | Weight | Used for |
|-------|--------------|--------|----------|
| `fonts.primary` | `primary` | 600 | Headings, nav items, CTAs |
| `fonts.secondary` | `secondary` | 400 | Body text, descriptions |
| `fonts.secondary` | `text` | 400 | General text |
| `fonts.primary` | `accent` | 700 | Bold callouts, badges |

Fonts must be available in Google Fonts (Elementor loads them automatically).

## Template defaults

If no `design_tokens` are provided, the plugin falls back to per-template
color presets:

| Template | Primary | Accent | Vibe |
|----------|---------|--------|------|
| `authority-v2` | `#1A3A5C` (navy) | `#C9A84C` (gold) | Professional, established |
| `green-v2` | `#2E7D32` (forest) | `#4CAF50` (green) | Eco, natural, roofing |
| `premium-v2` | `#1A3A5C` (navy) | `#C9A84C` (gold) | Luxury, high-end |
| `bold-v2` | `#1B1B1B` (black) | `#D85A30` (coral) | Modern, bold, contractor |

## Preset system (planned)

For the n8n pipeline, design token presets let you rotate visual styles
across generated sites so they don't all look identical:

```
modern-minimal  → Light, clean, Montserrat/Open Sans, navy/white
bold-contractor → Dark hero, Oswald/Roboto, orange accent
warm-professional → Warm grays, Playfair Display/Source Sans, gold accent
clean-corporate → Blue/gray, Inter/Inter, subtle styling
earth-tones     → Greens/browns, Merriweather/Lato, natural feel
```

The n8n workflow can select a preset based on:
- Industry (HVAC → bold-contractor, painting → modern-minimal)
- Random rotation within an industry (so not all HVAC sites look the same)
- Client preference (if doing paid demo sites)

## Extending tokens (future)

Additional token categories under consideration:

- **Spacing**: section padding, card gaps, container width
- **Border radius**: global corner rounding (sharp vs rounded)
- **CTA style**: button shape, hover effect, text transform
- **Hero layout**: image left/right/background/none
- **Section ordering**: move testimonials before/after services

These would require template-level logic beyond what Elementor's kit
settings support — likely handled via `_wp_if` variants or template
selection in n8n rather than plugin changes.
