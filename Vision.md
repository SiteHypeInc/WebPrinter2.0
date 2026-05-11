# WebPrinter Vision Analysis System

## Overview

Two modes, one tool:
- **ANALYZE mode:** Extract design DNA from a SOURCE site screenshot → feeds template selection + brand colors
- **QA mode:** Score a DEPLOYED site screenshot against quality rubric → gates outreach

Both use Claude API with vision. Same n8n node, different prompts.

---

## MODE 1: Design DNA Extraction (Source Site Analysis)

### When to use
Before deploying — analyze the target business's existing website to extract
design characteristics that inform template selection and brand color mapping.

### System prompt

```
You are a website design analyst for WebPrinter, an automated website generation engine.
Analyze the screenshot of this business website and extract its design DNA.
Return ONLY valid JSON — no markdown, no backticks, no explanation.

Extract:

{
  "design_profile": {
    "theme": "dark|light|mixed",
    "primary_color": "#hex — dominant brand color (header, nav, hero background)",
    "secondary_color": "#hex — supporting color (text backgrounds, cards)",
    "accent_color": "#hex — CTA/highlight color (buttons, links, badges)",
    "text_color": "#hex — primary body text color",
    "background_color": "#hex — main page background",
    "gradient": "none|subtle|bold — does the site use gradients prominently?",
    "gradient_colors": ["#hex", "#hex"] or null,

    "typography": {
      "heading_style": "sans-serif|serif|monospace|display",
      "heading_weight": "light|normal|bold|black",
      "body_style": "sans-serif|serif|monospace",
      "size_scale": "compact|normal|large|oversized",
      "vibe": "modern|traditional|playful|corporate|technical|elegant"
    },

    "layout": {
      "structure": "single-column|two-column|card-grid|magazine|hero-driven",
      "density": "minimal|balanced|dense",
      "whitespace": "generous|moderate|tight",
      "section_count": 0,
      "has_hero": true,
      "has_sidebar": false,
      "card_style": "flat|bordered|shadowed|glass|none",
      "border_radius": "none|subtle|rounded|pill"
    },

    "imagery": {
      "style": "photography|illustration|icons|mixed|minimal",
      "has_hero_image": true,
      "has_team_photos": false,
      "has_gallery": false,
      "image_treatment": "full-bleed|contained|rounded|circular",
      "uses_stock": true,
      "icon_style": "line|filled|emoji|custom|none"
    },

    "vibe": "one of: tech-forward|professional-clean|bold-contractor|warm-friendly|luxury-premium|playful-casual|corporate-serious|creative-agency|minimal-modern|traditional-business",

    "quality_score": 0-100,
    "quality_notes": "brief assessment of overall design quality"
  },

  "recommended_template": {
    "best_match": "template name from available kits",
    "reasoning": "why this template matches the source design",
    "brand_mode": "template|client",
    "brand_mode_reasoning": "would the template design or client brand colors produce a better result?"
  },

  "brand_colors_for_override": {
    "primary": "#hex — map to Elementor system primary",
    "secondary": "#hex — map to Elementor system secondary",
    "text": "#hex — map to Elementor system text (accent/CTA color)",
    "accent": "#hex — map to Elementor system accent"
  },

  "content_observations": {
    "has_testimonials": true,
    "has_pricing": false,
    "has_portfolio": false,
    "has_blog": false,
    "has_contact_form": true,
    "has_social_links": true,
    "estimated_page_count": 5,
    "industry_guess": "hvac|roofing|plumbing|saas|restaurant|dental|etc"
  }
}

RULES:
1. Extract colors from what you SEE, not what you guess. Look at actual rendered elements.
2. For dark themes: primary is usually the dark background color, accent is the bright pop color.
3. For light themes: primary is usually the brand color used in headers/nav, secondary is the background.
4. quality_score: 90+ = professionally designed, 70-89 = decent template site, 50-69 = outdated/basic, <50 = needs complete rebuild.
5. recommended_template: choose from available kits. Current options: saas-v1 (Imbo dark), ainexa-ai-agency (dark blue neon Pro), bold-v2 (contractor dark). Say "need new kit" if nothing fits.
6. brand_mode recommendation: if the source site has strong brand colors and a cohesive design, recommend "client" mode. If the source site is ugly/generic, recommend "template" mode (let the template design carry).
```

### n8n integration

```javascript
// Screenshot node (before Claude normalize)
// Uses Puppeteer via n8n's Execute Command or a screenshot API

// Option A: ScreenshotAPI.net (simple, $9/mo)
const screenshotUrl = `https://shot.screenshotapi.net/screenshot?url=${encodeURIComponent(url)}&full_page=true&output=image&file_type=png&wait_for_event=load`;

// Option B: Puppeteer in a Code node (free, needs Chrome on the n8n host)
// const browser = await puppeteer.launch();
// const page = await browser.newPage();
// await page.goto(url, {waitUntil: 'networkidle0'});
// const screenshot = await page.screenshot({fullPage: true, encoding: 'base64'});

// Claude Vision API call
const response = await fetch('https://api.anthropic.com/v1/messages', {
  method: 'POST',
  headers: {
    'x-api-key': ANTHROPIC_API_KEY,
    'anthropic-version': '2023-06-01',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 2000,
    messages: [{
      role: 'user',
      content: [
        {
          type: 'image',
          source: { type: 'base64', media_type: 'image/png', data: screenshotBase64 }
        },
        {
          type: 'text',
          text: DESIGN_DNA_PROMPT  // the prompt above
        }
      ]
    }]
  })
});

const designProfile = JSON.parse(response.content[0].text);
// Pass to deploy node for template selection + brand colors
```

### Pipeline flow with vision

```
URL in
  → Firecrawl (content extraction)     ─┐
  → Screenshot → Claude Vision (design) ─┤
                                         ├→ Merge into enriched schema
                                         │
                                         ├→ Template auto-selected from design_profile.vibe
                                         ├→ Brand colors mapped from design_profile
                                         ├→ brand_mode chosen automatically
                                         │
                                         └→ Deploy with optimal template + colors
```

---

## MODE 2: QA Scoring (Deployed Site Analysis)

### When to use
After every deploy — screenshot the output and score it before entering outreach.

### System prompt

```
You are a website quality analyst for WebPrinter. You're reviewing an auto-generated
demo site. Score it against this rubric and return a structured report.

Analyze the screenshot and score EACH category:

HERO SECTION (0-15):
- Real headline, not template placeholder? (5 pts)
- Subtext present and relevant? (3 pts)
- CTA button visible with actionable text? (3 pts)
- Hero image/background fills properly? (4 pts)

BRANDING (0-15):
- Business name in header/nav? (5 pts)
- Logo present and properly sized? (3 pts)
- Color scheme cohesive? (4 pts)
- Typography readable and consistent? (3 pts)

CONTENT QUALITY (0-20):
- No placeholder/lorem text? (5 pts)
- No template brand names? (5 pts)
- Services populated with real content? (5 pts)
- Contact info present? (5 pts)

IMAGE QUALITY (0-15):
- No empty image boxes? (5 pts)
- Images contextually relevant? (5 pts)
- No broken images? (3 pts)
- Image variety (not all identical)? (2 pts)

LAYOUT & DESIGN (0-15):
- No large empty gaps? (4 pts)
- Logical section flow? (3 pts)
- Cards/grids aligned? (3 pts)
- Footer has real info? (3 pts)
- No overlapping/broken elements? (2 pts)

CONVERSION ELEMENTS (0-10):
- Primary CTA above fold? (3 pts)
- Phone/contact visible? (2 pts)
- Quote/contact page linked? (3 pts)
- Social proof present? (2 pts)

MOBILE (0-10):
- If mobile screenshot: score all 4 checks (2.5 pts each)
- If desktop only: award 5/10 default, note mobile untested

Return JSON:
{
  "url": "site url",
  "business_name": "detected name",
  "template": "detected template",
  "total_score": 0-100,
  "status": "PASS|REVIEW|FAIL",
  "categories": {
    "hero": {"score": 0, "max": 15, "issues": []},
    "branding": {"score": 0, "max": 15, "issues": []},
    "content": {"score": 0, "max": 20, "issues": []},
    "images": {"score": 0, "max": 15, "issues": []},
    "layout": {"score": 0, "max": 15, "issues": []},
    "conversion": {"score": 0, "max": 10, "issues": []},
    "mobile": {"score": 0, "max": 10, "issues": []}
  },
  "critical_issues": ["issue 1", "issue 2"],
  "positive_notes": ["good thing 1", "good thing 2"],
  "recommendation": "APPROVE|NEEDS_FIXES|REDEPLOY",
  "fix_list": [
    {"priority": "high|medium|low", "issue": "description", "fix": "specific action"}
  ]
}

Scoring: 90+ EXCELLENT, 80-89 PASS, 70-79 REVIEW, 60-69 NEEDS_WORK, <60 FAIL
```

---

## MODE 3: Compare (Source vs Deployed)

### When to use
After deploy — compare the source site against the deployed version
to verify content fidelity and design improvement.

### System prompt

```
You are comparing two websites:
- Image 1: The ORIGINAL source website (the business's current site)
- Image 2: The DEPLOYED WebPrinter version (auto-generated from scraped content)

Analyze both and return:

{
  "content_fidelity": {
    "score": 0-100,
    "business_name_match": true,
    "services_captured": "all|most|some|few",
    "contact_info_match": true,
    "missing_content": ["list of content from source not in deployed"],
    "extra_content": ["list of content in deployed not in source"]
  },
  "design_improvement": {
    "score": -50 to +50,
    "source_quality": 0-100,
    "deployed_quality": 0-100,
    "improved": true,
    "notes": "how the deployed version compares visually to the source"
  },
  "outreach_ready": true,
  "pitch_angle": "suggested messaging for the outreach email based on what improved"
}

The goal: the deployed site should contain the source's CONTENT but
look BETTER than the source's design. If the deployed version is uglier
than the source, that's a failure.
```

---

## WePro Integration

Add to WePro's post-deploy workflow:

```bash
# After deploy + cache warm:
1. Take screenshot of deployed site (Puppeteer)
2. Run QA mode → get score
3. If score >= 80:
   - Report: "Deploy QA passed ({score}/100), ready for outreach"
4. If score 60-79:
   - Report: "Deploy needs review ({score}/100)"
   - Include fix_list from the QA response
5. If score < 60:
   - Report: "Deploy failed QA ({score}/100), recommend redeploy"
   - Include critical_issues
```

Push this file to the repo as `QA.md` alongside `SKILL.md`.

---

## Available Templates for auto-selection

Update this list as new kits are converted:

| Template | Vibe | Best for |
|----------|------|----------|
| saas-v1 (Imbo) | Dark, modern, red accent | Tech, SaaS, agencies |
| ainexa-ai-agency | Dark blue, neon cyan, Pro widgets | AI, tech, premium SaaS |
| bold-v2 | Dark, bold, contractor-friendly | HVAC, roofing, trades |
| Green-v2 | Green, natural | Landscaping, eco, roofing |
| Authority-v2 | Navy/gold, professional | Legal, consulting, premium trades |
| premium-v2 | Navy/gold, luxury | High-end contractors, remodel |
