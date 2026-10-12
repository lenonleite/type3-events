<?php

declare(strict_types=1);

namespace Study\Events\Controller;

use Psr\Http\Message\ResponseInterface;
use Study\Events\Domain\Repository\EventRepository;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Frontend\Controller\ErrorController;

class EventController extends ActionController
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly ErrorController $errorController,
    ) {}

    public function listAction(): ResponseInterface
    {
        // Refresh even an empty cached list when an event is added or changed.
        $this->request->getAttribute('frontend.cache.collector')
            ?->addCacheTags(new CacheTag('tx_events_domain_model_event'));
        $this->view->assign('events', $this->eventRepository->findAll());

        return $this->htmlResponse();
    }

    public function showAction(int $event = 0): ResponseInterface
    {
        // Keep the detail lookup restricted to the plugin's Starting point.
        $eventRecord = $this->eventRepository->findOneBy(['uid' => $event]);
        if ($eventRecord === null) {
            $response = $this->errorController->pageNotFoundAction(
                $this->request,
                'The requested event was not found.',
            );

            throw new PropagateResponseException($response, 1791830001);
        }

        $this->view->assign('event', $eventRecord);

        return $this->htmlResponse();
    }
}
