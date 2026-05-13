# Paywall UX simplification — product spec & implementation checklist

WordPress plugin only. Dotcom / Jetpack facilitator / ledger services are out of scope here.

## Goals

1. **402 with negotiated body:** JSON (current shape) or minimal **HTML** (excerpt + payment notice), driven by client signals—not one body for everyone.
2. **Smarter client classification:** Combine **CrawlerDetect** with **`Accept`**, **`Sec-Fetch-Mode`**, **`Sec-Fetch-Dest`** to separate “JSON / API-style” clients from “HTML document” clients (among both bots and, when allowed, humans).
3. **Audience stays configurable:** Keep the **audience** setting (`bots` vs `everyone`). **`everyone`** supports manual QA in a real browser and lets us explore a **human-facing unpaid experience** (same HTML payment-required template as document-style bots)—not a commitment to a full human checkout yet.
4. **Facilitator UI stays for now:** Keep the facilitator picker (and testnet path) until a **functioning wpcom facilitator** is deployed; product UX should still **bias toward wpcom** (default selection, copy) and **hide the wallet field when it is not needed** (see Phase C).
5. **Admin probes:** One primary control (e.g. **“Run checks”**) that runs **facilitator test** and **paywall probe** in a single flow (order: facilitator first, then paywall—or document chosen order).

---

## Behaviour matrix (authoritative)

**Status code:** Always **402** when the paywall blocks (including HTML path).

**Body choice (JSON vs HTML)** when blocked depends on **client presentation** (classification § below), not on bot vs human: **document-style** → **HTML 402** excerpt template; **JSON / API-style** → **JSON 402** (current shape + `PAYMENT-REQUIRED` header).

### When `paywall_audience = bots` (default)

| Client | Paywall? | On block |
|--------|-----------|----------|
| **Non-bot** | **No** — **full content** | — |
| **Bot + HTML document signals** | Yes | **402** + `text/html` — excerpt template + payment-required copy (+ x402 / header pointer). |
| **Bot + JSON / API signals** | Yes | **402** + `application/json` — current JSON body + `PAYMENT-REQUIRED`. |

### When `paywall_audience = everyone`

Any **in-scope, unpaid** client can be paywalled (QA + exploration of a future human flow).

| Client | Paywall? | On block |
|--------|-----------|----------|
| **Non-bot + HTML document signals** (e.g. normal tab) | Yes | **Same HTML 402** path as HTML-capable bots (excerpt template + payment-required copy). |
| **Non-bot + JSON / API signals** | Yes | **402** + `application/json` — same as bot JSON path. |
| **Bot + HTML document signals** | Yes | **402** + `text/html` — as above. |
| **Bot + JSON / API signals** | Yes | **402** + `application/json` — as above. |

**Production note:** `everyone` may have **SEO / indexing** implications (search crawlers can receive 402 + excerpt). Treat as an **explicit** mode; default remains **`bots`** until a product decision says otherwise.

---

## Classification order (v1)

Apply in order; first strong match wins where noted; otherwise combine bot flag with “preferred response family”.

1. **`Sec-Fetch-Mode` / `Sec-Fetch-Dest`** (when present): **`navigate` + `document`** → treat as **HTML document** intent (typical browser navigation).
2. **`Accept`:** contains **`application/json`** or a **`+json`** subtype → **JSON** intent for the response body when blocking.
3. **`User-Agent`:** **CrawlerDetect** → **bot** vs non-bot (drives **whether** the paywall applies when `audience = bots`; still used for analytics / future policy).

**Heuristic defaults (body shape, among clients that are actually paywalled):**

- **HTML document intent** → **HTML 402** excerpt path.
- **JSON intent** (or API-style `Accept` without document navigation) → **JSON 402** path.
- **Ambiguous (bot):** prefer **JSON** if `Accept` strongly suggests API; else **HTML** if document-like fetch metadata exists; else default **JSON** for unknown bots unless PR specifies otherwise.
- **Ambiguous (non-bot, `everyone` only):** same body rules as above—browser-like → HTML; API-like → JSON.

**Edge case:** With **`everyone`**, non-crawler **API clients** (`curl`, scripts) with `Accept: application/json` can receive **JSON 402** without being CrawlerDetect bots. With **`bots` only**, they remain **full content** unless you later add a separate “API paywall” flag.

---

## Implementation phases (suggested PRs)

### Phase A — Request plumbing & classifier (no UX change to 402 body yet optional)

- [x] Extend `Plugin::collect_headers()` / `PaywallController` request array to include **`Accept`**, **`Sec-Fetch-Mode`**, **`Sec-Fetch-Dest`** (canonical header names already normalized).
- [x] New small service (`PaywallClientProfile`), with pure PHP + unit tests:
  - inputs: `User-Agent`, `Accept`, `Sec-Fetch-Mode`, `Sec-Fetch-Dest` (and optionally `X-Requested-With`);
  - outputs (for Phase B): `is_bot`, `document_navigation_intent`, `json_accept_intent`, `xml_http_request` (see class docblock; no `prefers_*` until ambiguous-bot policy lands).
- [x] Thread profile into `RuleResolver` / `DefaultPaywallRule` **or** only into `PaywallController` after rule match—pick the smallest coupling (likely controller + rule context).

### Phase B — 402 response negotiation

- [x] Split `PaywallController::respond_402` (or parallel paths) to emit:
  - same **402** + **`PAYMENT-REQUIRED`** header;
  - **JSON** body (existing shape) vs **HTML** minimal template, chosen from **client presentation** (document navigation → HTML; otherwise JSON). **`paywall_audience`** still controls **who** is paywalled via rules; with **`everyone`**, unpaid **non-bot document** clients get the **same HTML 402** as document-style bots when `Sec-Fetch-Mode`/`Dest` indicate a document navigation.
- [x] HTML template: post **excerpt** (from `$post_id` / queried post), site title optional, payment line with **configured price** + note to inspect x402 headers.
- [x] Filters: `x402press_paywall_html_402_body` (`PaywallController::HTML_402_BODY_FILTER`), `x402press_paywall_excerpt_text` (`PaywallController::EXCERPT_TEXT_FILTER`).
- [x] Integration tests: `Accept` + bot UA (no document fetch metadata) → JSON body; `Sec-Fetch-Mode` navigate + document + bot UA → HTML contains excerpt; with **`everyone`**, non-bot document navigation → **HTML 402**; non-bot JSON `Accept` → JSON.
- [x] **Admin paywall probe:** `runPaywallProbe()` accepts **402** with **`application/json`** or **`text/html`** body (JSON path still validates JSON parse).

### Phase C — Audience & facilitator (staging; keep controls for testing)

**Until the wpcom facilitator is live in production**, keep **audience** and **facilitator** controls so we can test on **testnet** and flip **`everyone`** for human QA.

- [ ] **Audience:** keep setting in admin and in stored options; **no removal** in this phase. Optional: `x402press_paywall_audience` filter later if needed.
- [ ] **Facilitator:** keep picker and **`x402press_test`** (x402.org / Base Sepolia) for ongoing development.
  - **Default toward wpcom:** align UX with existing autopick where possible—**prefer `wpcom_x402`** when the companion registers it and Jetpack reports connected; otherwise fall back to test connector (already largely true—verify settings bootstrap + first-run story).
  - **Wallet field:** hide when **`x402press_managed_pool_pay_to`** returns a non-empty `payTo` for the selected connector (already reflected in `managedWalletFacilitators`). If wpcom is selected **without** a managed pool address yet, the wallet may still be required for `payTo`—do **not** hide solely on connector id until `payTo` is guaranteed (e.g. dev sets `X402PRESS_WPCOM_POOL_ADDRESS` or Dotcom supplies the pool).
  - Copy / help text: clarify **testnet vs WordPress.com** paths without implying the picker will disappear before wpcom ships.
- [ ] Update **README**, **PaywallIndicator** copy, and settings payloads as needed for the above (no “single hard-coded facilitator” messaging until wpcom is ready).

**Deferred:** Removing facilitator UI and hard-coding a single connector—revisit after wpcom facilitator deployment.

### Phase D — Admin “Run checks”

- [x] Replace two separate probe entry points with one **primary** action that runs facilitator test then paywall probe; keep detailed step output (expandable or two lines under one button).
- [x] i18n strings; avoid losing nonce / error surfacing from current flows.

**Phase D / Phase B note:** The paywall live probe accepts **HTTP 402** with **JSON or HTML** `Content-Type` (see `runPaywallProbe()` in `assets/src/index.jsx`).

---

## Code touchpoints (non-exhaustive)

| Area | Files / symbols |
|------|------------------|
| Headers | `src/Plugin.php` (`collect_headers`) |
| 402 orchestration | `src/Http/PaywallController.php` |
| Rule / audience | `src/Services/DefaultPaywallRule.php`, `src/Services/RuleResolver.php`, `src/Settings/SettingsRepository.php` |
| Bot UA | `src/Services/BotDetector.php` — compose with new classifier or inject |
| Admin UI | `assets/src/index.jsx`, `src/Admin/SettingsPage.php`, `src/Admin/PaywallProbeAjax.php`, `src/Admin/TestConnectionAjax.php` |
| Managed pool | `src/Services/FacilitatorHooks.php`, companion `JetpackSiteState.php` |

---

## Open items (resolve in first implementing PR)

1. ~~**Ambiguous client** default (JSON vs HTML) when both/neither signals present~~ **Phase B:** HTML 402 **only** when `document_navigation_intent` is true (`Sec-Fetch-Mode: navigate` and `Sec-Fetch-Dest: document`). If that is false, body is **JSON** (including when `json_accept_intent` or `xml_http_request` is true, and when all three are false). When document intent is true, HTML wins even if `Accept` lists JSON.
2. **HTML template** location: inline string in PHP vs small view file under `templates/` vs `wp_kses_post` + block template hook.
3. **Human grant / payment path** (cookies, wallet, Woo, etc.)—out of scope for B except messaging in the HTML template.

---

## Changelog

- **2026-04-26** — Phase B: `PaywallController` negotiates **JSON vs HTML** 402 bodies from `PaywallClientProfile` (`document_navigation_intent` → HTML excerpt template; else JSON). Filters `x402press_paywall_excerpt_text`, `x402press_paywall_html_402_body`; admin paywall probe accepts HTML 402.
- **2026-04-26** — Phase A: `PaywallClientProfile` classifier, stable `Accept` / `Sec-Fetch-*` keys on paywall requests, `x402press_paywall_client_profile` filter (402 body unchanged).
- **2026-04-26** — Phase D: unified Settings → x402 Pay **Run checks** (facilitator connectivity, then paywall probe); per-step results in admin UI.
- **2026-04-25** — Initial doc from agreed product decisions.
- **2026-04-26** — Matrix and Phase B/C/D revisions: **`everyone`** uses same HTML 402 as document-style bots for exploration/QA; **keep audience + facilitator UI** until wpcom ships; Phase C = staging (default wpcom, hide wallet only when managed `payTo` applies); Phase B notes probe updates after HTML 402.
