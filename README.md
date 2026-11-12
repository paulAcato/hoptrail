# Redirect Inspector

Finds redirect loops, chains and redirects you don't need across every redirect plugin on a WordPress site at once (Redirection, Yoast SEO Premium, Rank Math, Safe Redirect Manager and Simple 301 Redirects), and fixes the clear-cut ones.

![Banner](.wordpress-org/banner-1544x500.png)

The user-facing description is in [readme.txt](readme.txt), which is also what WordPress.org shows.

## Development

Requirements: PHP 8.1+, Node 24 (`.nvmrc`), Composer.

```bash
nvm use
npm ci
npm run build        # dist/, built by Vite (not committed)
npm run watch        # rebuild on change
npm run lint:css     # stylelint, logical properties only

composer install
vendor/bin/phpcs     # WordPress Coding Standards + PHPCompatibility
vendor/bin/phpstan analyse
php tests/test-analysis.php
```

Dummy data for every supported redirect plugin (local or development environments only):

```bash
wp eval-file bin/seed-dummy-data.php          # seed
wp eval-file bin/seed-dummy-data.php remove   # remove
wp redirect-inspector scan                    # expected counts: bin/seed-expected.json
```

## Structure

| Path | What |
|---|---|
| `includes/sources/` | One read (and write) adapter per redirect plugin; add more through the `redirect_inspector_sources` filter |
| `includes/analysis/` | Matcher (exact, prefix, regex, …) and the graph analysis |
| `includes/` | Scanner, fixer, migrator, scheduler, retention, REST API, WP-CLI |
| `assets/scripts/` | React + TypeScript dashboard (`@wordpress/*` from WordPress itself) |
| `assets/styles/` | SCSS: logical properties only, colour schemes in `base/_theme.scss` |
| `.wordpress-org/` | Banners, icons, screenshots and the Playground blueprint |

## Releasing

1. Update the version in `redirect-inspector.php` (header and constant), `package.json` and the `Stable tag` and changelog in `readme.txt`.
2. Tag the release (`git tag 1.0.1 && git push --tags`). The **Deploy to WordPress.org** workflow builds `dist/` and pushes to SVN.

Changes to only `readme.txt` or `.wordpress-org/` on `main` are pushed to WordPress.org by the **Plugin asset/readme update** workflow.

Both need the repository secrets `SVN_USERNAME` and `SVN_PASSWORD`.

## License

GPL-2.0-or-later. Wapuu, the WordPress mascot, is by Kazuko Kaneuchi and released under GPLv2 or later; the Wapuu with a magnifying glass is drawn for this plugin.
