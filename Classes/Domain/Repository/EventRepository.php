<?php

declare(strict_types=1);

namespace Study\Events\Domain\Repository;

use Study\Events\Domain\Model\Event;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * @extends Repository<Event>
 */
class EventRepository extends Repository
{
    protected $defaultOrderings = [
        'eventDate' => QueryInterface::ORDER_ASCENDING,
        'uid' => QueryInterface::ORDER_ASCENDING,
    ];
}
