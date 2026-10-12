<?php

declare(strict_types=1);

namespace Study\Events\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Study\Events\Controller\EventApiController;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\Site;

final readonly class EventApiMiddleware implements MiddlewareInterface
{
    public function __construct(private EventApiController $controller) {}

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

        return $this->controller->showAction(
            $request,
            $matches[1] ?? '',
            (int)$site->getSettings()->get('events.storagePid'),
        );
    }
}
