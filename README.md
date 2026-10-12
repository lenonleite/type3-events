# Events for TYPO3

Event records, a frontend list, detail pages with readable URLs, and a read-only
JSON API. Requires **TYPO3 14.3** and **PHP 8.2+**.

## Setup

1. Install the `study/events` Composer package from your configured repository.
2. Enable the **Events** site set (`study/events`).
3. Run `vendor/bin/typo3 extension:setup` and `vendor/bin/typo3 cache:flush`.
4. Create a records folder and add events through **Content > Records**.
5. Add **Events: List** to a page. Select the folder in **Starting point**,
   or set the site default with `events.storagePid`.

Your site must render content elements, for example with Fluid Styled Content.
The extension does not require the Camino theme.

## API

```sh
curl https://your-site.example/api/events/1
```

Returns `data` containing `id`, `title`, `description`, `eventDate` (ISO 8601 UTC
or null), and `location`.

The API is public and reads only the site's `events.storagePid` folder, independent
of each HTML plugin's Starting point. An unset or zero folder exposes no events.

- **200:** event found; **404:** missing, deleted, or outside the configured folder.
- **400:** invalid ID; **405:** unsupported method. GET and HEAD are supported.
- Responses use `Cache-Control: no-store`.

The HTML plugin uses Extbase. The API uses a PSR-15 middleware, a small HTTP
controller, and a database repository. See [TYPO3 middleware guidance](https://docs.typo3.org/m/typo3/reference-coreapi/14.3/en-us/ApiOverview/RequestLifeCycle/Middlewares.html).

## Development

From the extension repository root:

```sh
composer install
composer check
```

From our DDEV project root:

```sh
ddev exec composer --working-dir=packages/events install
ddev exec composer --working-dir=packages/events check
```

`composer check` runs **PHPCS, PHP-CS-Fixer, PHPStan, and PHPUnit**.
Run them separately with `composer phpcs`, `composer cs:check`,
`composer phpstan`, or `composer test`. Use `composer cs:fix` to apply formatting.

The functional tests use TYPO3's Testing Framework and an isolated SQLite database;
they do not use your site's records. PHP needs PDO SQLite for testing.
Testing Framework 10 currently uses an explicit `@dev` dependency for TYPO3 14.

[GitHub Actions](https://github.com/lenonleite/type3-events/actions) runs quality
checks and tests on **PHP 8.2 and 8.4** for pushes and pull requests.

## License

[GPL-2.0-or-later](LICENSE).
