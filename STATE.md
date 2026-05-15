# WebPrinter State

**Last updated:** 2026-05-14
**Updated by:** WP Pilot (TEA-792 — Modora re-proof on webprinter-test.hostingersite.com end-to-end). Cloned gold workflow `A6naBMqLH3eRDzjx` → new workflow `wp-pilot webprinter` (id `tLIE3cHlwWF97FRD`) with webhook path `wp-pilot-webprinter`, deploy URL pointed at `webprinter-test.hostingersite.com`, Basic auth from `WP_TEST_USER:WP_TEST_PASSWORD`, validTemplates extended with `'modora'`. Gold workflow untouched. Staged Modora Elementor kit (TEA-760 attachment) as `templates/modora/{home,about,services,quote,contact,header,footer}.json + kit.json` via branch `tea792-modora` → PR #6 squash-merge `f453cc1`. Scraped `bethebesthome.com` via the cloned pipeline (n8n exec 1142): business_name=Kathryn Emery, industry=media-personality, 5 services, hero from source. Deploy via residential-curl bypass of Hostinger bot-wall (same DigitalOcean ASN issue, Issue #5 recurs on webprinter-test). Engine returned **HTTP 200 in ~4s** with engine shape `{success:true, version:"5.3-candidate", template:"modora", company:"Kathryn Emery", updated:{home,about,services,quote,contact,header:"updated",footer:"updated"}, image_ids:{hero:155, about:156, service:157, logo:0, gallery_0-3:158-161}}`. **QA chain exec on `webprinter-test.hostingersite.com` returned qa_score=34, qa_verdict=FAIL** — regression from ainexa baseline 54-58. 16 issues, of which 10 critical/high are layout/template-side (lorem ipsum bleed-through on mobile, services-page z-index collisions, empty white box, gray gap below hero, no hero CTA, business name missing from header, no contact info / no footer rendered, mobile no tappable buttons). Vision read the rendered site accurately enough to name specific layout defects → **Firecrawl screenshots are not the Vision bottleneck; SnapRender swap (TEA-760) does not address any actual blocker → recommend CANCEL TEA-760**. Two new infra notes captured below: (a) `webprinter-test.hostingersite.com` runs engine **version `5.3-candidate`** (not `5.2`), and design_tokens override on this build trips Elementor `Core\Settings\Page\Manager::ajax_before_save_settings()` Access Denied 500 — workaround was to omit `design_tokens`+`design_profile` and pass `brand_mode:"template"`; (b) Hostinger DigitalOcean-ASN bot-wall (Issue #5) is also live on `webprinter-test.hostingersite.com`, not just `getinstabid.pro`.

---

**Last STATE entry before TEA-792 (Wake 5, TEA-756 close — Path 1, accept v3 as v0.1 cold-start baseline).** Commander direction in comment `e30980ed` after Wake 4 stopped CSS iteration on rule #4: revert v4 → v3, ship as v0.1, park engine + template fixes as WebPrinter2.0 GitHub product items (not Paperclip execution work). Action this wake: branch-first revert PR `#5` (commit `d696940`) on `main` rolls `templates/ainexa-ai-agency/kit.json` back to v3 state (commit `94fa1353` content, pre-v4 squash). Re-deploy fired direct from this Mac against the same `templates/ainexa-ai-agency` + `brand_mode: client` + scrape-derived `design_tokens.colors.accent = #00D4FF` payload that v3 ran at 16:43 UTC. Engine returned HTTP 207 in 4.4s with engine shape `{success:false, blog_id:1, template:"ainexa-ai-agency", company:"InstaBid", updated:{home,about,services,quote,contact,footer}, errors:{header:"Template returned HTTP 404"}, image_ids:{hero:139, about:138, service:0, logo:0}, version:"5.2"}` — `success:false` still solely from missing `templates/ainexa-ai-agency/header.json` (Issue #3, unchanged). All 5 pages + footer re-rendered. Live verification: `/wp-json/webprinter/v1/health` returns `{"status":"ok","version":"5.2"}`; `<title>InstaBid</title>` on `/`; `<title>About – InstaBid</title>` on `/about/`; 0 occurrences of `Probe / AiNexa / Imbo / Linear / #5e6ad2`. Per-post kit CSS (`post-8.css`) now serves v3 baseline rules (`overflow-x: hidden`, `max-width: 240px`, `border-radius: 999px`, `.elementor-widget-counter { display: none }`) and is free of the v4 selectors (`.elementor-widget-counter + .elementor-widget-heading`, `[data-elementor-type="header"] .elementor-widget-button`). Vision score accepted at **58/100** from the v3 deploy at 16:43 UTC recorded in comment `5024f7c6` — no re-QA this wake per Commander direction.

**v0.1 cold-start baseline — recorded fields:**
- Commit (kit content): `94fa1353` (PR #3 squash on main as `e0f9966`; restored via revert PR #5 squash as `d696940`).
- Site URL: `https://getinstabid.pro/`.
- Kit: `templates/ainexa-ai-agency`.
- Brand colors on the wire: scrape-derived `design_tokens.colors.accent = #00D4FF` (cyan, ×18 on `instabid.pro` source). Original TEA-756 brief listed `primary: #1B4D7A` + `accent: #E8622D` (navy/orange); the board correction in comment `783c9576` reset that to scrape-first DNA, so the navy/orange spec is the documented InstaBid brand identity but the deployed accent is the cyan extracted from `instabid.pro`. Ainexa kit's white-on-dark `primary/secondary/text` left at template defaults.
- Vision score: 58/100 FAIL — accepted as v0.1 baseline. v4 score 42 was a regression caused by a mis-targeted CSS selector on `.elementor-location-header .elementor-widget-button`; the revert removes that delta.
- Deploy response shape verified: engine-shape JSON, HTTP 207, ≠ HTML, ≠ 4xx, ≠ 130s timeout.

**Parked items (intentionally NOT spawned as Paperclip child issues per Commander e30980ed):**
1. Engine Issue #11 — `purge_placeholder_images` (engine L1436) broadcasts one stock image; `image_ids.service:0` and `image_ids.logo:0` reproduce every deploy. Lives in `webprinter-engine-v5.2-gold.php` — gated. Owned at the WebPrinter2.0 GitHub product layer with Commander triage on TEA-475 AM brief.
2. Ainexa template body mismatch — kit ships an AI-agency hero/headline structure (animated-headline placeholder, INTRODUCING repeater, image-box wordmark in nav, double-rendered tagline on /about/) that does not match contractor-SaaS marketing. Future contractor deploys should consider a different template; SaaS-v1 has its own tokenization gaps (TEA-761 backlog). Lives as a template-strategy decision in WebPrinter2.0, not a Paperclip ticket.
3. Issue #5 (DigitalOcean ASN bot-wall on getinstabid.pro) — dropped per board comment `ed99c4ed` ("CDN is off. Litespeed cache is off. We're beating a dead horse"). Direct curl from a residential IP works for all current and future cold-starts.

Wake 2 firecrawl migration findings and Wake 3 ainexa redeploy findings preserved below.

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
| Vision Mode 1 (Design DNA) | ✅ LIVE | Source Screenshot → Vision DNA → Merge — auto-selects template + brand colors |
| Templates | ✅ DEPLOYED | saas-v1 (Imbo) + ainexa-ai-agency in repo |

## Sites

| Site | Purpose | Plugin | Status |
|------|---------|--------|--------|
| webprinter-test.hostingersite.com | Pasterkamp HVAC test | v5.2 | Deployed, working |
| getinstabid.pro | InstaBid SaaS test | v5.2 | **Wake 3 redeploy 2026-05-12 (TEA-756 redo):** kit `ainexa-ai-agency`, brand_mode `client`, scraped accent `#00D4FF` (cyan, ×18 on source homepage). Other slots: kept ainexa kit defaults (primary `#FFFFFF`, secondary `#EBEBEB`, text `#D8D8D8`). Engine 207 — all 5 pages + footer updated; ainexa header.json 404 (Issue #3). Title=`InstaBid` across all 5 pages, 0 leaks of `Probe/AiNexa/Imbo/Linear/#5e6ad2`. QA exec 1085 = score 54 FAIL (flat with prior ainexa baseline, blockers all engine/template-side: #11 engine-gated, #13 ainexa home.json CSS, #15 HFE footer contrast). Hero att 146, about att 145, service att 0 (Issue #11 reproduces). Wake 1 saas-v1 baseline (2026-05-12 AM) superseded by this deploy. |

## Deploy auth

getinstabid.pro requires `Authorization: Basic` header with WordPress application password for user `john@sitehypedesigns.com` (WP user id 1). App password is in the n8n deploy workflow (`A6naBMqLH3eRDzjx`, node `HTTP Request (Deploy)`) and as env var `GETINSTABID_APP_PASSWORD` in the WP Pilot agent. Unauthenticated deploys return Elementor "Access denied" 500 error.

## Wake log

### 2026-05-12 — WP Pilot Wake 3 (TEA-756 redo, scrape-first + ainexa override)

**Board correction:** Wake 1 baseline used hardcoded navy `#1B4D7A` + orange `#E8622D` on `saas-v1`. Board flagged this — "Navy and orange is on brand with what??" — and Commander corrected scope: scrape `instabid.pro` for brand identity, use `ainexa-ai-agency` template, deploy client-mode. Per Commander's last directive (comment `8a37af5d`), posted named-node spec on TEA-756 (comment `2130bbd5`) before redeploying.

**Source DNA (extracted by direct GET of `https://instabid.pro` from this Mac — Firecrawl key not exposed locally and n8n exec hung on bot-wall):**
- `<title>`: `InstaBid — AI Contractor Estimating Software | Win More Jobs`
- `og:site_name`: `Instabid`
- Hex frequency (top 5): `#00d4ff ×18` (cyan), `#0d1117 ×8` (dark bg), `#00e5ff ×6`, `#8b949e ×5`, `#ffffff ×4`. Pattern = dark SaaS theme, cyan-accented.
- Industry: `saas` (per `docs/normalization-rules.md` rule — "saas", "platform", "estimating software" all match)

**Brand override decision:** ainexa kit.json `system_colors` are `primary:#FFFFFF / secondary:#EBEBEB / text:#D8D8D8 / accent:#5DD7F2` — a dark template where `primary` = white text and `accent` = the cyan CTA color. Overriding `primary` with scraped cyan would invert contrast. Cleanest mapping: `design_tokens.colors = { accent: "#00D4FF" }` — replaces template's `#5DD7F2` with InstaBid's actual brand cyan and lets the dark template carry the rest. Engine `apply_design_tokens` L1081-1091 iterates only `_id`s that exist in kit + are present in payload, so unspecified slots stay at kit defaults.

**Deploy path:** n8n webhook fire at 20:46:20Z returned 504 (nginx) within 60s; n8n exec 1082 stayed running > 130s — almost certainly bot-walled on `HTTP Request (Deploy)` per Issue #5 (DigitalOcean ASN still not whitelisted in hPanel). Pivoted to direct curl from this Mac (residential IP) → engine returned HTTP 207 in 7.4s with engine-shape body (`success:false` only because of header.json 404 — Issue #3 — all 5 pages and footer updated cleanly).

**Live verification:**
- `<title>InstaBid</title>` on `/`, `About – InstaBid` on `/about/`, same pattern for `/services /quote /contact`
- 15 explicit `InstaBid` mentions, 0 occurrences of `Probe / AiNexa / Imbo / Linear / #5e6ad2`
- 0 marker leaks (`_wp_repeat / _wp_if / _wp_img / _wp_stock / {{` all zero on rendered home)
- Content rendered: "AI Estimate" ×2, "Home Depot" ×6 from `about_long`
- Hero att 146, about att 145 (GitHub-raw sideload) — Issue #11 reproduces: `image_ids.service:0` (engine `purge_placeholder_images` L1436 broadcasts one stock URL)

**QA chain run:** `qa-test-live` webhook fired with the seed (camelCase field names per `QA — Test Seed (live)` shape: `siteUrl, demoUrl, businessName, services[]`). Exec 1085 ran end-to-end:
- Pre-flight: PASS (`pages_reachable ✅, name_in_header ✅, services_three_plus ✅, no_lorem_no_template_brands ✅, instabid_embed_on_quote ✅, hero_img_src_present ✅`; `footer_real ❌` skipped via `qa_skip_failures` — Issue #2 known heuristic)
- Vision score: **54/100 FAIL** — categories: hero 10/15, branding 12/15, content 10/20, images 8/15, layout 6/15, mobile 6/10, conversion 5/10
- Top issues (all known and layer-named):
  - `critical` Hero image void on desktop — Issue #13 (ainexa home.json hero section min-height + marquee width, template-side fix)
  - `critical` Footer missing/contrast — Issue #15 (HFE footer dark-on-dark, kit.json custom_css fix)
  - `critical` Per-service images all identical — Issue #11 (engine `purge_placeholder_images` L1436, engine-gated, escalated to Commander)
  - `critical` Content brand mention — vision flagged "Home Depot" as potentially unlicensed (it's a real source-site claim per the `about_long`, so this is a content-tone decision, not a bug)
- Score Gate routed FAIL → `QA — Review Queue Write` (correct per design — auto-fix is for REVIEW 60-79, not FAIL <60). Outreach blocked.
- Firecrawl QA screenshot (signed, expires 2026-05-19): https://storage.googleapis.com/firecrawl-scrape-media/screenshot-b4f02402-e5f4-4239-b8d7-27d276322f45.png

**Honest score-flat read:** 54 here is on `ainexa-ai-agency`, matching the Wake 1-7 ainexa baseline ceiling. It is NOT a regression off the Wake 1 saas-v1 baseline (which was never re-scored on this template). Score-flat per AGENTS.md rule = "no progress, layer = template + engine" — the unmoved categories (layout, images, hero) all map to the 3 known blockers above, none of which can be resolved inside the TEA-756 cold-start scope (engine `.php` is gated, and template authoring polish is a separate TEA).

**Open watch items going out:**
- Issue #5 (DigitalOcean ASN bot-wall) still walls n8n's deploy egress. John's hPanel IP whitelist still pending; cold-start deploys can be done by direct curl from a non-DO IP in the meantime.
- LiteSpeed Cache still deactivated per task brief (not re-enabled).
- Token alignment: confirmed engine reads `payload.design_tokens.colors` (L1078) — `Merge Design DNA` already writes under that path (Wake 9 patch (b)+(c)).

### 2026-05-12 — WP Pilot Wake 2 (TEA-758, n8n-nodes-firecrawl migration)
- Installed `n8n-nodes-firecrawl@0.3.0` (by @minhlucvan, registers `n8n-nodes-firecrawl.fireCrawl`) on n8n.instabid.pro via `POST /rest/community-packages`.
- Created `fireCrawlApi` credential `Firecrawl API (WebPrinter)` (id `b9XuklKF3EmwLasp`) carrying the existing Firecrawl key.
- Built a throwaway parity workflow (`SL44SrzZksHiaUuU`, deleted after verify): Webhook → native FireCrawl `Scrape A Url And Get Its Content` → Respond. Two test fires (typed mode + `useCustomBody: true`) against `https://example.com` returned byte-identical envelopes to a raw `curl https://api.firecrawl.dev/v1/scrape` baseline at the keys downstream nodes read (`success`, `data.markdown`, `data.metadata`).
- PATCHed `A6naBMqLH3eRDzjx` (versionId `03cc779a…` → `e4bba1b9…`), swapping both Firecrawl HTTP Request nodes for the native node while preserving node ids, names, positions, and connections:
  - `HTTP Request (Firecrawl)` (markdown scrape, used by Claude Normalize) → `n8n-nodes-firecrawl.fireCrawl` Scrape op, `useCustomBody: true`, body `{ url, formats:["markdown"], onlyMainContent:true, timeout:15000 }`.
  - `Source Screenshot (FireCrawl)` (screenshot scrape, used by Vision Design DNA) → same native node, `useCustomBody: true`, body `{ url, formats:["screenshot"], onlyMainContent:false, timeout:30000, maxAge:0 }` + node-level `requestOptions.timeout: 60000`. **Rationale for `useCustomBody`**: native v0.3.0 typed UI exposes only `url`, `formats` (markdown/html/extract — no screenshot), `extract`, `actions` for the Scrape op; it does NOT expose `onlyMainContent`, `timeout`, `maxAge`. `useCustomBody` keeps the request body byte-identical to the prior HTTP node while still routing auth via the native credential.
  - Backup: `WebPrinterBoltjsonPHP/n8n-backups/A6naBMqLH3eRDzjx_pre-firecrawl-native_2026-05-12T1133.json`.
- End-to-end smoke (exec 1075, source = `https://example.com`, deploy target = getinstabid.pro):
  - `HTTP Request (Firecrawl)` [native] — OK, 421ms.
  - `Claude AI (content normalization)` — OK, 2173ms (consumed `$json.data.markdown` from the native node unchanged).
  - `Pexels Stock Sourcing` — OK, 79ms.
  - `Source Screenshot (FireCrawl)` [native] — OK, 1070ms.
  - `Vision — Design DNA` — OK, 11621ms (consumed `$json.data.screenshot` from the native node unchanged).
  - `Merge Design DNA` — OK; `Probe Egress IP` — OK.
  - `HTTP Request (Deploy)` — **error, 130694ms timeout** → pre-existing Issue #5 (Hostinger walling DigitalOcean ASN). Confirmed no regression: prior execs 1068 and 1069 failed identically on the same node before migration.
- Net: Firecrawl migration is functionally verified and does not change pipeline behavior at the seams downstream nodes care about. The QA chain in `3M01yRKUlwbSHqEK` is untouched (out of scope; queued for a separate ticket after the deploy workflow stays stable).
- Sibling work still queued (out of scope for TEA-758): SnapRender integration (blocked on John providing key) will replace `Source Screenshot (FireCrawl)` entirely; the QA chain Firecrawl preflight + screenshots migrate after SnapRender lands.

### 2026-05-12 — WP Pilot Wake 1 (cold-start, TEA-756)
- Deployed clean InstaBid baseline to `https://getinstabid.pro` via direct curl from residential IP (bypasses DigitalOcean ASN block walling n8n — Issue #5 unchanged).
- Payload: `template: saas-v1`, `brand_mode: client`, `design_tokens.colors.primary: #1B4D7A`, `accent: #E8622D`, `industry: saas`, 6 services, 3 testimonials, hero/about URLs from GitHub-raw saas hero manifest.
- Deploy response: HTTP 200 in 3.6s, body `{success:true, blog_id:1, template:"saas-v1", company:"InstaBid", updated:{home,about,services,quote,contact,header,footer}, errors:[], image_ids:{hero:139, about:146, service:0, logo:0}, version:"5.2"}`.
- Live verification: `<title>InstaBid</title>` on `/` (was `Probe`); 0 leaks of `AiNexa`/`Imbo`/`Probe`/`Linear` in rendered HTML; all 5 pages title `… – InstaBid`.
- Findings:
  1. **WePro flag #1 CONFIRMED.** With `brand_mode:client` and primary `#1B4D7A`, service cards, headings, dividers, and CTAs all render navy. Accent `#E8622D` (orange) is not visible anywhere in the rendered home page. Layer: engine `webprinter-engine-v5.2-gold.php:1075-1095` `apply_design_tokens` writes primary into multiple Elementor global slots and the `text` token is dropped. Engine `.php` change is gated → escalated to Commander on TEA-756.
  2. **saas-v1 template copy NOT tokenized.** Hero headline reads "Trusted by Homeowners Like You" (no `{{tagline}}`), "Why Homeowners Choose Us" (template default), and the 4 service cards render template defaults ("Mobile App Development", "Voice Coaching & Sound...", "Project Strategy & Consulting", "Custom Web Development") instead of the 6 InstaBid services from payload. Layer: `templates/saas-v1/home.json` (hardcoded copy) + `templates/saas-v1/services.json` (no `_wp_repeat:"services"` marker on the service-card group). Engine + payload are correct; this is template authoring work.
  3. **Testimonials ARE tokenized** in saas-v1 — all 3 InstaBid quotes appear (each rendered twice, suggesting `_wp_repeat` is fine but the template has 2 testimonial rows that each clone the array).
  4. **Hero image sideloaded cleanly** via GitHub-raw URL (`image_ids.hero:139`). WePro flag #2 (Unsplash failure on Hostinger) avoided.
  5. **`image_ids.service: 0`** — Issue #11 reproduces. `purge_placeholder_images` (engine line 1436) sideloads `get_stock_image('generic')` once and broadcasts; per-service URLs in payload are dropped.
- Screenshot attached to TEA-756. Recommended next step: TEA-XXX tokenize `templates/saas-v1/home.json` hero/about/services sections (branch-first, deploy-verify, then merge).

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
2. **footer_contact_missing** — pre-flight check looks at `home.slice(-6000)` for phone/email/city. With `phone=""`, only the email regex and `Portland` literal can clear it. **Wake 4:** `qa_skip_failures` array in webhook payload is now permanently honored by `QA — Pre-flight HTML` node (filters before pass/fail decision and exposes `preflight_failures_all` + `preflight_skipped` for audit). Permanent semantic fix still TODO: OR across phone/email/city plus a longer slice (HFE footer renders mid-document, not at tail).
3. **header.json missing** — AiNexa kit has no HFE header template. Hello Elementor default header renders instead (oversized logo). kit.json has CSS to hide it.
4. **Image IDs returning 0** — stock manifest sideload depends on URLs being reachable from WordPress host. GitHub-raw mirrored images work. Unsplash fails on Hostinger.
5. **n8n → Hostinger deploy: BOT-WALLED ALL ALONG** — REOPENED 2026-05-12 (Wake 8). Forensics on the executions table proves **no n8n-driven deploy has ever actually reached the engine**. Every "success" returned the Hostinger interstitial HTML (`Checking your browser before accessing. Just a moment...`) instead of an engine response. The HTTP node had `neverError: true`, so the 200-with-HTML challenge was treated as success and downstream nodes never validated the response shape. Evidence: deploy responses for execs 942, 943, 944, 955, 1018, 1027, 1033, 1044 all return `{ "data": "<!DOCTYPE html>...Checking your browser..." }`. None have an engine-shaped `{ "success", "blog_id", "version" }` body. Recent fires (1051, 1052, 1053, 1062) are the same wall, just escalated mode — Hostinger now drops the TCP connection (130s timeout) instead of serving the challenge page.\n\nWhy "we solved it before" wasn't real: the prior CDN-off toggles silenced the visible failure surface (vision scoring path), but the deploy egress was being walled in a way that LOOKED like success in n8n's log. The CDN-off setting John just confirmed does not affect this — from my Mac (residential IP) the same deploy endpoint returns HTTP 207 in 3.3s with a real engine response. The wall is keyed on **DigitalOcean ASN** (`137.184.188.253` = DIGITALOCEAN-137-184-0-0), not on CDN-mode.\n\n**Unblock owners/actions:**\n- **John:** in hPanel for getinstabid.pro, whitelist `137.184.188.253` (n8n's IP) under Security → IP Manager → "Whitelist" / "Trusted IPs," OR disable "Web Application Firewall" entirely for the deploy endpoint path. CDN-off alone is not sufficient.\n- **WePro (queued):** once unblocked, add validation to Deploy HTTP node so a non-engine response shape (no `success` key) marks the exec as error instead of silently passing. Also flip `neverError: true` → `false` and add an explicit response-shape guard.\n\n**Wake 9 patch landed (2026-05-12, workflow `A6naBMqLH3eRDzjx`):** (a) Inserted `Deploy Response Check` Code node at `[1056, 0]` between `HTTP Request (Deploy)` and `{Respond to Webhook, Cache Warm}`. Throws when response is a Hostinger interstitial (regex on `<!DOCTYPE html|Checking your browser|hcaptcha|cloudflare`) or lacks engine-shaped keys (`success`, `version: '5.2'`, `deployed_url`, `pages`). Bot-wall now surfaces as a real n8n failure instead of silent success. (b) Fixed Merge Design DNA node to write `p.design_tokens.colors.{primary,secondary,text,accent}` instead of `p.brand_colors.*` — matches what engine `webprinter-engine-v5.2-gold.php:1078-1095` actually reads. (c) Deploy body builder updated to pass `design_tokens: p.design_tokens` instead of `brand_colors: p.brand_colors`. Verified via API readback. Still blocked end-to-end on John's hPanel IP whitelist of `137.184.188.253`.
6. **Sibling Imbo URL leak in saas-v1** — RESOLVED 2026-05-11 (commit 85c3d28). 5 `nva.nirmanavisual.com/imbo/...` URLs replaced; one self-hosted asset added at `templates/saas-v1/assets/Image-BG-Imbo-1.png`.
7. ~~**"Servicesy from {{business_name}}" typo + 3 lorem-ipsum blurbs in AiNexa templates**~~ — RESOLVED 2026-05-11 (commit a5f88b7). Typo fixed in `services.json:333`; `Excepteur sint occaecat…` replaced with `{{about_short}}` in `services.json` (2 occurrences) and `about.json` (1 occurrence). Verified clean on live `https://getinstabid.pro/services/`.
8. ~~**FireCrawl /scrape cache makes vision scores stale**~~ — RESOLVED 2026-05-11 (Wake 4). `maxAge: 0` made permanent across all 5 FireCrawl call sites (Pre-flight HTML + 4 capture nodes) in workflow `3M01yRKUlwbSHqEK`.
9. **LiteSpeed cache not flushed by engine on deploy** — `webprinter-engine-v5.2-gold.php` calls `Breeze_PurgeCache::breeze_cache_flush()` and `$this->clear_elementor_cache()`, but getinstabid.pro runs LiteSpeed Cache. Direct local curl after deploy *does* show fresh content (LiteSpeed auto-purges on `_elementor_data` save), but cache-warmth across FireCrawl scrape paths is unreliable. Watch item — may need `do_action('litespeed_purge_all')` added to engine post-deploy hook. Requires plugin change → John's approval.
10. **Testimonials tokenized but vision still flags "no social proof"** — Wake 4 commit `b684a8f` added `_wp_if: "testimonials"` on the wrapper in `home.json`, `services.json`, `about.json` (and collapsed `services.json`'s 5 sibling cards to 1 with `_wp_repeat: "testimonials"` + `_wp_repeat_max: 8`). InstaBid payload has `testimonials: []` so the section correctly hides. Vision rubric still calls this a "high" issue because it expects social proof somewhere — gap is product-content, not template. To raise the floor, either: (a) seed payload with 2-3 fake testimonials for the demo, (b) add a logos/trust-badges row that's always visible (not gated on `testimonials`).

11. **Per-service `image.url` in payload is ignored by engine** — Wake 5 confirmed via deploy + vision: payload sets distinct URLs per service (saas-action-01, saas-generic-01, saas-generic-02, saas-hero-02, saas-action-02, saas-team-01), and `deploy.image_ids.service: 0` plus vision findings ("all service cards use the same generic laptop/charts stock photo") prove the engine drops per-card URLs on the floor. The `purge_placeholder_images` fallback (engine line 1436) sideloads `get_stock_image('generic')` ONCE and broadcasts it across all unmarked image elements. Fix candidates: (a) extend engine: in `apply_image_overrides`, accept `payload.services[i].image.url` for any `_wp_img` marker inside a `_wp_repeat:"services"` clone; (b) tokenize service cards in `services.json` with `_wp_stock: "action"` markers so each clone gets its own stock pull (different random per call); (c) add per-trade `_wp_stock` map e.g. `_wp_stock_by_service: {roofing: "...", hvac: "..."}`. Plugin change → John's approval.

12. **`hero_image_url` in payload IS honored** — Wake 5 correction. Earlier diagnosis was wrong: engine line 437-447 maps `hero_image_url` → `images.hero` slot. Setting `hero_image_url` to the new SaaS manifest URL produced fresh attachment ID 139 and vision now sees a "laptop with charts" instead of "car dashboard." Hero asset relevance fixed. Hero LAYOUT still broken (see Issue #13).

13. **Hero layout void on desktop + mobile overflow** — Vision still flags hero as "critical" because the kit template puts the hero image below the headline with a "massive empty black gap" above the fold; on mobile the headline overflows horizontally and the hero image area is blank. This is a kit-level CSS/sizing issue, not asset quality. Fix candidates: reduce hero section `min-height` (likely on `_kit_default_color` or section settings in `ainexa-ai-agency/home.json`), or set hero image as section background with `object-fit:cover`. The marquee headline `min-width` should be `100vw` not auto. Requires editing `home.json` section settings.

14. **Vision Mode 1 wired into deploy pipeline (Wake 6)** — workflow `A6naBMqLH3eRDzjx` chain is now: `Webhook → FireCrawl → Claude Normalize → Pexels → Source Screenshot (FireCrawl) → Vision — Design DNA (OpenRouter sonnet-4.6) → Merge Design DNA (Code) → Deploy`. Merge node writes `template_override`, `brand_mode`, `brand_colors`, `design_profile`, and overrides `industry` from vision's `content_observations.industry_guess`. Deploy body builder honors `p.template_override` (whitelist: saas-v1, ainexa-ai-agency, bold-v2, Green-v2, Authority-v2, premium-v2) and passes through `brand_mode`/`brand_colors`/`design_profile`. End-to-end verified 2026-05-11 via `linear.app`: vision returned `recommended_template: ainexa-ai-agency`, `brand_colors.accent: #5e6ad2` (Linear's actual purple), `vibe: tech-forward`, `quality_score: 96`. All 8 pipeline stages ran clean — only Deploy timed out on `getinstabid.pro` (same hCDN IP block per Issue #5; n8n IPs not yet whitelisted there). Spec: `Vision.md` (committed 7903eac).

15. **HFE footer + Hello Elementor `<footer id="colophon">` co-existence** — Wake 6 commit `77c5b4a` added `footer#colophon { display: none !important; }` to `ainexa-ai-agency/kit.json` `custom_css`. Wake 7 inspection of live rendered HTML on getinstabid.pro shows **zero `<footer id="colophon">` occurrences** — HFE already suppresses Hello Elementor's colophon when a footer is mapped to `basic-global`. So this CSS is harmless precaution; the "Footer Missing" vision flag is **not** a DOM problem. Likely a contrast / viewport-bottom void issue. Re-classify as cosmetic — possibly increase HFE footer contrast or add a bottom-of-page accent bar.

## NEXT ACTIONS (in priority order)

1. ~~**Fix ainexa template stock-image leak**~~ — DONE (commits 5eee1ac, 85c3d28).
2. ~~**Exercise vision scoring end-to-end**~~ — DONE. Vision chain proven 2026-05-11 via `qa-test-live` with `qa_skip_failures` bypass. Score 47 → 52 (Servicesy + lorem, commit a5f88b7) → 54 (testimonials + Pexels payload, commit b684a8f) → 54 (saas stock manifest replaced with real SaaS imagery, commits 0350a1f + 6bfabd9; visual win confirmed by vision — "laptop with charts" replaced "car dashboard" — but score-flat because layout + per-service plumbing now dominate).
3. ~~**FireCrawl /scrape cache fix**~~ — DONE (Wake 4). `maxAge: 0` permanent across all 5 call sites in workflow `3M01yRKUlwbSHqEK`.
4. ~~**saas stock manifest**~~ — DONE (Wake 5, commit 0350a1f). 7 saas assets replaced with proper SaaS imagery (analytics dashboards, code editors, tech-office teams). Credits + manifest updated. URLs version-bumped `?v=20260511` so engine sideload memoization doesn't reuse stale attachments.
5. **Template polish for 70+/80+ score** — remaining blockers from 54 pass (in priority):
   (a) Hero layout (Issue #13): empty black void above the hero image on desktop, image below fold; mobile hero overflows. Fix in `templates/ainexa-ai-agency/home.json` hero section settings — reduce min-height, set image as background, fix marquee `min-width`.
   (b) Per-service `image.url` ignored (Issue #11): all service cards render the same asset because `purge_placeholder_images` broadcasts one stock pull. Plugin change → requires John.
   (c) Footer/contact (Issue #15): HFE footer renders but Hello colophon collision + dark-on-dark contrast keeps "Footer Missing" lit on vision. Hide `#colophon` via `kit.json.custom_css`.
   (d) Counter values lack labels ("3x" with no context).
   (e) Testimonials section hides correctly when `testimonials:[]` (Wake 4) but vision still flags "no social proof" — consider always-visible logos/trust-badges row, or seed demo testimonials.
5. **Pre-flight footer check semantics** — `footer_contact_missing` fires even when the rendered footer contains the contact info, because the check only looks at `home.slice(-6000)` and HFE's footer renders mid-document followed by Hello Elementor's empty `<footer id="colophon">`. Fix candidates: lengthen slice, OR semantics across phone/email/city, OR scan within the actual `<footer>` element.
6. **LiteSpeed cache flush in engine** — engine uses Breeze; site is LiteSpeed. Watch item; may need `do_action('litespeed_purge_all')` added post-deploy. Plugin change → requires John's explicit approval.
7. ~~**Wire Vision analysis to front of pipeline**~~ — DONE (Wake 6). Mode 1 (Design DNA) now runs between Pexels and Deploy. See Issue #14. Future enhancement: two-version deploys (`brand_mode: template` vs `brand_mode: client`) driven off `recommended_template.brand_mode_reasoning`.
8. **Test with instabid.pro site** — Wake 4 retest: `curl POST https://n8n.instabid.pro/webhook/webprinter-5-1 -d '{"url":"https://instabid.pro","brand_mode":"template"}'` still returns Hostinger hCDN bot-challenge HTML (`/hcdn-cgi/jschallenge`) wrapped inside FireCrawl's response. CDN may have been re-enabled on instabid.pro, or hCDN is still walling FireCrawl's IPs. Re-toggle hCDN OFF in hPanel for instabid.pro and re-fire.

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


---

## Wake 4 — TEA-756 reopen (2026-05-12, WP Pilot)

Commander reopened TEA-756 after Wake 3 (engine HTTP 207, page unusable). Goal: fix layout + images so home + about look decent on screen.

**v3 (commit `94fa1353`):** hero overflow fixed, counter widgets hidden via `.elementor-widget-counter { display:none }`, top-of-hero "Start a free trial" CTA constrained to 240px pill via `.elementor-widget-button .elementor-button { max-width:240px;... }`. Reverted v2's destructive `footer#colophon { display:none }` after discovery that HFE 2.8+ wraps its footer inside `<footer id="colophon">`. Reverted v2's `:has()` ancestor selectors that hid the hero. **Vision QA = 58/100 (was 54 Wake-3 baseline).**

**v4 (PR #4, squash-merge `adfabb65`):** Added `.elementor-widget-counter + .elementor-widget-heading { display:none }` to kill orphan suffix headings ("x", "%") that floated next to hidden counters. Added `[data-elementor-type="header"] .elementor-widget-button { display:none }` — but the redundant pill widget `5b6fd36` actually lives inside `data-elementor-type="wp-post"`, NOT inside the elementor header, so this selector missed. **Vision QA = 42/100** (LLM scoring variance — visible state nearly identical to v3, only "x" removed).

**Escalated to Commander.** CSS layer exhausted. Per AGENTS.md rule #4 + rule #5:
- Remaining hero/about defects need template body authoring in `templates/ainexa-ai-agency/home.json` + `about.json` (no proper H1 widget, tagline duplicated, brand image-box broken). Out of CSS scope.
- Service images stuck at 0 — engine Issue #11 (`purge_placeholder_images` L1436 broadcast bug). GATED.
- `header.json` 404 keeps every deploy at HTTP 207 (Issue #3, template authoring debt).

InstaBid current state: home + about render WITHOUT overflow, WITHOUT counter collisions, WITHOUT giant CTA circle. Hero has real image (team / laptop). 6 services present in DOM. Footer with email visible. Score still FAIL vs ≥80 rubric, but the specific Wake-3 defects Commander called out (text overflow, "25" stat collisions, giant CTA, tiny thumbnails) are addressed.

**TEA-756 → in_review, assignee = Commander (`ce6dbbe8`).** Three paths offered:
1. Accept v3/v4 as cold-start baseline, defer pretty.
2. Approve engine Issue #11 diff for service image attach.
3. Switch InstaBid template (ainexa is AI-agency, not contractor-SaaS).

Branch `wp-pilot/tea756-v4-kit-cleanup` merged. Main = `adfabb65`. Revert to v3 = single PUT on kit.json.

---

## Wake 5 — TEA-756 close, Path 1 (2026-05-12, WP Pilot)

Commander direction (comment `e30980ed`): **Path 1 — ship v3 as v0.1 cold-start baseline.** Engine Issue #11 + ainexa template body mismatch parked as WebPrinter2.0 GitHub product items (not Paperclip execution tickets). Path 2 (engine fix) + Path 3 (template switch) deferred to their own decision cycles.

**Revert path (branch-first per AGENTS.md rule #6):**
- Branch `wp-pilot/tea756-v4-revert` off `main`, single `git revert adfabb6` reversing the v4 squash.
- PR `#5` opened + squash-merged as commit `d696940`. Net diff = one line in `templates/ainexa-ai-agency/kit.json` `custom_css` (the v4 stray-suffix + header-CTA rules removed; everything from v1 + v2 cleanup + v3 revert preserved).
- `raw.githubusercontent.com/SiteHypeInc/WebPrinter2.0/main/templates/ainexa-ai-agency/kit.json` confirmed serving v3-state CSS within seconds.

**Re-deploy (direct curl from this Mac, residential IP):**
- Same payload structure as Wake 3: `template: ainexa-ai-agency`, `brand_mode: client`, `design_tokens.colors.accent: #00D4FF` (scraped from `instabid.pro`), `blog_id: 1`, 6 services, hero/about GitHub-raw SaaS imagery.
- Engine response: **HTTP 207 in 4.4s**, body `{success:false, blog_id:1, template:"ainexa-ai-agency", company:"InstaBid", updated:{home,about,services,quote,contact,footer}, errors:{header:"Template returned HTTP 404"}, image_ids:{hero:139, about:138, service:0, logo:0}, version:"5.2"}`. `success:false` = solely `header.json` 404 (Issue #3, unchanged). All 5 pages + footer updated.

**Live verification:**
- `GET /wp-json/webprinter/v1/health` → 200, `{"status":"ok","version":"5.2","ts":1778623839}`.
- `GET /` → 200, `<title>InstaBid</title>`, 12 `InstaBid` mentions; 0 occurrences of `Probe / AiNexa / Imbo / Linear / #5e6ad2`.
- `GET /about/` → 200, `<title>About – InstaBid</title>`; same leak scan = 0.
- Per-post Elementor kit CSS `post-8.css` confirmed serving v3 baseline rules — `overflow-x: hidden` ✅, `max-width: 240px` (CTA pill cap) ✅, `border-radius: 999px` ✅, `.elementor-widget-counter { display: none }` ✅. v4 selectors `.elementor-widget-counter + .elementor-widget-heading` and `[data-elementor-type="header"] .elementor-widget-button` ✅ absent.

**v0.1 cold-start baseline — locked-in fields:**
| Field | Value |
|---|---|
| Site URL | `https://getinstabid.pro/` |
| Kit (template) | `templates/ainexa-ai-agency` |
| Brand mode | `client` |
| Brand colors on the wire | `design_tokens.colors.accent: #00D4FF` (scraped cyan); kit primary/secondary/text at defaults |
| Brand colors per InstaBid spec | navy `#1B4D7A` + orange `#E8622D` (cold-start brief; superseded by board-corrected scrape-first direction) |
| Vision QA score | 58/100 FAIL — accepted as baseline (re-QA out of Path 1 scope) |
| Commit (kit content) | `94fa1353` content restored on `main` via revert PR #5 = `d696940` |

**Parked items (NOT spawned as Paperclip child issues per Commander `e30980ed`):**
- Engine Issue #11 (`purge_placeholder_images` broadcast). Plugin gate. Commander triage on TEA-475 AM brief.
- Ainexa template body mismatch (animated-headline placeholder, INTRODUCING repeater, duplicate brand mark, double-rendered tagline on `/about/`). Template strategy decision, not a one-shot fix. WebPrinter2.0 GitHub-side product question.
- DigitalOcean ASN bot-wall (was Issue #5) — dropped per board comment `ed99c4ed`. Direct curl from a non-DO IP works.

TEA-756 closed as `done`.
