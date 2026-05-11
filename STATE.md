# WebPrinter State

**Last updated:** 2026-05-11
**Updated by:** John + Claude Opus (architecture session)

---

## Pipeline status: WORKING

| Component | Status | Details |
|-----------|--------|---------|
| Plugin | ✅ LIVE | v5.2-gold on getinstabid.pro, health returns 5.2 |
| n8n deploy pipeline | ✅ LIVE | Workflow A6naBMqLH3eRDzjx (WebPrinter 5.2 Clean Baseline) |
| n8n QA chain | ✅ LIVE | 12-node chain in workflow 3M01yRKUlwbSHqEK, execution 1013 confirmed |
| Pre-flight | ✅ WORKING | FireCrawl-proxied, checks 3 pages (home, /services, /quote) |
| Vision scoring | ✅ BUILT | 4 screenshots, rubric scoring, ≥80 PASS / 60-79 REVIEW / <60 FAIL |
| Auto-fix loop | ✅ BUILT | 2 iterations max, then ESCALATE to review queue |
| Outreach gate | ✅ LIVE | Blocked unless QA verdict = PASS |
| Pexels stock node | ✅ LIVE | In deploy pipeline, 2 queries per deploy |
| Templates | ✅ DEPLOYED | saas-v1 (Imbo) + ainexa-ai-agency in repo |

## Sites

| Site | Purpose | Plugin | Status |
|------|---------|--------|--------|
| webprinter-test.hostingersite.com | Pasterkamp HVAC test | v5.2 | Deployed, working |
| getinstabid.pro | InstaBid SaaS test | v5.2 | Deployed, QA score 47/100 |

## Deploy auth

getinstabid.pro requires `Authorization: Basic` header with WordPress application password. Credentials in Config. Unauthenticated deploys return Elementor "Access denied" 500 error.

## n8n workflows

| Workflow | ID | Purpose |
|----------|----|---------|
| WebPrinter 5.2 Clean Baseline | A6naBMqLH3eRDzjx | Deploy pipeline (scrape→normalize→Pexels→deploy) |
| WebPrinter prod + QA | 3M01yRKUlwbSHqEK | Full pipeline with 12-node QA chain |

## n8n webhooks

| Webhook | URL | Purpose |
|---------|-----|---------|
| Deploy trigger | https://n8n.instabid.pro/webhook/webprinter-5-1 | Fire full pipeline |
| QA test (live) | https://n8n.instabid.pro/webhook/qa-test-live | QA-only check on any deployed site |

## QA chain architecture (DO NOT REBUILD — already live)

```
WordPress Deploy → QA Pre-flight HTML (FireCrawl proxy) → Pre-flight Pass?
  → (pass) → Capture Home Desktop → Home Mobile → Services Desktop → Services Mobile
  → Vision Score → Parse Score → Score Gate
  → PASS: outreach proceeds
  → REVIEW: Auto-Fix Apply → Loop Decide (REDEPLOY or ESCALATE)
  → FAIL/PARSE_ERROR: Review Queue Write
```

## Backups

| File | Purpose |
|------|---------|
| 3M01yRKUlwbSHqEK_live-pre-qa-cut_2026-05-10T2058.json | Rollback target (before QA chain) |
| 3M01yRKUlwbSHqEK_live-post-qa-cut_2026-05-10T2058.json | Current state (with QA chain) |
| LwJIQ4nyxKZVrgAM_qa-staging-v4-firecrawl-preflight_2026-05-10T1723.json | QA staging |

## Known issues (current)

1. **ainexa template stock-image leak** — 11 hits of ainexa.templatekit.co image URLs still in /services templates. Deploy isn't replacing these with stock. This is a template fix, not a plugin or QA issue.
2. **footer_contact_missing** — fires when payload has empty contact fields. Real deploys with real data will pass. Not a bug.
3. **header.json missing** — AiNexa kit has no HFE header template. Hello Elementor default header renders instead (oversized logo). kit.json has CSS to hide it.
4. **Image IDs returning 0** — stock manifest sideload depends on URLs being reachable from WordPress host. GitHub-raw mirrored images work. Unsplash fails on Hostinger.
5. **n8n → getinstabid.pro network blocked** — DigitalOcean → this specific Hostinger IP is null-routed. FireCrawl proxy workaround is in place. Local curl works fine.

## NEXT ACTIONS (in priority order)

1. **Fix ainexa template stock-image leak** — replace ainexa.templatekit.co URLs in the converted templates with _wp_stock markers or empty them so auto-purge handles them. This unblocks the vision scoring test.
2. **Exercise vision scoring end-to-end** — needs a site that clears pre-flight first (action #1 fixes this). Then fire qa-test-live and confirm vision scores + auto-fix loop works.
3. **Template polish for 80+ score** — fix counter placeholders (0 TB), hide empty testimonials, resolve duplicate footer.
4. **Wire Vision analysis to front of pipeline** — conditional gate after Firecrawl, only fires when scrape is thin.
5. **Test with instabid.pro site** — Run the full pipeline end-to-end.

## DO NOT

- Touch or reference WebPrinterBoltjsonPHP (legacy repo — dead)
- Rebuild the QA chain (it's already live and working)
- Rebuild the Pexels node (it's already live and working)
- Create tickets without John's approval
- Work on tasks not listed in NEXT ACTIONS without asking first
- Modify the plugin without John's explicit approval

## Repo structure

```
SiteHypeInc/WebPrinter2.0/
├── SKILL.md                           ← Agent skill doc (read every session)
├── QA.md                              ← QA rubric + vision system
├── STATE.md                           ← THIS FILE (read every session)
├── webprinter-engine-v5.2-gold.php    ← Plugin (DO NOT MODIFY)
├── tools/convert_template.py          ← Template converter
├── docs/                              ← Schema, authoring guide, normalization rules, design tokens
├── schema/                            ← JSON schema + example payloads
└── templates/
    ├── _stock/manifest.json           ← Stock photo URLs per trade
    ├── saas-v1/                       ← Imbo kit (7 pages + kit.json)
    └── ainexa-ai-agency/              ← AiNexa kit (7 pages + kit.json)
```
