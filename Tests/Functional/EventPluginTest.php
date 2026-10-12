<?php

declare(strict_types=1);

namespace Study\Events\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Page\CacheHashCalculator;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class EventPluginTest extends FunctionalTestCase
{
    private const TABLE = 'tx_events_domain_model_event';
    private const BASE_URL = 'https://events.example.test';

    protected array $coreExtensionsToLoad = ['fluid_styled_content'];
    protected array $testExtensionsToLoad = ['study/events'];

    // The framework normally disables page caching. These tests also verify invalidation.
    protected array $configurationToUseInTestInstance = [
        'SYS' => ['caching' => ['cacheConfigurations' => [
            'pages' => ['backend' => Typo3DatabaseBackend::class],
        ]]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $sitePath = Environment::getConfigPath() . '/sites/events';
        GeneralUtility::mkdir_deep($sitePath);
        file_put_contents($sitePath . '/config.yaml', Yaml::dump([
            'rootPageId' => 1,
            'base' => self::BASE_URL . '/',
            'dependencies' => ['typo3/fluid-styled-content', 'study/events'],
            'languages' => [[
                'title' => 'English', 'enabled' => true, 'languageId' => 0,
                'base' => '/', 'locale' => 'en-US', 'hreflang' => 'en-US',
                'navigationTitle' => 'English', 'flag' => 'global',
            ]],
        ], 5));
        file_put_contents($sitePath . '/settings.yaml', Yaml::dump(['events.storagePid' => 5]));
        $this->setUpFrontendRootPage(1, ['EXT:events/Tests/Functional/Fixtures/setup.typoscript']);
        // Preserve the TypoScript supplied by the enabled site sets.
        $this->getConnectionPool()->getConnectionForTable('sys_template')
            ->update('sys_template', ['clear' => 0], ['pid' => 1]);
    }

    #[Test]
    public function emptyListDisplaysHelpfulMessage(): void
    {
        $response = $this->request('/events');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('No events have been added yet.', (string)$response->getBody());
    }

    #[Test]
    public function listOrdersEventsByDateAndRespectsStartingPoint(): void
    {
        $this->createEvent(['title' => 'Later workshop', 'event_date' => 1794592800]);
        $this->createEvent(['title' => 'Earlier workshop']);
        $this->createEvent(['pid' => 4, 'title' => 'Other folder workshop']);
        $this->createEvent(['pid' => 5, 'title' => 'Default folder workshop']);

        $response = $this->request('/events');
        self::assertSame(200, $response->getStatusCode());
        $html = (string)$response->getBody();
        $links = $this->linksFrom($html);
        self::assertSame(['Earlier workshop', 'Later workshop'], array_keys($links));
        self::assertStringNotContainsString('Other folder workshop', $html);
        self::assertStringNotContainsString('Default folder workshop', $html);
    }

    #[Test]
    public function detailUsesReadableUrlEscapesTextAndLinksBackToList(): void
    {
        $title = 'Workshop <script>alert("test")</script>';
        $this->createEvent([
            'title' => $title,
            'description' => "First line <script>alert(1)</script>\nSecond line",
            'location' => 'Lisbon & Porto',
        ]);
        $link = $this->eventLink($title);
        self::assertStringStartsWith('/events/', (string)parse_url($link, PHP_URL_PATH));
        self::assertNull(parse_url($link, PHP_URL_QUERY));

        $response = $this->request($link);
        self::assertSame(200, $response->getStatusCode());
        $html = (string)$response->getBody();
        self::assertStringContainsString('class="event-detail"', $html);
        self::assertStringContainsString(htmlspecialchars($title, ENT_QUOTES), $html);
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('First line &lt;script&gt;', $html);
        self::assertMatchesRegularExpression('/<br\s*\/?>\s*Second line/', $html);
        self::assertStringContainsString('Lisbon &amp; Porto', $html);
        self::assertStringContainsString('<time datetime=', $html);

        $links = $this->linksFrom($html);
        self::assertArrayHasKey('Back to events', $links);
        $back = $links['Back to events'];
        self::assertSame('/events', rtrim((string)parse_url($back, PHP_URL_PATH), '/'));
        self::assertNull(parse_url($back, PHP_URL_QUERY));
        $list = $this->request($back);
        self::assertSame(200, $list->getStatusCode());
        self::assertStringContainsString('class="events-list"', (string)$list->getBody());
    }

    #[Test]
    public function duplicateTitlesGetDistinctSlugsAndResolveToCorrectRecord(): void
    {
        $first = $this->createEvent(['title' => 'Same title']);
        $second = $this->createEvent(['title' => 'Same title', 'description' => 'Second event description']);
        $firstSlug = $this->eventSlug($first);
        $secondSlug = $this->eventSlug($second);
        self::assertNotSame('', $firstSlug);
        self::assertNotSame('', $secondSlug);
        self::assertNotSame($firstSlug, $secondSlug);
        $response = $this->request('/events/' . $secondSlug);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Second event description', (string)$response->getBody());
    }

    #[Test]
    public function unknownSlugReturns404(): void
    {
        self::assertSame(404, $this->request('/events/unknown-event')->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidArguments(): iterable
    {
        yield 'missing argument' => ['missing'];
        yield 'unknown ID' => ['unknown'];
        yield 'outside selected folder' => ['outside'];
    }

    #[Test]
    #[DataProvider('invalidArguments')]
    public function invalidDetailArgumentReturns404(string $case): void
    {
        $params = ['id' => 2, 'tx_events_list' => ['action' => 'show', 'controller' => 'Event']];
        if ($case !== 'missing') {
            $params['tx_events_list']['event'] = $case === 'outside'
                ? $this->createEvent(['pid' => 4]) : 2147483647;
        }
        $params['cHash'] = GeneralUtility::makeInstance(CacheHashCalculator::class)
            ->generateForParameters(http_build_query($params));
        unset($params['id']);
        $response = $this->request('/events?' . http_build_query($params));
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('The requested event was not found.', (string)$response->getBody());
    }

    #[Test]
    public function emptyStartingPointUsesSiteDefaultForListAndDetail(): void
    {
        $this->createEvent(['title' => 'Selected workshop']);
        $this->createEvent(['pid' => 5, 'title' => 'Default workshop']);
        $this->saveRecords(['tt_content' => [1 => ['pages' => '']]]);
        $response = $this->request('/events');
        self::assertSame(200, $response->getStatusCode());
        $html = (string)$response->getBody();
        self::assertStringNotContainsString('Selected workshop', $html);
        $links = $this->linksFrom($html);
        self::assertArrayHasKey('Default workshop', $links);
        $detail = $this->request($links['Default workshop']);
        self::assertSame(200, $detail->getStatusCode());
        self::assertStringContainsString('Default workshop', (string)$detail->getBody());
    }

    #[Test]
    public function changingStartingPointInvalidatesDetailAccessAndCanBeRestored(): void
    {
        $this->createEvent();
        $link = $this->eventLink('Workshop');
        self::assertSame(200, $this->request($link)->getStatusCode());
        $this->saveRecords(['tt_content' => [1 => ['pages' => '']]]);
        self::assertSame(404, $this->request($link)->getStatusCode());
        $this->saveRecords(['tt_content' => [1 => ['pages' => '3']]]);
        self::assertSame(200, $this->request($link)->getStatusCode());
    }

    #[Test]
    public function addingEventInvalidatesCachedEmptyList(): void
    {
        self::assertStringContainsString(
            'No events have been added yet.',
            (string)$this->request('/events')->getBody(),
        );
        $this->assertPageWasCached();
        $this->createEvent();
        $response = $this->request('/events');
        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('Workshop', $this->linksFrom((string)$response->getBody()));
    }

    #[Test]
    public function deletingEventInvalidatesCachedDetailAndReturns404(): void
    {
        $uid = $this->createEvent();
        $link = $this->eventLink('Workshop');
        self::assertSame(200, $this->request($link)->getStatusCode());
        $this->assertPageWasCached();
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start([], [self::TABLE => [$uid => ['delete' => 1]]]);
        $handler->process_cmdmap();
        self::assertSame([], $handler->errorLog);
        $query = $this->getConnectionPool()->getQueryBuilderForTable(self::TABLE);
        $query->getRestrictions()->removeAll();
        $deleted = $query->select('deleted')->from(self::TABLE)
            ->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
            ->executeQuery()->fetchOne();
        self::assertSame(1, (int)$deleted);
        self::assertSame(404, $this->request($link)->getStatusCode());
    }

    private function assertPageWasCached(): void
    {
        $count = $this->getConnectionPool()->getConnectionForTable('cache_pages')
            ->count('*', 'cache_pages', []);
        self::assertGreaterThan(0, $count, 'The test must exercise a real populated page cache.');
    }

    private function request(string $uri): ResponseInterface
    {
        return $this->executeFrontendSubRequest(new InternalRequest(
            str_starts_with($uri, '/') ? self::BASE_URL . $uri : $uri,
        ));
    }

    /** @param array<string, int|string> $overrides */
    private function createEvent(array $overrides = []): int
    {
        $ids = $this->saveRecords([self::TABLE => ['NEW_event' => array_replace([
            'pid' => 3, 'title' => 'Workshop', 'description' => 'Event description',
            'event_date' => 1794506400, 'location' => 'Lisbon',
        ], $overrides)]]);
        return $ids['NEW_event'];
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $data
     * @return array<string, int>
     */
    private function saveRecords(array $data): array
    {
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start($data, []);
        $handler->process_datamap();
        self::assertSame([], $handler->errorLog);
        return $handler->substNEWwithIDs;
    }

    private function eventSlug(int $uid): string
    {
        $slug = $this->getConnectionPool()->getConnectionForTable(self::TABLE)
            ->select(['slug'], self::TABLE, ['uid' => $uid])->fetchOne();
        self::assertIsString($slug);
        return $slug;
    }

    private function eventLink(string $title): string
    {
        $response = $this->request('/events');
        self::assertSame(200, $response->getStatusCode());
        $links = $this->linksFrom((string)$response->getBody());
        self::assertArrayHasKey($title, $links);
        return $links[$title];
    }

    /** @return array<string, string> */
    private function linksFrom(string $html): array
    {
        $document = new \DOMDocument();
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
}
