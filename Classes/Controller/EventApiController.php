<?php

declare(strict_types=1);

namespace Study\Events\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Study\Events\Domain\Repository\EventApiRepository;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Stream;

/** A plain HTTP controller: invoked by our middleware, without Extbase. */
final readonly class EventApiController
{
    public function __construct(private EventApiRepository $eventRepository) {}

    public function showAction(ServerRequestInterface $request, string $id, int $storagePid): ResponseInterface
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $this->error('method_not_allowed', 'Use GET or HEAD.', 405)
                ->withHeader('Allow', 'GET, HEAD');
        }

        $response = $this->findEventResponse($id, $storagePid);

        return $request->getMethod() === 'HEAD'
            ? $response->withBody(new Stream('php://temp', 'rw'))
            : $response;
    }

    private function findEventResponse(string $id, int $storagePid): ResponseInterface
    {
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id) || (int)$id > 2147483647) {
            return $this->error('invalid_event_id', 'Event ID must be a positive integer.', 400);
        }

        $event = $this->eventRepository->findInStorageFolder((int)$id, $storagePid);
        if ($event === null) {
            return $this->error('event_not_found', 'Event not found.', 404);
        }

        return new JsonResponse(['data' => $event], 200, ['Cache-Control' => 'no-store']);
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
