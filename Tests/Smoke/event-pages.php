<?php

declare(strict_types=1);

// This smoke test uses temporary records in an isolated TYPO3 installation.
// TYPO3's DataHandler removes the test records in the finally block.
use GuzzleHttp\Client;
use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Core\BootService;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Page\CacheHashCalculator;

$loader = require getcwd() . '/vendor/autoload.php';
SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container = Bootstrap::init($loader, true)->get(BootService::class)->loadExtLocalconfDatabaseAndExtTables();
Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class);
Bootstrap::initializeBackendAuthentication();
$GLOBALS['LANG'] = $container->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
// Each mutation runs in a fresh TYPO3 request, like a backend editor save.
if (in_array($argv[1] ?? '', ['--save', '--delete'], true)) {
    $data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $handler = GeneralUtility::makeInstance(DataHandler::class);
    $handler->start($argv[1] === '--save' ? $data : [], $argv[1] === '--delete' ? $data : []);
    if ($argv[1] === '--save') {
        $handler->process_datamap();
    } else {
        $handler->process_cmdmap();
    }
    echo json_encode(['ids' => $handler->substNEWwithIDs, 'errors' => $handler->errorLog], JSON_THROW_ON_ERROR);
    exit;
}

/**
 * @param array<string, array<int|string, array<string, mixed>>> $data
 * @return array{ids: array<string, int>, errors: list<string>}
 */
function mutateRecords(string $mode, array $data): array
{
    $process = new Process([PHP_BINARY, __FILE__, $mode]);
    $process->setInput(json_encode($data, JSON_THROW_ON_ERROR));
    $process->mustRun();
    return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
}

$site = $container->get(SiteFinder::class)->getSiteByIdentifier('events-test');
$baseUrl = rtrim((string)$site->getBase(), '/');
$client = new Client(['base_uri' => $baseUrl, 'http_errors' => false]);
/** @var array<string, array<int, array{delete: int}>> $created */
$created = [];

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: ' . $message . "\n";
}

/**
 * @param array<string, array<int|string, array<string, mixed>>> $data
 * @return array<string, int>
 */
function saveRecords(array $data): array
{
    global $created;
    $result = mutateRecords('--save', $data);
    foreach ($result['ids'] as $key => $uid) {
        foreach ($data as $table => $records) {
            if (isset($records[$key])) {
                $created[$table][$uid] = ['delete' => 1];
            }
        }
    }
    if ($result['errors'] !== []) {
        throw new RuntimeException(implode("\n", $result['errors']));
    }
    return $result['ids'];
}

/** @return array<string, string> */
function linksFrom(string $html): array
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $links = [];
    foreach ($document->getElementsByTagName('a') as $link) {
        $links[trim($link->textContent)] = $link->getAttribute('href');
    }
    return $links;
}

try {
    $slug = '/events-smoke-' . bin2hex(random_bytes(6));
    $pages = saveRecords(['pages' => [
        'NEW_test_page' => [
            'pid' => $site->getRootPageId(), 'title' => 'Events smoke test',
            'slug' => $slug, 'doktype' => 1, 'hidden' => 0, 'nav_hide' => 1,
        ],
        'NEW_test_folder' => [
            'pid' => $site->getRootPageId(), 'title' => 'Events smoke records', 'doktype' => 254, 'hidden' => 0,
        ],
        'NEW_other_folder' => [
            'pid' => $site->getRootPageId(), 'title' => 'Events smoke other records', 'doktype' => 254, 'hidden' => 0,
        ],
    ]]);
    $pageId = $pages['NEW_test_page'];
    $folderId = $pages['NEW_test_folder'];
    $plugins = saveRecords(['tt_content' => ['NEW_plugin' => [
        'pid' => $pageId, 'CType' => 'events_list', 'header' => 'Events',
        'colPos' => 0, 'hidden' => 0, 'pages' => (string)$folderId,
    ]]]);

    $empty = $client->get($slug);
    check(
        $empty->getStatusCode() === 200
        && str_contains((string)$empty->getBody(), 'No events have been added yet.'),
        'Empty list',
    );

    $title = 'Workshop <script>alert("test")</script>';
    $description = "First line <script>alert(1)</script>\nSecond line";
    $fixture = ['pid' => $folderId, 'title' => $title, 'description' => $description,
        'event_date' => 1794506400, 'location' => 'Lisbon & Porto'];
    $defaultFolderId = (int)$site->getSettings()->get('events.storagePid');
    check($defaultFolderId > 0, 'Site defines a default event records folder');
    $defaultTitle = 'Default folder workshop ' . bin2hex(random_bytes(6));
    $events = saveRecords(['tx_events_domain_model_event' => [
        'NEW_later' => array_replace($fixture, ['title' => 'Later workshop', 'event_date' => 1794592800]),
        'NEW_event' => $fixture,
        'NEW_outside' => array_replace($fixture, [
            'pid' => $pages['NEW_other_folder'], 'title' => 'Outside selected folder',
        ]),
        'NEW_default' => array_replace($fixture, ['pid' => $defaultFolderId, 'title' => $defaultTitle]),
    ]]);

    $list = $client->get($slug);
    $html = (string)$list->getBody();
    $links = linksFrom($html);
    check(
        $list->getStatusCode() === 200
        && isset($links[$title]),
        'List refreshes after record creation and links to the event',
    );
    check(strpos($html, 'Workshop &lt;script&gt;') < strpos($html, 'Later workshop'), 'Events are sorted by date');
    check(!str_contains($html, 'Outside selected folder'), 'List respects Starting point');
    check(!str_contains($html, $defaultTitle), 'Explicit Starting point overrides the site default');
    $detailPath = parse_url($links[$title], PHP_URL_PATH);
    check(
        is_string($detailPath)
        && str_starts_with($detailPath, $slug . '/')
        && $detailPath !== $slug . '/',
        'Detail link uses a readable URL segment',
    );
    check(parse_url($links[$title], PHP_URL_QUERY) === null, 'Detail link needs no action query parameters or cHash');
    $unknownSlug = $client->get($slug . '/unknown-event-' . bin2hex(random_bytes(6)));
    check($unknownSlug->getStatusCode() === 404, 'Unknown event slug returns 404');

    $detail = $client->get($links[$title]);
    $detailHtml = (string)$detail->getBody();
    check(
        $detail->getStatusCode() === 200
        && str_contains($detailHtml, 'class="event-detail"'),
        'Generated link displays the detail view',
    );
    check(
        str_contains($detailHtml, htmlspecialchars($title, ENT_QUOTES))
        && !str_contains($detailHtml, '<script>alert'),
        'Detail safely escapes event text',
    );
    check(
        str_contains($detailHtml, 'First line &lt;script&gt;')
        && str_contains($detailHtml, '<br')
        && str_contains($detailHtml, 'Second line'),
        'Description preserves line breaks',
    );
    check(
        str_contains($detailHtml, 'Lisbon &amp; Porto')
        && str_contains($detailHtml, '<time datetime='),
        'Detail displays location and date',
    );
    $back = linksFrom($detailHtml)['Back to events'] ?? '';
    check($back !== '', 'Detail provides a back link');
    $backResponse = $client->get($back);
    check(
        $backResponse->getStatusCode() === 200
        && str_contains((string)$backResponse->getBody(), 'class="events-list"'),
        'Back link returns to list',
    );
    check(
        rtrim((string)parse_url($back, PHP_URL_PATH), '/') === $slug
        && parse_url($back, PHP_URL_QUERY) === null,
        'Back link uses the clean list URL',
    );

    $duplicates = saveRecords(['tx_events_domain_model_event' => [
        'NEW_duplicate' => array_replace($fixture, ['description' => 'Second record with the same title']),
    ]]);
    $connection = $container->get(\TYPO3\CMS\Core\Database\ConnectionPool::class)
        ->getConnectionForTable('tx_events_domain_model_event');
    $originalSlug = $connection
        ->select(['slug'], 'tx_events_domain_model_event', ['uid' => $events['NEW_event']])->fetchOne();
    $duplicateSlug = $connection
        ->select(['slug'], 'tx_events_domain_model_event', ['uid' => $duplicates['NEW_duplicate']])->fetchOne();
    check(
        is_string($duplicateSlug)
        && $duplicateSlug !== ''
        && $duplicateSlug !== $originalSlug,
        'Identical event titles get distinct slugs',
    );
    $duplicateDetail = $client->get($slug . '/' . $duplicateSlug);
    check(
        $duplicateDetail->getStatusCode() === 200
        && str_contains((string)$duplicateDetail->getBody(), 'Second record with the same title'),
        'Duplicate title URL resolves to the correct event',
    );

    $calculator = GeneralUtility::makeInstance(CacheHashCalculator::class);
    $invalidArguments = [
        'missing argument' => null, 'unknown ID' => 2147483647, 'outside folder' => $events['NEW_outside'],
    ];
    foreach ($invalidArguments as $case => $uid) {
        $params = ['id' => $pageId, 'tx_events_list' => ['action' => 'show', 'controller' => 'Event']];
        if ($uid !== null) {
            $params['tx_events_list']['event'] = $uid;
        }
        $params['cHash'] = $calculator->generateForParameters(http_build_query($params));
        unset($params['id']);
        $response = $client->get($slug . '?' . http_build_query($params));
        check(
            $response->getStatusCode() === 404
            && str_contains((string)$response->getBody(), 'The requested event was not found.'),
            '404 for ' . $case,
        );
    }

    saveRecords(['tt_content' => [$plugins['NEW_plugin'] => ['pages' => '']]]);
    $defaultList = $client->get($slug);
    $defaultHtml = (string)$defaultList->getBody();
    $defaultLinks = linksFrom($defaultHtml);
    check(
        $defaultList->getStatusCode() === 200
        && isset($defaultLinks[$defaultTitle]),
        'Empty Starting point reads the site default folder',
    );
    check(
        !str_contains($defaultHtml, 'Later workshop'),
        'Default folder does not include records from the previous override',
    );
    $defaultDetail = $client->get($defaultLinks[$defaultTitle]);
    check(
        $defaultDetail->getStatusCode() === 200
        && str_contains((string)$defaultDetail->getBody(), $defaultTitle),
        'Detail view also uses the site default folder',
    );
    $oldDetail = $client->get($links[$title]);
    check($oldDetail->getStatusCode() === 404, 'Changing the Starting point invalidates old detail access');

    saveRecords(['tt_content' => [$plugins['NEW_plugin'] => ['pages' => (string)$folderId]]]);
    $restoredDetail = $client->get($links[$title]);
    check($restoredDetail->getStatusCode() === 200, 'Explicit Starting point can be restored');
    $result = mutateRecords('--delete', ['tx_events_domain_model_event' => [$events['NEW_event'] => ['delete' => 1]]]);
    check($result['errors'] === [], 'Test event deleted through TYPO3');
    $deletedResponse = $client->get($links[$title]);
    check($deletedResponse->getStatusCode() === 404, 'Deleted event invalidates cached details and returns 404');
    unset($created['tx_events_domain_model_event'][$events['NEW_event']]);
} finally {
    // Remove children before the pages that contain them.
    $result = mutateRecords('--delete', array_reverse($created, true));
    if ($result['errors'] !== []) {
        throw new RuntimeException('Fixture cleanup failed: ' . implode("\n", $result['errors']));
    }
    echo "Test records removed using TYPO3 deletion.\n";
}
