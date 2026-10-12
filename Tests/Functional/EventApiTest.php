<?php

declare(strict_types=1);

namespace Study\Events\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class EventApiTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['fluid_styled_content'];
    protected array $testExtensionsToLoad = ['study/events'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/api-events.csv');
        $this->configureSite();
        $this->setUpFrontendRootPage(1, ['EXT:events/Tests/Functional/Fixtures/setup.typoscript']);
        $this->getConnectionPool()->getConnectionForTable('sys_template')
            ->update('sys_template', ['clear' => 0], ['pid' => 1]);
    }

    #[Test]
    public function getReturnsOnlyPublicFieldsAsJson(): void
    {
        $response = $this->request('/api/events/11');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['data' => [
            'id' => 11, 'title' => 'API workshop', 'description' => 'First line',
            'eventDate' => '2026-11-12T18:00:00+00:00', 'location' => 'Lisbon',
        ]], json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{int}> */
    public static function unavailableEvents(): iterable
    {
        yield 'unknown ID' => [2147483647];
        yield 'outside API storage folder' => [12];
        yield 'soft-deleted event' => [13];
    }

    #[Test]
    #[DataProvider('unavailableEvents')]
    public function unavailableEventReturnsJson404(int $id): void
    {
        $response = $this->request('/api/events/' . $id);
        $this->assertJsonError($response, 404, 'event_not_found');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'missing' => [''];
        yield 'zero' => ['/0'];
        yield 'negative' => ['/-1'];
        yield 'text' => ['/abc'];
        yield 'decimal' => ['/1.5'];
        yield 'too large' => ['/2147483648'];
        yield 'SQL text' => ['/1%20OR%201=1'];
    }

    #[Test]
    #[DataProvider('invalidIds')]
    public function malformedIdReturnsJson400(string $suffix): void
    {
        $this->assertJsonError($this->request('/api/events' . $suffix), 400, 'invalid_event_id');
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedMethods(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
        yield 'DELETE' => ['DELETE'];
    }

    #[Test]
    #[DataProvider('unsupportedMethods')]
    public function writeMethodsAreRejected(string $method): void
    {
        $response = $this->request('/api/events/11', $method);
        $this->assertJsonError($response, 405, 'method_not_allowed');
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
        self::assertSame(200, $this->request('/api/events/11')->getStatusCode());
    }

    #[Test]
    public function headReturnsHeadersWithoutBody(): void
    {
        $response = $this->request('/api/events/11', 'HEAD');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('', (string)$response->getBody());
        $missing = $this->request('/api/events/999', 'HEAD');
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('', (string)$missing->getBody());
    }

    #[Test]
    public function unsetStorageFolderDoesNotExposeAllRecords(): void
    {
        $this->configureSite(storagePid: 0);
        $this->assertJsonError($this->request('/api/events/11'), 404, 'event_not_found');
    }

    #[Test]
    public function endpointWorksBelowSiteBasePath(): void
    {
        $this->configureSite(basePath: '/example/');
        self::assertSame(200, $this->request('/example/api/events/11')->getStatusCode());
    }

    #[Test]
    public function siteWithoutEventsSetDoesNotActivateApi(): void
    {
        $this->configureSite(enableEvents: false);
        $response = $this->request('/api/events/11');
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function ordinaryFrontendPageStillRendersHtml(): void
    {
        $response = $this->request('/events');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Other folder event', (string)$response->getBody());
    }

    #[Test]
    public function changesAreVisibleOnNextRequest(): void
    {
        self::assertSame(200, $this->request('/api/events/11')->getStatusCode());
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_events_domain_model_event');
        $connection->update('tx_events_domain_model_event', ['title' => 'Updated title'], ['uid' => 11]);
        $response = $this->request('/api/events/11');
        self::assertSame('Updated title', json_decode((string)$response->getBody(), true)['data']['title']);
        $connection->update('tx_events_domain_model_event', ['deleted' => 1], ['uid' => 11]);
        $this->assertJsonError($this->request('/api/events/11'), 404, 'event_not_found');
    }

    private function configureSite(int $storagePid = 5, string $basePath = '/', bool $enableEvents = true): void
    {
        $sitePath = Environment::getConfigPath() . '/sites/events';
        GeneralUtility::mkdir_deep($sitePath);
        file_put_contents($sitePath . '/config.yaml', Yaml::dump([
            'rootPageId' => 1,
            'base' => 'https://events.example.test' . $basePath,
            'dependencies' => $enableEvents ? ['typo3/fluid-styled-content', 'study/events']
                : ['typo3/fluid-styled-content'],
            'languages' => [[
                'title' => 'English', 'enabled' => true, 'languageId' => 0,
                'base' => '/', 'locale' => 'en-US', 'hreflang' => 'en-US',
                'navigationTitle' => 'English', 'flag' => 'global',
            ]],
        ], 5));
        file_put_contents($sitePath . '/settings.yaml', Yaml::dump(
            $enableEvents ? ['events.storagePid' => $storagePid] : [],
        ));
        // Site settings are cached independently of changes to settings.yaml.
        GeneralUtility::makeInstance(CacheManager::class)->getCache('core')->flush();
    }

    private function request(string $path, string $method = 'GET'): ResponseInterface
    {
        return $this->executeFrontendSubRequest(
            (new InternalRequest('https://events.example.test' . $path))->withMethod($method),
        );
    }

    private function assertJsonError(ResponseInterface $response, int $status, string $code): void
    {
        self::assertSame($status, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($code, $payload['error']['code']);
    }
}
