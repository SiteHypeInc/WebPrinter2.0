---
name: webprinter
description: Convert Elementor template kits into WebPrinter-compatible templates and deploy automated websites. Use when working with WebPrinter 2.0, converting templates, deploying sites, managing the plugin, or working with the n8n pipeline. Triggers on mentions of WebPrinter, template conversion, Elementor templates, site deployment, kit.json, or the marker system (_wp_repeat, _wp_if, _wp_img, _wp_stock).
---

# WebPrinter 2.0 — Agent Skill

## What WebPrinter Is

WebPrinter is an automated website generation engine. It takes any Elementor template, fills it with scraped business content, and deploys a fully editable WordPress site. The output is native Elementor — clients can edit everything in the visual editor.

**The pipeline:**
```
URL in → Firecrawl scrapes → Claude normalizes to schema → Plugin deploys to WordPress → Styled editable site out
```

**Repo:** github.com/SiteHypeInc/WebPrinter2.0
**Test site:** webprinter-test.hostingersite.com
**Plugin version:** v5.2-gold (file in repo: `webprinter-engine-v5.2-gold.php`)

## Repo Structure

```
WebPrinter2.0/
├── webprinter-engine-v5.2-gold.php    ← WordPress plugin (DO NOT MODIFY without approval)
├── tools/
│   └── convert_template.py           ← Template converter script
├── docs/
│   ├── content-schema.md             ← Content schema field reference
│   ├── template-authoring.md         ← Marker system guide
│   ├── normalization-rules.md        ← AI extraction rules
│   └── design-tokens.md             ← Color/font token system
├── schema/
│   ├── webprinter-content-schema.json ← JSON Schema for validation
│   └── example-hvac.json             ← Filled example payload
└── templates/
    ├── _stock/
    │   ├── manifest.json             ← Per-trade stock photo URLs (must cover every active trade)
    │   └── README.md
    └── {template-name}/              ← One folder per template kit
        ├── kit.json                  ← Full Elementor kit settings
        ├── home.json
        ├── about.json
        ├── services.json
        ├── quote.json
        ├── contact.json
        ├── header.json               ← HFE template (not a page)
        └── footer.json               ← HFE template (not a page)
```

## The Marker System

Markers go inside Elementor JSON template files. The plugin processes and strips them at deploy time — the final site has no markers, just clean Elementor data.

### Text tokens — `{{dot.notation}}`
```json
{"title": "{{business_name}}"}
{"editor": "{{about_long}}"}
{"text": "{{cta.primary_text}}"}
```
Common: `{{business_name}}`, `{{tagline}}`, `{{about_short}}`, `{{about_long}}`, `{{contact.phone}}`, `{{contact.email}}`, `{{contact.address.city}}`, `{{contact.address.state}}`, `{{cta.primary_text}}`, `{{year_founded}}`

### _wp_repeat — Clone elements per array item
```json
{"settings": {"_wp_repeat": "services", "_wp_repeat_max": 8}}
```
DELETE duplicate siblings — keep ONE with the marker. Plugin clones it N times.

Supported arrays: `services`, `testimonials`, `process_steps`, `team`, `credentials`, `service_areas`

For widget-internal arrays (icon-list, slides):
```json
{"icon_list": [{"text": "{{credentials._item.name}}", "_wp_repeat": "credentials"}]}
```

### _wp_if — Conditional sections
```json
{"settings": {"_wp_if": "testimonials"}}
```
Removed entirely if data is empty. Use on: `testimonials`, `team`, `credentials`, `pricing`, `social`, `images.gallery`

### _wp_img — Scraped client images
```json
{"settings": {"_wp_img": "logo", "image": {"url": "", "id": 0}}}
```
Slots: `logo`, `hero`, `about`. Zero the URL when adding the marker.

### _wp_stock — Random trade stock photos
```json
{"settings": {"_wp_stock": "action"}}
```
Categories: `hero`, `action`, `team`, `equipment`, `generic`

### _wp_keep — Do not replace
```json
{"settings": {"_wp_keep": true}}
```
For SVG icons, decorative design elements that should stay as-is.

### Auto-purge
Any image widget or `background_image` without a marker gets replaced with random trade stock at deploy time. **Failure mode:** If `_stock/manifest.json` is missing entries for the target trade, auto-purge fires but has no URLs to inject — resulting in empty image cards. Always verify the manifest covers the trade before deploying.

## Converting a New Template Kit

### Step 1: Extract the kit
Unzip the template kit. Find `global.json` (kit settings) and individual page JSONs.

### Step 2: Create kit.json
```python
import json
with open('global.json') as f:
    d = json.load(f)
kit = d.get('page_settings', {})
kit['_kit_name'] = 'template-name'
kit['_kit_version'] = '1.0'
with open('kit.json', 'w') as f:
    json.dump(kit, f, indent=2)
```
**Kit passthrough:** Everything in kit.json is applied to `_elementor_page_settings` EXCEPT these reserved keys: `system_colors`, `custom_colors`, `system_typography`, `custom_typography`, `_kit_name`, `_kit_version`, `_source`. Those are handled separately. All other keys flow through automatically — `body_background_*`, custom CSS, viewports, everything.

### Step 3: Run the converter (v2 — aggressive mode)
```bash
python3 tools/convert_template.py input.json --report   # See section detection
python3 tools/convert_template.py input.json > output.json  # Convert
python3 tools/convert_template.py output.json --audit    # Verify zero niche copy leaks (exits 1 if any)
```

v2 replaces ALL `heading` and `text-editor` widgets with tokens unless the text matches `SECTION_LABELS` (e.g. "About", "Services", "FAQ", "Contact") or is a short decorative label like "1", "case 1", "step 3". Token choice depends on section context (hero/about/services/testimonials/contact/pricing/unknown) and on whether the widget sits inside a `_wp_repeat` card. Mark widgets you want preserved literally with `_wp_keep: true`.

### Step 4: Cleanup pass (header/footer + images only)
The aggressive converter handles section body copy. Manual passes still needed for:
1. Header: `_wp_img: "logo"`, `{{business_name}}`, `{{cta.primary_text}}` (these widgets are usually outside the section classifier)
2. Footer: same + `{{contact.email}}`, `{{contact.phone}}`, nav columns
3. Brand names inside image URLs — leave them, auto-purge handles those
4. If the audit reports leaks, either widen `SECTION_LABELS` in the converter or mark the widget with `_wp_keep: true`

### Step 5: Rename files to match slugs
Pages (WordPress pages): `home`, `about`, `services`, `quote`, `contact`
HFE templates (Header Footer Elementor post type): `header`, `footer`

Common renames: `features.json → services.json`, `pricing.json → quote.json`

### Step 6: Push to `templates/{template-name}/`

### Step 7: Test deploy + verify rendered page
Deploy response `success: true` does NOT guarantee correct visual output. Always check the actual page.

## WordPress Site Setup

1. **Plugins:** Elementor, Header Footer Elementor (HFE), WebPrinter Engine v5.2-gold
2. **Theme:** Hello Elementor
3. **Pages:** 5 with exact slugs: `home`, `about`, `services`, `quote`, `contact`
4. **wp-config.php:**
```php
define('WP_TEMPLATE_BASE', 'https://raw.githubusercontent.com/SiteHypeInc/WebPrinter2.0/main/templates');
define('WP_WEBPRINTER_KEY', 'your-secret-key');  // optional
```
5. **Verify:** `curl -k https://{site}/wp-json/webprinter/v1/health` → `{"status":"ok","version":"5.2"}`

## Operations

### Plugin replacement on Hostinger
Hostinger blocks REST plugin endpoints (`DISALLOW_FILE_MODS`). Use the wp-admin cookie-auth flow:

1. `POST /wp-login.php` → capture `wordpress_logged_in_*` cookies
2. `GET /wp-admin/plugin-install.php?tab=upload` → extract `_wpnonce`
3. `POST /wp-admin/update.php?action=upload-plugin` with zipped plugin file
4. Follow overwrite URL (`&overwrite=update-plugin`)
5. Verify: health endpoint returns expected version

### Hostinger hCDN bot challenge
Hostinger's hCDN intercepts automated POST requests to `/wp-json/*` from certain IPs (including n8n on Digital Ocean). Returns a JS bot challenge page instead of JSON.

**Symptom:** n8n deploy node returns HTML instead of JSON, or times out.
**Fix:** Whitelist `/wp-json/*` in hPanel → Website → CDN settings, or disable hCDN.
**Workaround:** Deploy via direct curl from a local machine (bypasses hCDN).

This is NOT a plugin or auth bug — it's infrastructure.

## n8n Pipeline

Canonical flow: Webhook → Firecrawl scrape → Claude normalize → Deploy to WP → Respond to Webhook (+ Cache Warm branch)

The deploy node sends BOTH v5 nested format AND v4 flat format for backward compat. Template selection mapped per trade in the payload builder.

## Content Schema (Key Fields)

```json
{
  "business_name": "string",
  "tagline": "string, max 120 chars",
  "industry": "hvac|roofing|plumbing|electrical|painting|flooring|siding|drywall|other",
  "about_short": "string, max 250 chars",
  "about_long": "string, max 1500 chars",
  "services": [{"name": "...", "description": "...", "is_primary": true}],
  "testimonials": [{"quote": "...", "author": "..."}],
  "contact": {"phone": "...", "email": "...", "address": {"street":"","city":"","state":"","zip":""}},
  "images": {"logo": {"url":"..."}, "hero": {"url":"..."}, "about": {"url":"..."}},
  "credentials": [{"name": "BBB A+ Rated", "type": "award"}],
  "cta": {"primary_text": "Get a free estimate"},
  "design_tokens": {"colors": {"primary": "#hex", "secondary": "#hex", "text": "#hex", "accent": "#hex"}},
  "brand_mode": "template|client"
}
```

## brand_mode — Two-Version Pitch

- `"brand_mode": "template"` (default) → kit.json design as-is
- `"brand_mode": "client"` → overrides kit system colors with `design_tokens.colors`

Deploy twice with different brand_mode → two visual identities, same content. The agency pitch: "here's our design, and here's YOUR brand."

## Stock Photo Library

Location: `templates/_stock/manifest.json`

Fallback chain: trade.category → trade.generic → general.category → general.generic

**Every active trade MUST have at least a `generic` array with working URLs.** Missing entries = empty image boxes on the rendered site.

## Rules

1. **NEVER modify the plugin** without John's explicit approval
2. **NEVER modify templates on the live site** — changes go through GitHub repo, then redeploy
3. **Always verify rendered pages** — `success: true` ≠ correct visual output
4. **Image URLs in template JSON** get auto-purged at deploy time — don't fix them manually
5. **Converter v2 = aggressive mode** — zero niche copy should survive `--audit`; only header/footer chrome + image markers need a manual pass
6. **Header/footer** need extra attention — they appear on every page
7. **Check stock manifest** before deploying to a new trade
