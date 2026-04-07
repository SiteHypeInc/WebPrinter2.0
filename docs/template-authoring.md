# WebPrinter v5 — template authoring guide

## Overview

Templates are Elementor JSON files stored on GitHub. The plugin fetches
them at deploy time and processes three custom markers before writing
to the database. Templates stay clean — one file per page, one instance
of each repeatable block.

## Processing order

1. `_wp_if` — remove sections where data is missing
2. `_wp_repeat` — clone elements per array item
3. `_wp_img` — inject images (unchanged from v4)
4. `[BRACKET TOKENS]` — v4 string replacement (backward compat)
5. `{{dot.tokens}}` — v5 path-based replacement


## Marker: _wp_if (conditional sections)

Add to any element's settings to show/hide based on payload data.

```json
{
  "elType": "container",
  "settings": {
    "_wp_if": "testimonials"
  },
  "elements": [ ... ]
}
```

- Element is KEPT if `testimonials` exists and is non-empty in payload
- Element is REMOVED entirely if missing or empty
- Supports negation: `"_wp_if": "!team"` → keep only if team is empty
- Cleans up the marker before Elementor sees it

### Common uses

| Marker value       | Keeps section when...                  |
|--------------------|----------------------------------------|
| `testimonials`     | At least one testimonial was scraped   |
| `team`             | Team members exist                     |
| `credentials`      | Licenses/certs were found              |
| `social.youtube`   | YouTube URL exists                     |
| `images.gallery`   | Gallery images were scraped            |
| `!team`            | No team data (show a generic section)  |


## Marker: _wp_repeat (variable-length content)

The big one. Put this on ONE element and the plugin clones it per array item.

```json
{
  "elType": "container",
  "settings": {
    "_wp_repeat": "services",
    "_wp_repeat_min": 3,
    "_wp_repeat_max": 8
  },
  "elements": [
    {
      "elType": "widget",
      "widgetType": "heading",
      "settings": {
        "title": "{{services._item.name}}"
      }
    },
    {
      "elType": "widget",
      "widgetType": "text-editor",
      "settings": {
        "editor": "{{services._item.description}}"
      }
    }
  ]
}
```

### How it works

1. Plugin finds `_wp_repeat: "services"` on the element
2. Reads `payload.services` array (e.g. 6 items)
3. Clones the entire element tree 6 times
4. Each clone gets a fresh Elementor element ID
5. `{{services._item.name}}` resolves to that clone's item
6. Markers are cleaned up

### Token syntax inside repeats

| Token                              | Resolves to                        |
|------------------------------------|------------------------------------|
| `{{services._item.name}}`         | Current service's name             |
| `{{services._item.description}}`  | Current service's description      |
| `{{services._item.icon_hint}}`    | Current service's icon hint        |
| `{{_index}}`                       | 0-based index (0, 1, 2...)         |
| `{{_position}}`                    | 1-based position (1, 2, 3...)      |

### Options

| Setting            | Default | Purpose                                    |
|--------------------|---------|--------------------------------------------|
| `_wp_repeat_min`   | 0       | Pad array with empty items if fewer exist  |
| `_wp_repeat_max`   | 12      | Cap at this many clones                    |

### Supported arrays

| Array key        | Typical items | Template section      |
|------------------|---------------|-----------------------|
| `services`       | 3-8           | Services grid/cards   |
| `testimonials`   | 1-6           | Testimonial slider    |
| `process_steps`  | 3-5           | How it works steps    |
| `team`           | 1-8           | Team member cards     |
| `credentials`    | 2-6           | Trust badge bar       |
| `service_areas`  | 3-10          | Service area list     |


## Marker: _wp_img (image injection — unchanged from v4)

```json
{
  "elType": "container",
  "settings": {
    "_wp_img": "hero",
    "background_background": "classic"
  }
}
```

Slot names: `hero`, `about`, `service`, `logo`, `gallery_0`, `gallery_1`, etc.

For image widgets (logo, team photos):
```json
{
  "elType": "widget",
  "widgetType": "image",
  "settings": {
    "_wp_img": "logo"
  }
}
```


## Tokens: {{dot.notation}} (v5)

Resolve any value from the payload using dot paths:

| Token                     | Payload path                    | Example output          |
|---------------------------|---------------------------------|-------------------------|
| `{{business_name}}`       | `payload.business_name`         | Summit Mechanical       |
| `{{tagline}}`             | `payload.tagline`               | Your trusted HVAC...    |
| `{{contact.phone}}`       | `payload.contact.phone`         | (303) 555-0147          |
| `{{contact.email}}`       | `payload.contact.email`         | info@summit.com         |
| `{{contact.address.city}}`| `payload.contact.address.city`  | Denver                  |
| `{{about_short}}`         | `payload.about_short`           | Family-owned since...   |
| `{{about_long}}`          | `payload.about_long`            | Full about text...      |
| `{{industry}}`            | `payload.industry`              | hvac                    |
| `{{cta.primary_text}}`    | `payload.cta.primary_text`      | Get a free estimate     |

Arrays and objects resolve to empty string — use `_wp_repeat` for those.


## Tokens: [BRACKET FORMAT] (v4 backward compat)

All v4 tokens still work. The plugin generates them from the structured
payload automatically:

`[COMPANY NAME]`, `[TAGLINE]`, `[HERO HEADLINE]`, `[HERO SUB]`,
`[TRADE]`, `[CITY, STATE]`, `[PHONE]`, `[EMAIL]`, `[ADDRESS]`,
`[ABOUT]`, `[YEARS IN BUSINESS]`, `[SERVICE 1-12 NAME]`,
`[SERVICE 1-12 description]`, `[PROCESS 1-6 TITLE]`,
`[PROCESS 1-6 DESC]`, `[TESTIMONIAL 1-6 TEXT/NAME/TITLE]`


## Design tokens

Pass in the payload to control Elementor kit styling:

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

If not provided, falls back to per-template defaults (authority-v2, green-v2, etc.)


## Example: services.json template (v5)

```json
{
  "content": [
    {
      "elType": "container",
      "settings": { "content_width": "boxed" },
      "elements": [
        {
          "elType": "widget",
          "widgetType": "heading",
          "settings": {
            "title": "Our services",
            "header_size": "h2"
          }
        },
        {
          "elType": "container",
          "settings": {
            "flex_direction": "row",
            "flex_wrap": "wrap",
            "content_width": "full"
          },
          "elements": [
            {
              "elType": "container",
              "settings": {
                "_wp_repeat": "services",
                "_wp_repeat_max": 8,
                "flex_basis": "calc(33.33% - 20px)",
                "padding": { "top": "20", "right": "20", "bottom": "20", "left": "20" }
              },
              "elements": [
                {
                  "elType": "widget",
                  "widgetType": "heading",
                  "settings": {
                    "title": "{{services._item.name}}",
                    "header_size": "h3"
                  }
                },
                {
                  "elType": "widget",
                  "widgetType": "text-editor",
                  "settings": {
                    "editor": "{{services._item.description}}"
                  }
                }
              ]
            }
          ]
        }
      ]
    },
    {
      "elType": "container",
      "settings": { "_wp_if": "testimonials" },
      "elements": [
        {
          "elType": "widget",
          "widgetType": "heading",
          "settings": { "title": "What our customers say", "header_size": "h2" }
        },
        {
          "elType": "container",
          "settings": {
            "_wp_repeat": "testimonials",
            "_wp_repeat_max": 3
          },
          "elements": [
            {
              "elType": "widget",
              "widgetType": "text-editor",
              "settings": {
                "editor": "<em>\"{{testimonials._item.quote}}\"</em>"
              }
            },
            {
              "elType": "widget",
              "widgetType": "heading",
              "settings": {
                "title": "— {{testimonials._item.author}}",
                "header_size": "h4"
              }
            }
          ]
        }
      ]
    }
  ]
}
```

One service card, one testimonial card. Plugin clones them to match the data.


## Migration from v4 templates

Existing v4 templates with [BRACKET TOKENS] and _wp_img markers work
unchanged. To upgrade a template:

1. Replace fixed service blocks with one `_wp_repeat: "services"` block
2. Replace fixed testimonial blocks with one `_wp_repeat: "testimonials"` block
3. Wrap optional sections in `_wp_if` (testimonials, team, credentials)
4. Optionally switch bracket tokens to dot notation
5. Test with the v5 plugin — both token formats process on every deploy
