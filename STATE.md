# WebPrinter State

**Last updated:** 2026-05-11
**Updated by:** WePro (vision-chain proven; Servicesy + lorem fixes shipped)

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
| getinstabid.pro | InstaBid SaaS test | v5.2 | Deployed, QA score 52/100 (was 47, +5 after Servicesy + lorem fixes) |

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
2. **footer_contact_missing** — pre-flight check looks at `home.slice(-6000)` for phone/email/city. With `phone=""`, only the email regex and `Portland` literal can clear it. With `qa_skip_failures:["footer_contact_missing"]` payload-bypass the chain runs end-to-end; permanent fix is OR semantics across phone/email/city plus a longer slice (HFE footer renders mid-document, not at tail).
3. **header.json missing** — AiNexa kit has no HFE header template. Hello Elementor default header renders instead (oversized logo). kit.json has CSS to hide it.
4. **Image IDs returning 0** — stock manifest sideload depends on URLs being reachable from WordPress host. GitHub-raw mirrored images work. Unsplash fails on Hostinger.
5. **n8n / FireCrawl → Hostinger network blocked on BOTH properties** — RESOLVED 2026-05-11. CDN toggled OFF in hPanel for both `getinstabid.pro` and `instabid.pro`. End-to-end QA via `qa-test-live` now returns clean preflight + vision results from getinstabid.pro.
6. **Sibling Imbo URL leak in saas-v1** — RESOLVED 2026-05-11 (commit 85c3d28). 5 `nva.nirmanavisual.com/imbo/...` URLs replaced; one self-hosted asset added at `templates/saas-v1/assets/Image-BG-Imbo-1.png`.
7. ~~**"Servicesy from {{business_name}}" typo + 3 lorem-ipsum blurbs in AiNexa templates**~~ — RESOLVED 2026-05-11 (commit a5f88b7). Typo fixed in `services.json:333`; `Excepteur sint occaecat…` replaced with `{{about_short}}` in `services.json` (2 occurrences) and `about.json` (1 occurrence). Verified clean on live `https://getinstabid.pro/services/`.
8. **FireCrawl /scrape cache makes vision scores stale** — `api.firecrawl.dev/v1/scrape` caches results by URL; back-to-back QA fires return identical screenshots even after fresh deploys. Verified by patching all 5 call sites (Pre-flight HTML + 4 Capture nodes) with `maxAge: 0` and re-firing — Servicesy and lorem-ipsum disappeared from vision findings, score moved 47→52. Patch was reverted to backup parity after the test. Permanent fix: add `maxAge: 0` (or a short TTL) to the body in `QA — Pre-flight HTML` (`JSON.stringify({ url: target, formats: ['html'], timeout: 20000, maxAge: 0 })`) and the four capture nodes' raw body opts.
9. **LiteSpeed cache not flushed by engine on deploy** — `webprinter-engine-v5.2-gold.php` calls `Breeze_PurgeCache::breeze_cache_flush()` and `$this->clear_elementor_cache()`, but getinstabid.pro runs LiteSpeed Cache. Direct local curl after deploy *does* show fresh content (LiteSpeed auto-purges on `_elementor_data` save), but cache-warmth across FireCrawl scrape paths is unreliable. Watch item — may need `do_action('litespeed_purge_all')` added to engine post-deploy hook. Requires plugin change → John's approval.
10. **HFE footer + Hello Elementor `<footer id="colophon">` co-existence** — HFE injects footer content correctly (4× `hello@instabid.pro` + 4× `Portland` render in DOM after deploy), but Hello Elementor's empty `<footer id="colophon">` placeholder appears below the HFE footer. Vision rubric repeatedly reports "Footer Missing" — likely because the bottom of viewport shows the empty colophon, and dark-on-dark colors (footer gradient `#07142D` on body `#00060B`) make HFE footer hard to distinguish. Mechanism is intact (see `webprinter-engine-v5.2-gold.php:1206-1255` — engine creates `elementor-hf` post w/ `ehf_template_type=type_footer` + `ehf_target_include_locations=basic-global`). Fix candidates: (a) hide `#colophon` via `kit.json.custom_css` the same way `#site-header` is hidden, (b) reverse the visual mismatch by increasing footer contrast.

## NEXT ACTIONS (in priority order)

1. ~~**Fix ainexa template stock-image leak**~~ — DONE (commits 5eee1ac, 85c3d28).
2. ~~**Exercise vision scoring end-to-end**~~ — DONE. Vision chain proven 2026-05-11 via `qa-test-live` with `qa_skip_failures:["footer_contact_missing"]` bypass. Score 47 → 52 after Servicesy + lorem fixes (commit a5f88b7). 12 issues remain — see vision report.
3. **Template polish for 80+ score** — remaining critical/high issues from latest vision pass: (a) hero image fills only half the desktop hero, missing on mobile (huge empty black gap) — likely an `_wp_img` marker missing on the hero container or hero image asset not sideloaded for the `hero` category; (b) service-card thumbnails show car-dashboard photos for Roofing/HVAC/Painting — wrong industry context — need `_wp_stock` markers per service-card tied to the trade (or per-service `image_url` in payload); (c) mobile hero headline overflows horizontally + CTA overlaps counter; (d) no testimonials/social proof anywhere (kit has the section but it's not tokenized for `_wp_repeat:testimonials`); (e) counter values lack labels ("3x" with no context).
4. **FireCrawl /scrape cache fix** — add `maxAge: 0` to the 5 FireCrawl call bodies (1 in Pre-flight HTML, 4 in capture nodes). Without this, vision scores reflect the cached state from the *previous* deploy, not the current one. Patch was proven in this session and reverted; needs to be made permanent.
5. **Pre-flight footer check semantics** — `footer_contact_missing` fires even when the rendered footer contains the contact info, because the check only looks at `home.slice(-6000)` and HFE's footer renders mid-document followed by Hello Elementor's empty `<footer id="colophon">`. Fix candidates: lengthen slice, OR semantics across phone/email/city, OR scan within the actual `<footer>` element.
6. **LiteSpeed cache flush in engine** — engine uses Breeze; site is LiteSpeed. Watch item; may need `do_action('litespeed_purge_all')` added post-deploy. Plugin change → requires John's explicit approval.
7. **Wire Vision analysis to front of pipeline** — conditional gate after Firecrawl, only fires when scrape is thin.
8. **Test with instabid.pro site** — Run the full pipeline end-to-end (CDN now off on instabid.pro per issue #5; should be unblocked).

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
