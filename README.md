# Events for TYPO3

A learning extension for TYPO3 14.3, providing event records, a frontend list,
and event detail pages with readable URLs. Requires PHP 8.2 or later.

## Install in a TYPO3 project

Install the `study/events` Composer package from your configured repository.
Enable the **Events** site set (`study/events`) in the site's configuration,
then run `vendor/bin/typo3 extension:setup` and `vendor/bin/typo3 cache:flush`.

Create a folder for event records. In **Content > Records**, select that folder
and create an **Event**. Add the **Events: List** content element to a page.
Select the records folder in **Starting point**, or configure the site's
`events.storagePid` setting as the default folder.

The site must render content elements (for example using Fluid Styled Content).
No dependency on the Camino theme is required. The extension's site set provides
the routing configuration in `Configuration/Sets/Events/route-enhancers.yaml`.

## Develop and check

This directory is the repository root. From a standalone checkout, run:

```sh
composer install
composer check
```

In our existing DDEV learning project, run from the TYPO3 project root:

```sh
ddev exec composer --working-dir=packages/events install
ddev exec composer --working-dir=packages/events check
```

Development dependencies are intentionally installed in this extension's
`vendor/` directory. A TYPO3 project that requires the extension does not inherit
its `require-dev` dependencies or Composer scripts.

| Command | Purpose |
| --- | --- |
| `composer phpcs` | PSR-12 checks, with TYPO3-compatible brace rules |
| `composer cs:check` | TYPO3 PHP-CS-Fixer rules without changing files |
| `composer cs:fix` | Apply TYPO3 formatting |
| `composer phpstan` | Level 8 static analysis, including test scripts |
| `composer test` | 28 HTTP smoke checks against a disposable TYPO3 site |
| `composer check` | Run all the checks above, without applying fixes |

The PHP CLI needs TYPO3's required extensions, plus PDO SQLite. Composer must
be available on `PATH`; the first test run downloads the test site's dependencies.
The test runner creates a minimal TYPO3 installation in `.build/site`, initializes
its own SQLite database, and starts a temporary HTTP server on a free loopback
port. It stops the server even if a test fails. Server output is in
`.build/server.log`.

Tests cover list/detail rendering, date sorting, escaping, readable URLs,
duplicate slugs, Starting point/default-folder behavior, 404 responses, and cache
invalidation. Fixtures are created and soft-deleted through TYPO3 DataHandler.
The test site is reused between runs; `.build/` can be removed to start fresh.
Your normal site's database and records are not used.

The test harness uses `Tests/Fixtures/composer.json` to install this extension as
a path dependency. This verifies integration in an actual TYPO3 project without
requiring the parent learning project.

## GitHub Actions

Publish the **contents of this directory** as the repository root, so GitHub sees
`.github/workflows/ci.yml`. The workflow runs on pushes, pull requests, and manual
dispatch, on PHP 8.2 and 8.4. It validates Composer metadata and runs
`composer check`. It needs no site credentials or database service.

Generated dependencies, Composer locks, caches, test databases and local
configuration are ignored. CI resolves dependencies from the supported version
ranges on each fresh run.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
