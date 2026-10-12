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

## Read-only JSON API

With the Events site set enabled, configure `events.storagePid` to the folder
whose events should be publicly readable. The API uses this site default,
independently of individual content elements' Starting point selections.
A zero or unset folder exposes no events. No authentication is required.

Request an event by its database UID:

```sh
curl https://your-site.example/api/events/11
```

Example response (HTTP 200):

```json
{"data":{"id":11,"title":"API workshop","description":"First line","eventDate":"2026-11-12T18:00:00+00:00","location":"Lisbon"}}
```

Dates use ISO 8601 in UTC and can be null for records without a date.
Missing, deleted, and out-of-folder events return HTTP 404 with
`{"error":{"code":"event_not_found","message":"Event not found."}}`.
Malformed IDs return 400. GET and HEAD are supported; other methods return 405.
Responses use `Cache-Control: no-store`. The endpoint also works below a site's
base path or language prefix. Event records currently have no translated variants.

`Configuration/RequestMiddlewares.php` registers the PSR-15 middleware.
`Classes/Middleware/EventApiMiddleware.php` matches the URL and delegates to
`Classes/Controller/EventApiController.php`, which builds the JSON response.
`Classes/Domain/Repository/EventApiRepository.php` queries the permitted folder
using parameterized database queries. This endpoint needs no Fluid template.
Flush TYPO3 caches after installing the new middleware.

See TYPO3's [middleware documentation](https://docs.typo3.org/m/typo3/reference-coreapi/14.3/en-us/ApiOverview/RequestLifeCycle/Middlewares.html).

### How this follows TYPO3 conventions

Follow a request to `GET /api/events/11` through these steps:

1. TYPO3 resolves the site and language. Our middleware runs after site resolution,
   maintenance handling and base redirects, but before page resolution. These
   ordering constraints belong in `Configuration/RequestMiddlewares.php`.
2. `EventApiMiddleware::process()` checks that the site's Events settings exist
   and matches the path relative to the site/language base. An unrelated URL is
   passed to the next TYPO3 handler, preserving normal page rendering.
3. The middleware calls `EventApiController::showAction()` with the PSR-7 request,
   the raw ID from the URL, and the site's storage folder ID.
4. The controller checks the method and ID, asks the repository for the event,
   and selects the HTTP status and JSON response. For HEAD it removes the body.
5. `EventApiRepository::findInStorageFolder()` uses TYPO3's database API to query
   by UID, folder and deletion status and returns the documented public fields.

The API controller is an ordinary PHP service, not an Extbase `ActionController`.
The method name `showAction` is descriptive: our middleware explicitly calls it.
There is no automatic Extbase action dispatch, Fluid template, or route enhancer
for this endpoint. The existing HTML plugin still uses Extbase and its own route
enhancer. TYPO3's middleware guidance warns against using Extbase before its
frontend TypoScript context exists; this API uses the database API directly.

`Configuration/Services.yaml` enables constructor autowiring. TYPO3 creates and
injects the controller, repository and `ConnectionPool`. The PSR-7 request is
passed per request; it is not a constructor dependency. Responses use TYPO3's
`JsonResponse`, which implements the PSR-7 response contract.

### SOLID and complexity

The responsibility split is a design choice for this extension; TYPO3 does not
require this exact three-class layout.

| Principle | Application and limits here |
| --- | --- |
| Single responsibility | Middleware handles site/path dispatch; controller handles the HTTP endpoint; repository handles the database read and public result mapping. |
| Open/closed | Responsibilities can evolve separately, but adding an endpoint may still require changing dispatch. We have not introduced a generic routing framework. |
| Liskov substitution | The middleware honors its PSR-15 contract by returning a response or delegating. Our application classes do not use inheritance. |
| Interface segregation | Each application service exposes a small operation; there is no broad CRUD interface. |
| Dependency inversion | Dependencies are constructor-injected, but controller and repository dependencies are concrete classes. This is DI, not strict dependency inversion. A repository interface would be useful if multiple storage implementations or an explicit domain boundary were needed. |

SOLID is design guidance, not something PHPCS or PHPStan can certify. Keeping the
validation, status codes and HEAD handling in the HTTP controller makes those
rules visible without adding a class for each condition. The repository currently
returns an API read model (an array of public fields); a separate mapper could
be justified if other consumers require different representations.

`Tests/Functional/EventApiTest.php` exercises the real TYPO3 request stack and
database, including delegation to ordinary pages. Run `composer check` to verify
behavior, formatting and static analysis after changing this flow.

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
`#[Test]` methods in `Tests/Functional/`. The test classes extend
TYPO3's `FunctionalTestCase`, which creates an isolated TYPO3 installation and
SQLite database and resets the database between tests.

The fixture `Tests/Functional/Fixtures/pages.csv` provides the pages, content
plugin, and backend test user. Events and editor changes are processed through
TYPO3 DataHandler. Frontend requests use the framework's
`executeFrontendSubRequest()` method, exercising TYPO3 routing, controllers,
Fluid templates, and response generation without a separate web server.

The plugin tests cover list/detail rendering, date ordering,
escaping, readable URLs, duplicate slugs, Starting point/default-folder behavior,
404 responses, and cache invalidation. Page caching is explicitly enabled, and
cache tests assert that it is populated before testing invalidation.

API tests cover JSON fields, folder boundaries, deleted records, invalid IDs,
HTTP methods, site base paths, site-set activation, fresh data after edits, and
continued rendering of normal pages. Together the suites run 32 test cases.

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
