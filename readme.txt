=== Hoptrail – Redirect Loop & Chain Checker ===
Contributors: paulacato
Tags: redirects, redirect loop, redirect chain, 301, seo
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds redirect loops, chains and redirects you don't need, across every redirect plugin on the site at once, and fixes the clear-cut ones.

== Description ==

Redirects pile up. A site that changed redirect plugins once or twice, or runs an SEO plugin next to a redirect plugin, ends up with rules that send visitors from A to B to C, rules that send them in circles, and rules that do nothing at all. Each plugin only knows its own rules, so none of them can see that its rule and another plugin's rule together form a loop.

Hoptrail reads the rules of all supported redirect plugins, merges them into one graph and follows every rule to where it really ends.

= What it finds =

* **Loops**: including loops that run through several plugins (one plugin sends A to B, another sends B back to A).
* **Chains**: two or more hops before a page loads, with a suggestion to point the first URL straight at the final one.
* **Conflicts**: the same URL redirected to different targets by different rules.
* **Duplicates**: the same redirect defined twice.
* **Self-redirects**: source and target are the same URL (up to case, trailing slash or encoding).
* **Redundant rules**: WordPress already redirects the old slug of a post.
* **Hidden content**: a redirect on the URL of a published post or page.
* **Broken targets**: a final target that matches no post, page or term.
* **Invalid patterns**: regular expressions that never match.

Regex and wildcard rules are followed too.

= Supported redirect plugins =

* Redirection
* Yoast SEO Premium (redirect manager)
* Rank Math SEO (redirections module)
* Safe Redirect Manager
* Simple 301 Redirects
* 301 Redirects – Redirect Manager
* SEOPress (the redirect set on a post)
* Slim SEO (redirection module)
* SmartCrawl (URL redirection)
* The SEO Framework (the 301 redirect set on a post)

Hoptrail is not affiliated with or endorsed by any of these plugins or their authors. Other plugins can be added through the `hoptrail_sources` filter.

= Dashboard =

Under Tools → Hoptrail:

* A health score, issues per type and their trend.
* Charts of the health score and of issues by severity over time.
* A searchable, filterable list of issues, each showing the full redirect path and which plugin owns every hop. Search with plain text or, with the .* switch, a regular expression (such as `^/blog/`). When a scan finds nothing, an "All clear" overview shows the checks it passed.
* **Verify live**: follows the real redirects of an issue over HTTP, on your own site only, to confirm what visitors get.
* **Apply fix**: only where the fix is clear-cut. A chain is shortened by pointing its first rule at the final URL; a duplicate, self-redirect, redundant rule or rule hiding content is switched off. You confirm each change first.
* **Leftover redirects**: a redirect plugin that is no longer active but still holds rules is detected. Review its rules in a dialog, then migrate them into an active redirect plugin, delete single rules, or delete all of its data (and, for plugins with their own tables, the tables). Regex, wildcard and prefix rules are migrated as they are where the other plugin supports them; into a plugin that only holds exact rules (Simple 301 Redirects, 301 Redirects), they are copied as exact rules for the URLs your site is known to have: published content, the sources and targets of other rules, and URLs from Redirection's 404 log. Only plain paths become rules (no spaces, control characters, markup or `*`), and what is migrated is exactly what the dialog listed: if anything changed meanwhile, you are asked to review it again. On a multisite network, only a network administrator can drop another plugin's tables.
* **Scan history** with a date picker (limited to the days that have scans) and sortable columns.
* **Scheduled scans**: daily, weekly (on the weekdays you pick) or monthly (on a day of the month or the last day), at the hours you pick, in the site's timezone.
* **Retention**: keep scans for a number of days, or keep only the newest number of scans. Old scans are removed every Sunday around 03:00 (and right after every scan when you keep a number of scans).
* **Quick walkthrough**: a short tour of the dashboard on your first visit, started from a pointer on the Tools menu. After an update that adds features, each administrator gets a walkthrough of only what is new. Show it again any time from the bottom of the dashboard.
* **Working together**: when other administrators have the dashboard open, you see who; when one of them (or a scheduled scan, WP-CLI or an AI client) changes something, your dashboard updates within about 15 seconds and says what happened. Settings you are editing are never overwritten.

Dates and times follow the site's date and time formats, timezone and language. When your computer runs on another timezone than the site, scheduled times also show your own clock. The dashboard follows your system's light or dark mode and its contrast preference, and was checked against WCAG 2.2 AA in all of them.

= From WP-CLI =

* `wp hoptrail scan [--format=table|json|summary]` runs a scan and prints the issues.
* `wp hoptrail scan --porcelain --fail-on=loop,conflict` prints only the health score and exits with code 1 when loops or conflicts exist, so a deploy pipeline can stop on them (`--fail-on=any` for every type).
* `wp hoptrail purge-logs [--all]` removes old scan history.

= Abilities (WordPress 7.1+) =

Four abilities for the WordPress Abilities API, so AI clients connected through the MCP adapter can work with Hoptrail for administrators (`manage_options`):

* `hoptrail/run-scan`: runs a scan and returns the health score, counts per type and severity, and the most severe issues with their redirect path and a suggested fix.
* `hoptrail/get-scan-results`: the same for the latest scan, without running a new one; optionally filtered by issue type.
* `hoptrail/get-next-scan`: when the next scheduled scan runs, the schedule, and the retention policy.
* `hoptrail/flush-logs`: asks to delete the scan history, the change log or both. An AI client can't delete anything with it: with `confirm: true` it files a request, and the logs are only deleted when an administrator approves that request on the dashboard (Tools → Hoptrail). Requests expire after an hour.

They never return user data (e-mail addresses in rule strings are masked) and never change a redirect rule. They are not exposed on the abilities REST API.

= Changing other plugins' rules =

Hoptrail only reads other plugins' rules, except when you choose **Apply fix**, **Migrate** or **Delete** for leftover rules:

* Before a fix is applied, the rule is read again; if it changed since the scan, nothing is changed.
* Rules are switched off where the plugin supports that (Redirection, Rank Math, Safe Redirect Manager, 301 Redirects, SEOPress, Slim SEO), so they can be switched back on there. Yoast SEO Premium, Simple 301 Redirects, SmartCrawl and The SEO Framework have no "off" state; there the rule is removed.
* Only plain URLs and paths are ever written into another plugin's storage.
* Migrating copies rules; the inactive plugin's data is left as it is until you delete it. A pattern copied as exact rules covers the URLs listed in the dialog, not URLs your site has never seen.
* Leftover data can only be deleted while its plugin is not active.
* Every change is written to a log with the rule before and after. One change runs at a time, never during a scan.

= Privacy =

Hoptrail does not contact any external service. The live check only requests URLs on your own site. Scan history and the change log are stored in your site's database, are kept for the period you set (90 days by default). Deleting the plugin removes everything it stored, on every site of a network: its tables, settings, scheduled events and the walkthrough progress per user. Redirect rules it changed or migrated in other plugins belong to those plugins and stay.

= On multisite =

Each site has its own dashboard, history and schedule. Network administrators get an overview of the latest scan of every site under Network Admin → Settings → Hoptrail.

= Source code =

The dashboard is built with React and TypeScript. Its sources and build configuration are included in the plugin (`assets/`, `package.json`, `package-lock.json`, `vite.config.ts`, `tsconfig.json`), and the code is on GitHub: https://github.com/paulAcato/hoptrail

To rebuild `dist/` from the plugin folder with Node 24 or newer: `npm ci`, then `npm run build`. The built file is not minified, so it stays readable.

== Installation ==

1. Install and activate the plugin.
2. Go to Tools → Hoptrail and choose Run scan.

== Frequently Asked Questions ==

= Does it change my redirects on its own? =

No. Scans only read. Rules change only when you choose Apply fix or Migrate and confirm it.

= Why does a loop have no Apply fix button? =

Breaking a loop means choosing which rule is wrong, and only you know that. Fixes are offered only where there is one clear answer.

= Can I undo a fix? =

Switched-off rules can be switched back on in the plugin that owns them. For removed rules (Yoast SEO Premium, Simple 301 Redirects), the old rule is in the change log.

= Does the live check slow down my site? =

It requests at most 20 URLs per check, one at a time, with a 5-second timeout, only when you click it. Your redirect plugin may count these requests as hits.

= My scheduled scan did not run at the time I expected. =

Scheduled scans run in the site's timezone (Settings → General), not your computer's: the dashboard shows both when they differ. WordPress also only runs scheduled tasks when the site gets a visit; on a quiet site, set up a real cron job that calls wp-cron.php.

= Why are SmartCrawl's rules listed as leftovers while SmartCrawl is active? =

They are not: rules of an active plugin are never leftovers. When SmartCrawl's URL Redirection module (or Rank Math's or Slim SEO's redirect feature) is switched off, its rules redirect nothing and are left out of the scan.

= My site has thousands of redirects. =

Rules are read in batches and analysed within a time budget. If a scan reaches the budget, it is marked as partial.

== Screenshots ==

1. The dashboard: health score, issues per type and their trend.
2. An issue with its full redirect path and the plugin behind every hop.
3. Scan history and the schedule and retention settings.
4. The dashboard in every colour scheme: light, dark, and both with more contrast.
5. The charts in every colour scheme.
6. Leftover rules of an inactive plugin: review them, migrate them into an active plugin, or delete them.

== Changelog ==

= 1.0.0 =
* First release: reads redirects from ten SEO and redirect plugins and finds loops, chains, conflicts, duplicates, self-redirects, redundant and hidden-content rules, broken targets and invalid patterns.
* Verify live, Apply fix, leftover detection with migration (patterns as exact rules where needed) and deletion, scheduled scans, retention by days or by number of scans.
* Regex search in the issue list, a versioned walkthrough, and live updates when other administrators change something.
* Log deletions requested by AI clients need an administrator's approval in wp-admin.
* WP-CLI commands and four abilities for the WordPress Abilities API.
