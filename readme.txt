=== Redirect Inspector ===
Contributors: paulacato
Tags: redirects, redirect loop, redirect chain, 301, seo
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds redirect loops, chains and redirects you don't need, across every redirect plugin on the site at once, and fixes the clear-cut ones.

== Description ==

Redirects pile up. A site that changed redirect plugins once or twice, or runs an SEO plugin next to a redirect plugin, ends up with rules that send visitors from A to B to C, rules that send them in circles, and rules that do nothing at all. Each plugin only knows its own rules, so none of them can see that its rule and another plugin's rule together form a loop.

Redirect Inspector reads the rules of all supported redirect plugins, merges them into one graph and follows every rule to where it really ends.

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

Redirect Inspector is not affiliated with or endorsed by any of these plugins or their authors. Other plugins can be added through the `redirect_inspector_sources` filter.

= Dashboard =

Under Tools → Redirect Inspector:

* A health score, issues per type and their trend.
* A chart of the health score and of issues by severity over time.
* A searchable, filterable list of issues, each showing the full redirect path and which plugin owns every hop.
* **Verify live**: follows the real redirects of an issue over HTTP, on your own site only, to confirm what visitors get.
* **Apply fix**: only where the fix is clear-cut. A chain is shortened by pointing its first rule at the final URL; a duplicate, self-redirect, redundant rule or rule hiding content is switched off. You confirm each change first.
* **Leftover redirects**: a redirect plugin that is no longer active but still holds rules is detected, and its rules can be copied (migrated) into an active redirect plugin.
* Scan history with a date picker and sorting, and scheduled scans on the weekdays and hours you choose.

The dashboard follows your system's light or dark mode and its contrast preference.

= From WP-CLI =

`wp redirect-inspector scan [--format=table|json|summary]` runs a scan and prints the issues.
`wp redirect-inspector purge-logs [--all]` removes old scan history.

= Changing other plugins' rules =

Redirect Inspector only reads other plugins' rules, except when you choose **Apply fix** or **Migrate**:

* Before a fix is applied, the rule is read again; if it changed since the scan, nothing is changed.
* Rules are switched off where the plugin supports that (Redirection, Rank Math, Safe Redirect Manager), so they can be switched back on there. Yoast SEO Premium and Simple 301 Redirects have no "off" state; there the rule is removed.
* Migrating copies rules; the inactive plugin's data is left as it is.
* Every change is written to a log with the rule before and after.

= Privacy =

Redirect Inspector does not contact any external service. The live check only requests URLs on your own site. Scan history and the change log are stored in your site's database, are kept for the period you set (90 days by default), and are removed when the plugin is deleted.

= On multisite =

Each site has its own dashboard, history and schedule. Network administrators get an overview of the latest scan of every site under Network Admin → Settings → Redirect Inspector.

= Source code =

The dashboard is built with React and TypeScript. The sources are included in the plugin (assets/) and on GitHub: https://github.com/paulAcato/redirect-inspector

== Installation ==

1. Install and activate the plugin.
2. Go to Tools → Redirect Inspector and choose Run scan.

== Frequently Asked Questions ==

= Does it change my redirects on its own? =

No. Scans only read. Rules change only when you choose Apply fix or Migrate and confirm it.

= Why does a loop have no Apply fix button? =

Breaking a loop means choosing which rule is wrong, and only you know that. Fixes are offered only where there is one clear answer.

= Can I undo a fix? =

Switched-off rules can be switched back on in the plugin that owns them. For removed rules (Yoast SEO Premium, Simple 301 Redirects), the old rule is in the change log.

= Does the live check slow down my site? =

It requests at most 20 URLs per check, one at a time, with a 5-second timeout, only when you click it. Your redirect plugin may count these requests as hits.

= My site has thousands of redirects. =

Rules are read in batches and analysed within a time budget. If a scan reaches the budget, it is marked as partial.

== Screenshots ==

1. The dashboard: health score, issues per type and their trend.
2. An issue with its full redirect path and the plugin behind every hop.
3. Scan history and the schedule and retention settings.

== Changelog ==

= 1.0.0 =
* First release.
