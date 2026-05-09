# WebPrinter content normalization rules

## Overview
These rules govern how raw Firecrawl output gets mapped into the
WebPrinter content schema. The normalizer runs as an AI node in n8n
(Claude via API call) with this prompt context.

## Field extraction priority

### business_name
1. `<title>` tag (strip " | Home", " - Official Site", etc.)
2. `<meta property="og:site_name">`
3. Logo alt text
4. First `<h1>` on homepage

### tagline
1. Homepage `<h1>` (if different from business name)
2. `<meta name="description">` (truncate to 120 chars)
3. First `<h2>` under hero section
4. **Fallback**: AI generates from about text + industry

### industry
Map to enum based on keywords in services, about text, and meta:
- "heating", "cooling", "hvac", "furnace", "air conditioning" → `hvac`
- "roof", "shingle", "gutter" → `roofing`
- "plumb", "pipe", "drain", "water heater" → `plumbing`
- "electric", "wiring", "panel", "outlet" → `electrical`
- "paint", "stain", "coating" → `painting`
- "floor", "tile", "hardwood", "carpet" → `flooring`
- "siding", "vinyl", "fiber cement" → `siding`
- "drywall", "sheetrock", "plaster" → `drywall`
- "saas", "software-as-a-service", "platform", "dashboard", "subscription",
  "api", "cloud software", "web app", "mobile app", "estimating software",
  "crm", "erp" → `saas`
- "agency", "marketing agency", "creative agency", "consulting", "studio",
  "design firm", "branding", "ai agency", "digital agency" → `agency`
- Multiple matches or unclear → `general_contractor`
- None of the above → `other`

### about_short
1. First 1-2 sentences of About page content
2. Homepage hero paragraph
3. **Fallback**: AI summarizes about_long to 250 chars

### about_long
1. Full About page body content (strip nav, footer, sidebar)
2. "About Us" section on homepage
3. **Fallback**: Combine business_name + industry + year_founded
   into a basic paragraph

### services[]
1. Dedicated Services page — each `<h2>` or `<h3>` = service name,
   following `<p>` = description
2. Homepage services grid/cards
3. Navigation menu items under "Services" dropdown
4. **Rules**:
   - Deduplicate similar names ("AC Repair" and "Air Conditioning Repair" → keep one)
   - Cap descriptions at 200 chars
   - Flag top 2-3 as `is_primary` based on page prominence (listed first, larger cards, etc.)
   - Set `icon_hint` by matching service name to common icons

### testimonials[]
1. Dedicated Testimonials/Reviews page
2. Homepage testimonial section
3. Embedded Google/Yelp review widgets (extract visible text)
4. **Rules**:
   - Max 6, prefer 5-star reviews
   - Strip "Read more" truncation — get full quote if possible
   - Extract author name, strip last names to initial if full name seems private
   - Detect source from surrounding HTML (Google widget, Yelp badge, etc.)

### contact
- Phone: Look for `tel:` links, header/footer phone numbers
- Email: Look for `mailto:` links (skip generic noreply@)
- Address: Look for `<address>` tag, Google Maps embed, schema.org LocalBusiness
- Hours: Look for hours tables, schema.org openingHours, "Hours" sections

### images
- Logo: `<img>` in header/nav, or `<link rel="icon">` as last resort
- Hero: Largest image in hero/banner section (min 800px wide)
- About: First image on About page
- Gallery: Images from Gallery/Portfolio/Projects page
- **Rules**:
   - Skip icons, social badges, and images under 200px wide
   - Skip stock photo CDN domains (shutterstock, istock, adobe) — these aren't ownable
   - Generate alt text via AI if alt attribute is empty

### credentials[]
Scan for keywords in body text, footer, sidebar:
- "licensed", "insured", "bonded" → license
- "certified", "EPA", "NATE", "certification" → certification
- "award", "best of", "top rated", "A+ rated" → award
- "member", "association", "chamber" → membership

### social
Scan `<a>` tags for known social media domains. Extract from
header, footer, or sidebar links.

## Handling missing data

The `_meta.missing_fields` array tracks what couldn't be extracted.
Template behavior per missing field:

| Missing field     | Template behavior                           |
|-------------------|---------------------------------------------|
| tagline           | AI generates from about + industry          |
| about_short       | AI summarizes about_long                    |
| about_long        | Hide About section, use about_short in hero |
| services          | BLOCK — cannot generate site without these  |
| testimonials      | Hide testimonials section entirely          |
| team              | Hide team section (most sites skip this)    |
| images.hero       | Use industry-appropriate placeholder        |
| images.logo       | Text-only logo using business_name          |
| contact.hours     | Hide hours row in contact section           |
| contact.phone     | Use email as primary CTA                    |
| credentials       | Hide trust badge bar                        |
| social            | Hide social icons in header/footer          |

## Confidence scoring

The `_meta.confidence` score (0-1) is calculated as:

- Start at 1.0
- Subtract 0.05 for each missing required-ish field (tagline, about, contact.phone)
- Subtract 0.10 for each missing critical field (services with < 2 items)
- Subtract 0.03 for each AI-generated fallback used
- Subtract 0.15 if no images extracted at all
- Floor at 0.0

Sites scoring below 0.5 get flagged for human review before publishing.

## Content length rules for template fit

| Field             | Min   | Target | Max    | Overflow handling           |
|-------------------|-------|--------|--------|-----------------------------|
| business_name     | 2ch   | 15-30  | 50ch   | Truncate in nav, full elsewhere |
| tagline           | 10ch  | 40-80  | 120ch  | Line wrap in hero           |
| about_short       | 30ch  | 120-200| 250ch  | Truncate with ellipsis      |
| about_long        | 100ch | 400-800| 1500ch | Scroll or accordion         |
| service.name      | 3ch   | 15-30  | 50ch   | Truncate                    |
| service.desc      | 20ch  | 80-150 | 200ch  | Truncate with ellipsis      |
| testimonial.quote | 20ch  | 100-200| 300ch  | Truncate with ellipsis      |
