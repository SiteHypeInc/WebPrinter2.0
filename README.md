# WebPrinter 2.0

Automated website generation engine for SiteHype. Scrapes business content via Firecrawl, normalizes it into a standard schema, and deploys polished Elementor-based demo sites through n8n.

## Architecture

```
Firecrawl scrape → Content normalization (AI) → Content schema JSON
                                                        ↓
                              n8n orchestrator → selects template + design tokens
                                                        ↓
                         WebPrinter Engine plugin → fetches template from GitHub
                                                 → processes markers (_wp_if, _wp_repeat, _wp_img)
                                                 → injects tokens ({{dot.notation}} and [BRACKET])
                                                 → writes _elementor_data to WordPress
                                                 → busts Elementor + Breeze cache
                                                 → warm loopback request
                                                        ↓
                                              Live demo site on multisite subsite
```

## Repo structure

```
WebPrinter2.0/
├── README.md                          ← You are here
├── webprinter-engine-v5.1.php         ← WordPress plugin (drop into wp-content/plugins/)
├── docs/
│   ├── content-schema.md              ← Content schema spec + field reference
│   ├── template-authoring.md          ← How to create/convert templates
│   ├── normalization-rules.md         ← AI extraction rules for Firecrawl → schema
│   └── design-tokens.md               ← Design token system reference
├── schema/
│   ├── webprinter-content-schema.json ← JSON Schema (validation)
│   └── example-hvac.json              ← Filled example (Summit Mechanical)
└── templates/
    └── bold-v2/                       ← Example template set
        └── about.json                 ← About page with v5 markers
```

## Quick start

### 1. Install the plugin

Copy `webprinter-engine-v5.1.php` to your WordPress multisite `wp-content/plugins/` and activate network-wide.

Add to `wp-config.php`:
```php
define( 'WP_TEMPLATE_BASE', 'https://raw.githubusercontent.com/SiteHypeInc/WebPrinter2.0/main/templates' );
define( 'WP_WEBPRINTER_KEY', 'your-secret-key-here' );
```

### 2. Deploy a site via n8n

POST to `/wp-json/webprinter/v1/deploy` with the content schema payload. See `schema/example-hvac.json` for the full payload format.

### 3. Create new templates

See `docs/template-authoring.md` for the marker system. Short version: grab any Elementor JSON, drop in `_wp_repeat`, `_wp_if`, `_wp_img`, and `{{dot.tokens}}`, push to this repo.

## Plugin markers

| Marker | Where | What it does |
|--------|-------|-------------|
| `_wp_img` | Element settings | Injects sideloaded image (widget or background) |
| `_wp_if` | Element settings | Keeps/removes element based on payload data |
| `_wp_repeat` | Element settings | Clones element per array item (services, testimonials) |
| `_wp_repeat` | Inside widget settings array | Clones list item inside widget (icon-list, slides, etc.) |
| `{{field}}` | Any string value | Resolves from payload via dot notation |
| `[FIELD]` | Any string value | v4 bracket token (backward compatible) |

## Version history

- **v5.1** — Widget-internal array repeats (icon-list, price-table, slides, etc.), PHP 8.0 polyfill
- **v5.0** — Smart template engine with `_wp_repeat`, `_wp_if`, `{{dot.notation}}`, expanded design tokens, full backward compat with v4
- **v4.7** — Template-agnostic REST endpoint, `_wp_img` image slots, Breeze cache management, HFE support
