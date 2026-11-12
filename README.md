# Redirect Inspector

Finds redirect loops, chains and redirects you don't need across ten SEO and redirect plugins on a WordPress site at once, and fixes the clear-cut ones.

![Banner](.wordpress-org/banner-1544x500.png)

Supported: Redirection, Yoast SEO Premium, Rank Math, Safe Redirect Manager, Simple 301 Redirects, 301 Redirects – Redirect Manager, SEOPress, Slim SEO, SmartCrawl and The SEO Framework. More can be added through the `redirect_inspector_sources` filter.

The user-facing description is in [readme.txt](readme.txt), which is also what WordPress.org shows.

## Features

- **One rule graph over all plugins**: loops (also across plugins), chains, conflicts, duplicates, self-redirects, rules made redundant by WordPress's old-slug redirects, redirects hiding published content, broken targets and invalid regexes. Regex, wildcard, prefix and per-post redirects are all followed.
- **Verify live**: follows real hops over HTTP, on the site's own host only.
- **Apply fix**: only for clear-cut cases (shorten a chain, switch off a duplicate/self/redundant/hiding rule), confirmed inline, re-checked before writing, logged.
- **Leftover redirects**: rules of plugins that are no longer active; review in a modal, migrate into an active plugin, delete single rules or all data (and the plugin's own tables). Patterns go into exact-only plugins (Simple 301, 301 Redirects) as exact rules for known URLs: published content, every rule's source and target, then Redirection's 404 log (`includes/analysis/class-expander.php`). Sources must pass `Url_Normalizer::is_safe_source()` (log URLs with HTML entities are skipped), all expansion shares one time budget, and the real run is bound to the reviewed preview by a plan hash (refused with 409 when it changed).
- **Regex search**: the issue list searches source, target and every hop with PCRE (the plugins' own engine), with a low backtrack limit and invalid patterns refused (`Log_Repository::search_pattern()`).
- **Walkthrough**: a pointer on the Tools menu (plain JS, `assets/menu-pointer.js`) and a step-by-step tour on the dashboard (React `Popover`, `components/Tour.tsx`). Each step has a `since` version in `lib/tour.ts`; bump `Admin\Tour::VERSION` when a release adds steps and administrators who saw an older tour get only the new steps. Progress is per user (`redirect_inspector_tour` user meta). No jQuery.
- **Dashboard** (Tools → Redirect Inspector): health score, trends, severity chart, issue explorer, scan history with date picker and sorting, an "All clear" view, light/dark and more-contrast colour schemes (WCAG 2.2 AA).
- **Scheduled scans**: daily, weekly or monthly at chosen hours, in site time. **Retention** by days or by the newest number of scans, with a weekly cleanup.
- **WP-CLI**: `scan` (with `--fail-on` and `--porcelain` for pipelines) and `purge-logs`.
- **Abilities** (WordPress 7.1+): `run-scan`, `get-scan-results`, `get-next-scan` and `flush-logs` (only files a request with `confirm: true`; an administrator approves it on the dashboard, see `includes/class-flush-requests.php`), for administrators, public for AI clients via the MCP adapter, not on REST, with a GDPR guard.
- **Presence and live updates**: other administrators on the dashboard are shown; any change (scan, fix, migration, deletion, settings, flush) refreshes every open dashboard within ~15 s, via the WordPress Heartbeat API (its `wp.hooks` actions).
- **Multisite**: per-site dashboards plus a network overview.

## Development

Requirements: PHP 8.1+, WordPress 6.9+ (abilities: 7.1+), Node 24 (`.nvmrc`), Composer.

```bash
nvm use
npm ci
npm run build        # dist/, built by Vite (not committed)
npm run watch        # rebuild on change
npm run lint:css     # stylelint: logical properties only

composer install
vendor/bin/phpcs     # WordPress Coding Standards + PHPCompatibility
vendor/bin/phpstan analyse --memory-limit=1G
php tests/test-analysis.php   # analysis, regex, security and schedule tests
```

Dummy data for all ten supported plugins (local or development environments only), with exact, wildcard and regex rules and loops and chains across plugins. Inactive plugins are seeded in their own storage format, so they show up as leftovers:

```bash
wp eval-file bin/seed-dummy-data.php          # seed
wp eval-file bin/seed-dummy-data.php remove   # remove
wp redirect-inspector scan                    # expected counts (all ten active): bin/seed-expected.json
```

The WordPress.org live preview (`.wordpress-org/blueprints/blueprint.json`) installs all ten plugins and runs the same seed. Rebuild it after changing the seed:

```bash
php bin/build-blueprint.php
```

## Structure

| Path | What |
|---|---|
| `includes/sources/` | One adapter per plugin: read (`Source`), write for fixes and migration (`Writable`), delete leftovers (`Purgeable`) |
| `includes/analysis/` | Matcher (exact, prefix, suffix, contains, regex) and the graph analysis |
| `includes/` | Scanner, fixer, migrator, scheduler, retention, locks, REST API, WP-CLI, abilities |
| `assets/scripts/` | React + TypeScript dashboard (`@wordpress/*` from WordPress itself) |
| `assets/styles/` | SCSS: logical properties, `lh` spacing, HSL colours; colour schemes in `base/_theme.scss` |
| `.wordpress-org/` | Banners, icons, screenshots and the Playground blueprint |

## Security

Every action requires `manage_options` and runs through REST with the core nonce. Rule strings from other plugins are treated as untrusted: rendered as text only, regexes guarded, only plain URLs or paths written into other plugins' storage, leftover data deletable only while its plugin is inactive, one write at a time. The live checker only requests the site's own host.

## Releasing

1. Update the version in `redirect-inspector.php` (header and constant), `package.json` and the `Stable tag` and changelog in `readme.txt`.
2. Tag the release (`git tag 1.0.1 && git push --tags`). The **Deploy to WordPress.org** workflow builds `dist/` and pushes to SVN.

Changes to only `readme.txt` or `.wordpress-org/` on `main` are pushed to WordPress.org by the **Plugin asset/readme update** workflow. Both need the repository secrets `SVN_USERNAME` and `SVN_PASSWORD`.

## License

GPL-2.0-or-later. Wapuu, the WordPress mascot, is by Kazuko Kaneuchi and released under GPLv2 or later; the Wapuu with a magnifying glass is drawn for this plugin.
