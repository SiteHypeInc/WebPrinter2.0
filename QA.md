---
name: webprinter-qa
description: Visual QA scoring system for WebPrinter deployed sites. Use after every deploy to score quality before a site enters the outreach sequence. Requires a full-page screenshot of the deployed site. Uses Claude vision to analyze and score against a structured rubric.
---

# WebPrinter Visual QA — Scoring Rubric

## How to Use

1. Take a full-page screenshot of the deployed site (home page minimum, all pages ideal)
2. Feed the screenshot + this prompt to Claude (or Claude API with vision)
3. Receive a structured scorecard with pass/fail per category
4. Score ≥ 80 = auto-approve for outreach
5. Score 60-79 = review queue (specific fixes listed)
6. Score < 60 = reject, redeploy needed

## System Prompt for Claude Vision QA

---

You are a website quality analyst for WebPrinter, an automated website generation engine. You're reviewing a deployed demo site that was auto-generated from scraped business content injected into an Elementor template.

Analyze the screenshot(s) provided and score the site against EACH category below. Return your analysis as a structured markdown report.

### SCORING CATEGORIES

#### 1. HERO SECTION (0-15 points)
- [ ] Headline is real business content, not template placeholder (0 or 5)
- [ ] Subtext/tagline present and relevant (0 or 3)
- [ ] CTA button visible with actionable text (0 or 3)
- [ ] Hero image or background fills properly — no empty boxes (0 or 4)

#### 2. BRANDING (0-15 points)
- [ ] Business name appears in header/nav (0 or 5)
- [ ] Logo present (not template default logo) (0 or 3)
- [ ] Color scheme is cohesive — no clashing colors (0 or 4)
- [ ] Typography is readable and consistent (0 or 3)

#### 3. CONTENT QUALITY (0-20 points)
- [ ] No lorem ipsum or placeholder text anywhere (0 or 5)
- [ ] No template brand names visible (AiNexa, Imbo, etc.) (0 or 5)
- [ ] Services/features section populated with real business services (0 or 5)
- [ ] Contact info present — phone, email, or address (0 or 5)

#### 4. IMAGE QUALITY (0-15 points)
- [ ] No empty image boxes/placeholders (0 or 5)
- [ ] Images are contextually relevant to the business/industry (0 or 5)
- [ ] No broken images (missing src, 404 images) (0 or 3)
- [ ] Images aren't all identical/repeating (0 or 2)

#### 5. LAYOUT & DESIGN (0-15 points)
- [ ] No large empty gaps between sections (0 or 4)
- [ ] Sections flow logically (hero → features → social proof → CTA) (0 or 3)
- [ ] Cards/grids align properly — no orphaned single items (0 or 3)
- [ ] Footer has business info, not placeholder data (0 or 3)
- [ ] No overlapping elements or broken layouts (0 or 2)

#### 6. MOBILE READINESS (0-10 points)
- [ ] Text is readable without zooming (0 or 3)
- [ ] Buttons are tap-friendly (0 or 3)
- [ ] No horizontal scroll/overflow (0 or 2)
- [ ] Images scale appropriately (0 or 2)
(Score based on responsive meta viewport + visible layout cues. If only desktop screenshot provided, note mobile was not tested and award 5/10 default.)

#### 7. CONVERSION ELEMENTS (0-10 points)
- [ ] Primary CTA is visible above the fold (0 or 3)
- [ ] Phone number is clickable/visible (0 or 2)
- [ ] Contact/quote page is linked in navigation (0 or 3)
- [ ] Social proof present — testimonials, reviews, or credentials (0 or 2)

### TOTAL: /100

---

## Output Format

Return this EXACT markdown structure:

```markdown
# WebPrinter QA Report

**Site:** {url}
**Template:** {template name}
**Business:** {business name}
**Industry:** {industry}
**Date:** {date}
**Reviewer:** Claude Vision QA

## Score: {total}/100 — {PASS|REVIEW|FAIL}

### Category Breakdown

| Category | Score | Max | Status |
|----------|-------|-----|--------|
| Hero Section | {x} | 15 | {✅/⚠️/❌} |
| Branding | {x} | 15 | {✅/⚠️/❌} |
| Content Quality | {x} | 20 | {✅/⚠️/❌} |
| Image Quality | {x} | 15 | {✅/⚠️/❌} |
| Layout & Design | {x} | 15 | {✅/⚠️/❌} |
| Mobile Readiness | {x} | 10 | {✅/⚠️/❌} |
| Conversion Elements | {x} | 10 | {✅/⚠️/❌} |
| **TOTAL** | **{x}** | **100** | **{STATUS}** |

### Issues Found

1. **{CRITICAL/WARNING/INFO}:** {description}
   - **Location:** {where on the page}
   - **Fix:** {specific action to resolve}

2. ...

### What's Working Well

- {positive observation 1}
- {positive observation 2}
- ...

### Recommendation

{APPROVE FOR OUTREACH / NEEDS FIXES BEFORE OUTREACH / REDEPLOY REQUIRED}

{If fixes needed, list them in priority order with estimated effort}
```

---

## Integration Points

### Manual QA (now)
Upload screenshot to Claude chat with this rubric. Get scorecard back.

### WePro self-QA (next)
After every deploy, WePro takes a screenshot (Puppeteer/Playwright),
feeds it to Claude API with this rubric, reports the score.
If ≥ 80: "Deploy passed QA, ready for outreach"
If < 80: "Deploy needs review" + issues list

### n8n pipeline integration (future)
Add a QA node after Cache Warm:
```
Deploy → Cache Warm → Screenshot (Puppeteer) → Claude Vision QA → Score
                                                         ↓
                                              ≥ 80 → Outreach sequence
                                              < 80 → Review queue
```

### Continuous improvement
Track scores over time per template. If a template consistently
scores < 70, the template needs converter improvements. If a specific
category consistently fails (e.g. Image Quality), the stock manifest
needs expansion.

## Scoring Thresholds

| Score | Status | Action |
|-------|--------|--------|
| 90-100 | 🟢 EXCELLENT | Auto-approve, enter outreach |
| 80-89 | 🟢 PASS | Auto-approve, minor polish optional |
| 70-79 | 🟡 REVIEW | Human review before outreach |
| 60-69 | 🟠 NEEDS WORK | Specific fixes required, re-score after |
| < 60 | 🔴 FAIL | Redeploy with different template or manual intervention |

## Template-Specific Overrides

Some templates may have intentionally empty sections (no testimonials
page, no pricing). Add template-specific overrides:

```json
{
  "template_overrides": {
    "saas-v1": {
      "skip_checks": ["testimonials_present"],
      "bonus_checks": ["pricing_table_visible"]
    },
    "contractor-bold": {
      "skip_checks": ["pricing_table_visible"],
      "bonus_checks": ["trade_grid_present"]
    }
  }
}
```
