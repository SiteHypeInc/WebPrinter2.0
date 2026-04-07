# WebPrinter content schema

## Overview

Every business site scraped by Firecrawl gets normalized into this single JSON
structure before it hits the n8n pipeline. The schema is the contract between
scraping, template selection, and Elementor injection.

The full JSON Schema for validation is in `schema/webprinter-content-schema.json`.
A filled example is in `schema/example-hvac.json`.

## Required fields

Only three fields are truly required — everything else is optional and the
template adapts via `_wp_if` markers:

| Field | Type | Purpose |
|-------|------|---------|
| `business_name` | string | Company name everywhere — nav, footer, title tag |
| `industry` | enum | Template selection + fallback content generation |
| `services` | array (min 1) | Core offerings — the minimum viable demo site needs at least one |

## Field reference

### Identity

| Field | Type | Max length | Template slot |
|-------|------|-----------|--------------|
| `business_name` | string | 50 | Nav logo, footer, title tag, CTA |
| `tagline` | string | 120 | Hero headline |
| `industry` | enum | — | Template selection, fallback content |
| `about_short` | string | 250 | Hero subtext |
| `about_long` | string | 1500 | About page body |
| `year_founded` | integer/null | — | "X years experience" badges |

### Industry enum values

`hvac`, `roofing`, `plumbing`, `electrical`, `painting`, `flooring`,
`siding`, `drywall`, `general_contractor`, `landscaping`, `cleaning`,
`pest_control`, `other`

### Services array

```json
{
  "name": "AC Installation",
  "description": "Central air, mini-splits, and ductless systems.",
  "icon_hint": "snowflake",
  "is_primary": true
}
```

- Templates handle 3-8 items gracefully via `_wp_repeat`
- `is_primary` flags get featured placement (hero cards, top of grid)
- `icon_hint` maps to template icon library (Elementor icons, Font Awesome, etc.)

### Testimonials array

```json
{
  "quote": "Best contractor in the area.",
  "author": "Sarah M., Lakewood",
  "rating": 5,
  "source": "google"
}
```

Source enum: `google`, `yelp`, `bbb`, `facebook`, `website`, `other`

### Contact object

```json
{
  "phone": "(303) 555-0147",
  "email": "info@summit.com",
  "address": {
    "street": "4521 W Colfax Ave",
    "city": "Denver",
    "state": "CO",
    "zip": "80204"
  },
  "hours": [
    { "days": "Mon-Fri", "hours": "8:00 AM - 5:00 PM" },
    { "days": "Sat", "hours": "9:00 AM - 2:00 PM" }
  ]
}
```

### Images object

```json
{
  "logo": { "url": "https://...", "alt": "Company logo", "width": 280, "height": 64 },
  "hero": { "url": "https://...", "alt": "Hero image" },
  "about": { "url": "https://...", "alt": "Team photo" },
  "gallery": [ { "url": "...", "alt": "..." } ],
  "team_photo": null
}
```

The plugin sideloads images into the WordPress media library, deduplicates
by source URL, and injects the local URL + attachment ID into Elementor elements
marked with `_wp_img`.

### Credentials array

```json
{ "name": "EPA Certified", "type": "certification" }
```

Type enum: `license`, `certification`, `award`, `membership`, `insurance`

### Social object

Direct URLs to social profiles. Injected into header/footer icon widgets.

### CTA object

```json
{
  "primary_text": "Get a free estimate",
  "primary_url": "#contact",
  "secondary_text": "Call now",
  "phone_display": "(303) 555-0147"
}
```

### SEO object

Auto-generated from content if not explicitly scraped.

```json
{
  "title": "Summit Mechanical | Denver HVAC Repair",
  "description": "Denver's trusted HVAC company since 2003...",
  "keywords": ["denver hvac", "furnace repair denver"]
}
```

### Metadata (_meta)

Pipeline metadata — not injected into templates:

| Field | Purpose |
|-------|---------|
| `source_url` | Original URL scraped |
| `scraped_at` | ISO timestamp |
| `schema_version` | Currently `1.0` |
| `confidence` | 0-1 extraction quality score |
| `missing_fields` | Fields that couldn't be extracted |
| `template_id` | Selected template (set by n8n) |
| `design_token_id` | Design preset applied (set by n8n) |

## Missing field behavior

| Missing field | Template behavior |
|---------------|-------------------|
| `tagline` | AI generates from about + industry |
| `about_short` | AI summarizes about_long |
| `about_long` | Hide about section, use about_short in hero |
| `testimonials` | Hide testimonials section (`_wp_if`) |
| `team` | Hide team section |
| `images.hero` | Use industry-appropriate placeholder |
| `images.logo` | Text-only logo using business_name |
| `contact.hours` | Hide hours row |
| `credentials` | Hide trust badge bar |
| `social` | Hide social icons |

Sites with confidence below 0.5 get flagged for human review.
