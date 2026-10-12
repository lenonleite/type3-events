<?php

declare(strict_types=1);

namespace Study\Events\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Study\Events\Domain\Repository\EventApiRepository;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\Site;

final readonly class EventApiMiddleware implements MiddlewareInterface
{
    public function __construct(private EventApiRepository $eventRepository) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $site = $request->getAttribute('site');
        $routing = $request->getAttribute('routing');
        if (
            !$site instanceof Site || !$routing instanceof SiteRouteResult
            || !$site->getSettings()->has('events.storagePid')
        ) {
            return $handler->handle($request);
        }

        // The tail excludes the site's base path and language prefix.
        $path = '/' . ltrim($routing->getTail(), '/');
        if (!preg_match('#^/api/events(?:/([^/]+))?/?$#D', $path, $matches)) {
            return $handler->handle($request);
        }

        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $this->error('method_not_allowed', 'Use GET or HEAD.', 405)
                ->withHeader('Allow', 'GET, HEAD');
        }

        $id = $matches[1] ?? '';
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id) || (int)$id > 2147483647) {
            $response = $this->error('invalid_event_id', 'Event ID must be a positive integer.', 400);
        } else {
            $event = $this->eventRepository->findInStorageFolder(
                (int)$id,
                (int)$site->getSettings()->get('events.storagePid'),
            );
            $response = $event === null
                ? $this->error('event_not_found', 'Event not found.', 404)
                : new JsonResponse(['data' => $event], 200, ['Cache-Control' => 'no-store']);
        }

        return $request->getMethod() === 'HEAD'
            ? $response->withBody(new Stream('php://temp', 'rw'))
            : $response;
    }

    private function error(string $code, string $message, int $status): ResponseInterface
    {
        return new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message]],
            $status,
            ['Cache-Control' => 'no-store'],
        );
    }
}
