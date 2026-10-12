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
| `composer test` / `composer test:functional` | PHPUnit functional tests with TYPO3’s Testing Framework |
| `composer check` | Run all the checks above, without applying fixes |

The PHP CLI needs TYPO3's required extensions, plus PDO SQLite. After
`composer install`, running tests requires no additional downloads or HTTP server.

### How the tests work

`composer test` calls PHPUnit using `phpunit.xml.dist`. PHPUnit discovers the
`#[Test]` methods in `Tests/Functional/EventPluginTest.php`. That class extends
TYPO3's `FunctionalTestCase`, which creates an isolated TYPO3 installation and
SQLite database and resets the database between tests.

The fixture `Tests/Functional/Fixtures/pages.csv` provides the pages, content
plugin, and backend test user. Events and editor changes are processed through
TYPO3 DataHandler. Frontend requests use the framework's
`executeFrontendSubRequest()` method, exercising TYPO3 routing, controllers,
Fluid templates, and response generation without a separate web server.

The suite preserves the former smoke checks as 12 named test cases (including
three data-provider cases). It covers list/detail rendering, date ordering,
escaping, readable URLs, duplicate slugs, Starting point/default-folder behavior,
404 responses, and cache invalidation. Page caching is explicitly enabled, and
cache tests assert that it is populated before testing invalidation.

Generated test installations live under `public/typo3temp/var/tests/`, which Git
ignores. Your normal site's database and records are not used.

Run just one scenario from the extension root:

```sh
composer test:functional -- --filter emptyListDisplaysHelpfulMessage
```

We use PHPUnit 11 to support PHP 8.2 and TYPO3 Testing Framework 10 for TYPO3 14.
Framework 10 currently requires the explicit `@dev` stability flag. This exception
applies only to that development dependency; the project's global minimum
stability remains stable.

These are functional tests because the current behavior depends on TYPO3 and the
database. Add unit tests when introducing logic that can usefully be tested in
isolation; trivial getter tests are not needed to fill a separate suite.

See TYPO3's official [extension testing guidance](https://docs.typo3.org/m/typo3/reference-coreapi/14.3/en-us/Testing/ExtensionTesting.html)
and [functional testing documentation](https://docs.typo3.org/m/typo3/reference-coreapi/14.3/en-us/Testing/FunctionalTesting/Index.html).

## GitHub Actions

Publish the **contents of this directory** as the repository root, so GitHub sees
`.github/workflows/ci.yml`. The workflow runs on pushes, pull requests, and manual
dispatch, on PHP 8.2 and 8.4. It validates Composer metadata and shows separate steps for
PHPCS, PHP-CS-Fixer, PHPStan, and PHPUnit. It needs no site credentials or database service.

Generated dependencies, Composer locks, caches, test databases and local
configuration are ignored. CI resolves dependencies from the supported version
ranges on each fresh run.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
