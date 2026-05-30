=== Caidance — AI-Readiness Score ===
Contributors: caidanceai
Tags: ai, schema, ai-search, aeo, llms-txt
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See what AI says about your site. A free 60-second scan + a clear top-3 fix list, all inside your WordPress admin.

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

Each check returns a clear pass / partial / fail with a plain-English explanation. No jargon, no false alarms.

= Industry-aware fix list =

Pick your industry from 11 options — Financial Services, Healthcare, Legal, Home Services, Nonprofit, Professional Services, Manufacturing, eCommerce, SaaS, Education, Local Retail & Restaurants — and the fix recommendations are tailored. The fix you see is the fix that actually matters in your industry.

= What this plugin does NOT do =

* It does not auto-fix anything. The plugin shows you the gaps; you (or your developer) close them. No invisible changes to your site.
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

The 10 universal checks all work on a WooCommerce site. A future version (1.1) will add WooCommerce-specific checks (product schema, breadcrumbs, review aggregates).

= Does this work on a Multisite Network? =

Single-site only for v1. Multisite Network support is on the roadmap.

= Is it compatible with my caching plugin? =

Yes. The scan reads your site the same way an AI crawler would — including any HTML your cache layer serves.

== Screenshots ==

1. The Dashboard widget — your AI-readiness score, band, and top 3 fixes at a glance.
2. The full readout at Tools → Caidance Scan — all 10 checks with pass/partial/fail and plain-English explanations.
3. The Settings page — pick your industry, run a scan, see your scan history.

== Changelog ==

= 1.0.0 =
* Initial release.
* 10 local AI-readiness checks (llms.txt, schema family, OG/Twitter, canonical, sitemap, AI-crawler access).
* CDI-aligned 0–60 scoring with Starter / Builder / Operator / Leader bands.
* Industry-aware fix recommendations across 11 industries.
* Three admin surfaces: Settings, Dashboard widget, Tools page.
* Weekly automated re-scan with 12-scan history.

== Upgrade Notice ==

= 1.0.0 =
First public release.
