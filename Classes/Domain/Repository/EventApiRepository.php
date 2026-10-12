<?php

declare(strict_types=1);

namespace Study\Events\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class EventApiRepository
{
    public function __construct(private ConnectionPool $connectionPool) {}

    /** @return array{id: int, title: string, description: string, eventDate: ?string, location: string}|null */
    public function findInStorageFolder(int $id, int $storagePid): ?array
    {
        if ($storagePid <= 0) {
            return null;
        }

        // Middleware runs before Extbase's TypoScript configuration is available.
        $query = $this->connectionPool->getQueryBuilderForTable('tx_events_domain_model_event');
        $record = $query->select('uid', 'title', 'description', 'event_date', 'location')
            ->from('tx_events_domain_model_event')
            ->where(
                $query->expr()->eq('uid', $query->createNamedParameter($id, Connection::PARAM_INT)),
                $query->expr()->eq('pid', $query->createNamedParameter($storagePid, Connection::PARAM_INT)),
                $query->expr()->eq('deleted', $query->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()->fetchAssociative();

        if ($record === false) {
            return null;
        }

        return [
            'id' => (int)$record['uid'],
            'title' => (string)$record['title'],
            'description' => (string)$record['description'],
            'eventDate' => $record['event_date'] === null ? null
                : (new \DateTimeImmutable('@' . (int)$record['event_date']))->format(\DateTimeInterface::ATOM),
            'location' => (string)$record['location'],
        ];
    }
}
