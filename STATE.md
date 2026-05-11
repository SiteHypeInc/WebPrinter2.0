# WebPrinter State

**Last updated:** 2026-05-11
**Updated by:** WePro (post-leak fix + services tokenization)

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

1. ~~**ainexa template stock-image leak**~~ — RESOLVED 2026-05-11 (commit 5eee1ac). 39 ainexa.templatekit.co URLs across all 5 page templates replaced with self-hosted assets under `templates/ainexa-ai-agency/assets/`, served via raw.githubusercontent.com. Verified via deploy → 0 leaks on rendered home/about/services.
2. **footer_contact_missing** — pre-flight check fails on payloads with empty `contact.phone`. Email + address alone don't satisfy it. Either supply a phone (or placeholder) in the payload, or relax the check to OR semantics across phone/email.
3. **header.json missing** — AiNexa kit has no HFE header template. Hello Elementor default header renders instead (oversized logo). kit.json has CSS to hide it.
4. **Image IDs returning 0** — stock manifest sideload depends on URLs being reachable from WordPress host. GitHub-raw mirrored images work. Unsplash fails on Hostinger.
5. **n8n / FireCrawl → Hostinger network blocked on BOTH properties** — DigitalOcean → both `getinstabid.pro` and `instabid.pro` was null-routed via hCDN bot challenge (`hcdn-cgi/jschallenge`). CDN toggled OFF in hPanel 2026-05-11 — propagation may lag; FireCrawl outbound IPs may need separate whitelist if challenges persist. Symptom: `webprinter-5-1` webhook returns `{"data":"<!DOCTYPE html>...Checking your browser..."}` wrapped FireCrawl response, and `qa-test-live` returns instantly with stale-looking preflight failures even when the rendered page is correct (suggests FireCrawl proxy is seeing the challenge HTML in place of the real page).
6. **Sibling Imbo URL leak in saas-v1** — RESOLVED 2026-05-11 (commit 85c3d28). 5 `nva.nirmanavisual.com/imbo/...` URLs replaced; one self-hosted asset added at `templates/saas-v1/assets/Image-BG-Imbo-1.png`.

## NEXT ACTIONS (in priority order)

1. ~~**Fix ainexa template stock-image leak**~~ — DONE (commits 5eee1ac, 85c3d28).
2. **Exercise vision scoring end-to-end** — pre-flight now passes `services` count (commit 2475749 added `_wp_repeat: "services"` to the AiNexa services grid + tokenized `{{services._item.name}}` and `{{services._item.description}}`). Remaining blockers: (a) supply non-empty `contact.phone` in payload to clear `footer_contact_missing`, (b) confirm FireCrawl is no longer being challenged by hCDN (re-fire `qa-test-live` after dashboard propagation).
3. **Template polish for 80+ score** — fix counter placeholders (0 TB), hide empty testimonials (consider `_wp_if: "testimonials"` since AiNexa testimonials aren't tokenized for `_wp_repeat` either), resolve duplicate footer. Service-card icons currently repeat the same `AI-Icon-23-1.png` 6× because we kept ONE canonical card; consider rotating from a pool or extracting icons to a payload field.
4. **Wire Vision analysis to front of pipeline** — conditional gate after Firecrawl, only fires when scrape is thin.
5. **Test with instabid.pro site** — Run the full pipeline end-to-end (blocked on issue #5 propagation).

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
