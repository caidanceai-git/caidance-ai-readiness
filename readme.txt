=== Caidance — AI-Readiness Score ===
Contributors: caidance
Tags: ai, schema, ai-search, aeo, llms-txt
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See what AI says about your site — a free 60-second scan, a clear fix list, and one-click fixes applied with your approval.

== Description ==

When someone asks ChatGPT, Claude, or Perplexity *"who's a good [your industry] near me?"* — what does the AI say about your site? If it can't read you clearly, it doesn't give you a bad review. It just doesn't mention you. You never see the miss.

**Caidance — AI-Readiness Score** scans your active WordPress site for the signals AI search and AI agents look for, gives you a 0–60 score with a clear band (Starter / Builder / Operator / Leader), and tells you in plain English the top three things to fix first.

It runs entirely on your site. No account required, no data sent anywhere, no external API calls.

= What gets checked =

Ten local checks run against your site:

1. **llms.txt** — the file AI agents check first
2. **Sitemap declared in robots.txt** — so AI can find your content
3. **Organization schema** — so AI knows who you are
4. **WebSite schema with SearchAction** — so AI can map your site
5. **FAQPage schema** — direct answers AI can cite
6. **Article schema on posts** — so blog content gets surfaced
7. **Author / Person schema** — for E-E-A-T signals
8. **Open Graph + Twitter cards** — for AI preview generation
9. **Canonical tags** — to prevent duplicate-content confusion
10. **AI crawler access** — does robots.txt allow GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, and Google-Extended? (Toggleable.)

Each check returns a clear pass / partial / fail with a plain-English explanation — and when your own firewall or CDN blocks the scan itself, checks report Unverified and stay out of your score instead of pretending to fail. No jargon, no false alarms.

= The Fix Engine — three one-click fixes, applied for you =

Caidance now closes three of its checks for you — always with your approval, never behind your back:

* **llms.txt** — creates the file AI agents check first, built from your real site name, tagline, industry, and a curated key-page list: cart, checkout, account, and legal boilerplate are excluded, homepage duplicates collapse into one Home line, and on WooCommerce stores the shop page and top product category and brand pages lead. Create-only: an existing file or an SEO plugin serving one is detected, named, and left alone.
* **AI-crawler access** — surgically removes robots.txt groups that block only AI crawlers (GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, Google-Extended). The complete original file is stored first; one click restores it byte-for-byte. Groups covering other crawlers are never auto-edited.
* **Organization + WebSite schema** — outputs homepage JSON-LD built live from your real site settings. A pure output switch: no files written, revert is instant, and it goes silent automatically if you install an SEO plugin.

Every fix follows the same flow: preview the exact change → approve → Caidance applies it, re-checks your site, and shows the before/after score → one click reverses it. Every apply, revert, and refusal lands in a local, append-only evidence log.

And the weekly scan now watches for **drift**: if an applied fix stops holding — a deploy removed your llms.txt, a migration reverted robots.txt — Caidance flags it and offers the one-click re-apply.

= Stack Sense — your stack, as an AI operator sees it =

The Tools page gains a **Stack** tab: your active plugins mapped into ten categories (forms, CRM, email, automation, SEO, and more) plus a handful of plain-English observations — two form plugins means intake is split; an automation plugin means alignment matters more, not less. Detected locally, nothing leaves your site, and deliberately unscored: the scored systems diagnostic is the Alignment Review on caidance.ai, which the Stack tab can pre-fill with what it detected.

= Industry-aware fix list =

Pick your industry from 11 options — Financial Services, Healthcare, Legal, Home Services, Nonprofit, Professional Services, Manufacturing, eCommerce, SaaS, Education, Local Retail & Restaurants — and the fix recommendations are tailored. The fix you see is the fix that actually matters in your industry.

= What this plugin does NOT do =

* It changes nothing without your approval. Every fix shows you the exact change first — the file content, the precise lines, the exact markup — and applies only when you click approve. One click reverses each fix, every action lands in a local evidence log, and anything owned by another tool (an existing file, an SEO plugin's output) is detected and left alone. No invisible changes, ever.
* It does not require a Caidance account. Install, scan, see your score.
* It does not phone home. Zero external HTTP calls. Results are stored in your own WordPress database.

= Want the full off-site picture too? =

This plugin scores your WordPress site itself. If you want to see how AI agents see your business across the whole web — review sites, social, your public footprint — Caidance offers a free 60-second snapshot at [caidance.ai/snapshot](https://caidance.ai/snapshot/?utm_source=wp_plugin&utm_medium=readme&utm_campaign=wp_org_v1).

For paid layers (Toolkit, Monitoring), see [caidance.ai/pricing](https://caidance.ai/pricing/?utm_source=wp_plugin&utm_medium=readme&utm_campaign=wp_org_v1).

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/caidance-ai-readiness`, or install via the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** screen.
3. Click the activation notice, or go to **Settings → Caidance** and pick your industry.
4. Click **Run scan now**. Your score and top 3 fixes appear in 5–15 seconds.
5. Future scans run automatically each week.

== Frequently Asked Questions ==

= Does this plugin send my data anywhere? =

No. The scanner runs entirely in PHP against your own site. Results are stored in your WordPress database. Nothing is sent to Caidance or any third party.

= What exactly does Caidance change on my site? =

Only the three fixes above, and only with your approval: it can create an llms.txt file, remove AI-crawler-only blocks from robots.txt (storing the complete original for one-click restore), and switch on homepage Organization/WebSite JSON-LD output. You preview the exact change first, nothing happens until you click approve, the result is re-checked and recorded in a local evidence log, and one click reverses each. Everything else the plugin does is read-only.

= Will the fixes overwrite my files or conflict with my SEO plugin? =

No. Every fix is conflict-aware. llms.txt is create-only — an existing file or a plugin serving one is left alone. robots.txt edits never touch groups that cover non-AI crawlers, and the original file is stored for byte-for-byte restore. Schema output defers to Yoast SEO, Rank Math, All in One SEO, The SEO Framework, SEOPress, and Slim SEO — and goes silent automatically if you install one later.

= Does the Stack tab send my plugin list anywhere? =

No. Stack detection runs locally against your own active-plugins list and a curated table shipped inside the plugin — no remote calls, nothing stored outside your database. The only time anything travels is when YOU click the Alignment Review link: the detected platform names ride along as URL parameters to pre-fill the first questions of the assessment.

= What happens to the llms.txt file if I uninstall the plugin? =

The file stays — you approved its creation and it is your site content. If you want it removed, use Revert this fix on the Tools page before uninstalling (revert deletes the file only if it still exactly matches what Caidance wrote).

= Do I need a Caidance account? =

No. The plugin is fully usable on its own. The links to caidance.ai are optional — they point to the broader off-site analysis the plugin cannot do from inside WordPress.

= Will this slow down my site? =

No. The scan runs only when you click Run scan now or on the weekly schedule. It runs in the admin context, never on front-end pages.

= My score is lower than I expected. Now what? =

Open **Tools → Caidance Scan**. Each failed check has a plain-English explanation of why it matters and the first step to fix it. The fixes are ordered by impact, so start at the top.

= My site already has SEO covered. Why do I need this? =

Traditional SEO targets Google ten-blue-link rankings. AI search is different. AI agents read schema, llms.txt, and structured signals that do not always overlap with classic SEO. A site that ranks well on Google can still be invisible to ChatGPT. This plugin checks the AI-specific signals.

= What does the AI crawler check do exactly? =

It looks at your `robots.txt` and checks whether five major AI crawlers are allowed: GPTBot (OpenAI), ClaudeBot (Anthropic), PerplexityBot (Perplexity), OAI-SearchBot (OpenAI search), and Google-Extended (Google AI). Some sites intentionally block these. If yours does, toggle the check off in Settings.

= Does this work with WooCommerce? =

The 10 universal checks all work on a WooCommerce site, and the llms.txt fix is store-aware: it reads your assigned WooCommerce pages so cart, checkout, and account never appear in the file, and it features your shop page plus your top product category and brand pages instead — the pages an AI shopping agent actually needs. A future version will add WooCommerce-specific checks (product schema, breadcrumbs, review aggregates).

= Does this work on a Multisite Network? =

Single-site only for v1. Multisite Network support is on the roadmap.

= Is it compatible with my caching plugin? =

Yes. The scan reads your site the same way an AI crawler would — including any HTML your cache layer serves.

= My score suddenly collapsed and checks say the scanner is blocked =

Firewall and CDN bot protection (Cloudflare Bot Fight Mode, for example) sometimes challenges the plugin's own scan requests — the same way it challenges other automated clients. Since 1.4.2 the scanner detects this instead of failing the checks: affected checks are marked Unverified and excluded from your score, a banner explains what was detected, and the weekly drift watch stays quiet until the site is readable again. Check your firewall or CDN bot settings, or allowlist requests from your own server, then re-run the scan. Worth knowing: if the scanner is being challenged, real AI crawlers may be too.

== Screenshots ==

1. The Dashboard widget — your AI-readiness score, band, and top 3 fixes at a glance.
2. The full readout at Tools → Caidance Scan — all 10 checks with pass/partial/fail and plain-English explanations.
3. The Settings page — pick your industry, run a scan, see your scan history.
4. Stack Sense — your active plugins mapped into alignment categories, plain-English observations, and the Alignment Review link that arrives pre-filled.
5. A fix preview — the AI-crawler robots.txt fix shows the exact lines it will remove and the byte-for-byte resulting file before you approve. One click restores the original.

== Changelog ==

= 1.4.2 =
* Fixed: firewall/CDN bot protection (e.g. Cloudflare) challenging the scanner's own requests no longer collapses your score. Blocked checks now report a fourth outcome — Unverified — excluded from the 0–60 score entirely: blocked is not the same as failing.
* Added: blockage detection with three signals — the cf-mitigated challenge header or a known challenge page on the response; robots.txt reachable while page fetches fail; and a sudden all-fetch failure right after a scan that could read the site (treated as blockage, not regression). A clean 404 still counts as a real miss.
* Added: a plain-English banner naming the likely cause (your firewall or CDN challenging automated requests — including this scanner's, and possibly AI crawlers') plus the exact evidence detected. When 3 or more checks are blocked, the score shows as unavailable instead of a misleading number.
* Fixed: the weekly drift watch no longer warns that applied fixes "stopped holding" when the scan was blocked — existing drift flags are preserved untouched until a scan can actually read the site.
* Still zero external calls — detection runs entirely on responses the scan already fetched plus your stored scan history.

= 1.4.1 =
* Improved: the llms.txt fix now curates its Key pages list instead of listing the first pages it finds. Cart, checkout, account, legal boilerplate, and thank-you pages are excluded — detected via your assigned WordPress/WooCommerce page settings plus slug patterns, so leftover duplicates like cart-2 are caught too. Homepage aliases collapse into the one canonical Home line, and meaningful pages (about, contact, services, resources) are preferred.
* Improved: on WooCommerce stores the list now leads with your shop page and your top product category and brand pages — the pages an AI shopping agent actually needs. Picking eCommerce or Local Retail & Restaurants as your industry ranks category pages higher still.
* Unchanged: the fix remains preview-first, create-only, hash-verified, and one-click revertable. An llms.txt already applied on 1.4.0 is untouched — revert and re-apply if you want the improved file. Still zero external calls.

= 1.4.0 =
* Added: Stack Sense — a new Stack tab on the Tools page maps your active plugins into ten alignment categories with plain-English observations (split intake, missing CRM, automation compounding, SEO tool present but schema failing, unbridged ecommerce accounting). Local and read-only; deliberately unscored.
* Added: the Alignment Review deep-link — one click opens the caidance.ai systems assessment with your detected stack already pre-filled.
* No changes to the scanner, the fixes, or the plugin's zero-external-calls behavior.

= 1.3.0 =
* Added: the Fix Engine — two more one-click fixes join llms.txt. AI-crawler access surgically removes robots.txt groups that block only AI crawlers, with the complete original file stored for one-click byte-for-byte restore. Organization + WebSite homepage schema are pure output switches built live from your site settings.
* Added: drift watch — the weekly scan notices when an applied fix stops holding (a deploy removed llms.txt, a migration reverted robots.txt) and offers one-click re-apply. Quiet by design: the alert shows only on the Dashboard and the plugin's own screens.
* Added: standing conflict guards — schema output goes silent automatically if an SEO plugin is installed later; robots.txt groups covering non-AI crawlers are never auto-edited; mixed cases get precise manual guidance instead.
* Improved: all fixes now run on one shared framework with the identical preview → approve → verify → revert flow and the same evidence log.
* Still zero external calls — every fix, verification, and log entry runs entirely on your site.

= 1.2.0 =
* Added: the First Fix — Caidance can now create your llms.txt for you. Preview the exact file, approve it, and the plugin writes it, re-checks your site, and shows the before/after score.
* Added: one-click revert — the plugin deletes only the exact file it wrote, verified by content hash first.
* Added: conflict detection — an existing llms.txt file, or an SEO plugin already serving one (Yoast, Rank Math, AIOSEO), is detected, named, and left alone.
* Added: a local, append-only fix evidence log (who, when, what changed, before/after score) on the Tools page.
* Still zero external calls — the fix, the verification, and the log all run entirely on your site.

= 1.1.0 =
* Added: optional link from your results to the AI Visibility Cost Calculator (see the estimated revenue impact of your score).
* Added: optional Caidance Pilot card (continuous monitoring + AI advisor, $9.95/mo).
* No changes to the checks, scoring, or the plugin's local-only behavior (still zero external calls).

= 1.0.0 =
* Initial release.
* 10 local AI-readiness checks (llms.txt, schema family, OG/Twitter, canonical, sitemap, AI-crawler access).
* CDI-aligned 0–60 scoring with Starter / Builder / Operator / Leader bands.
* Industry-aware fix recommendations across 11 industries.
* Three admin surfaces: Settings, Dashboard widget, Tools page.
* Weekly automated re-scan with 12-scan history.

== Upgrade Notice ==

= 1.4.2 =
No more false alarms when your own firewall challenges the scanner: blocked checks are marked Unverified and excluded from the score, a banner names the likely cause, and the drift watch stays quiet until your site is readable again. Zero external calls, as always.

= 1.4.1 =
Better llms.txt files: cart/checkout/legal clutter and duplicate homepage links are gone, and WooCommerce stores lead with shop, category, and brand pages. Same preview-first, reversible fix; still zero external calls.

= 1.4.0 =
Stack Sense: a new Stack tab reads your installed plugins as an AI operator would — categorized inventory, plain-English observations, and an Alignment Review deep-link pre-filled with what it found. Local-only, unscored, zero external calls.

= 1.3.0 =
The Fix Engine: three one-click fixes (llms.txt, AI-crawler robots access, homepage schema) with exact previews, byte-for-byte restore, and drift watch. Conflict-aware; still zero external calls.

= 1.2.0 =
The First Fix: Caidance can now create your llms.txt — preview the exact file, approve, verified after, one-click revert. Create-only and conflict-aware; still zero external calls.

= 1.1.0 =
Adds two optional cards under your scan results: the free AI Visibility Cost Calculator link and the Caidance Pilot (monitoring + advisor). Checks and scoring unchanged.

= 1.0.0 =
First public release.
