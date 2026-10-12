<?php

declare(strict_types=1);

namespace Study\Events\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class Event extends AbstractEntity
{
    protected string $title = '';
    protected string $description = '';
    protected ?\DateTime $eventDate = null;
    protected string $location = '';

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getEventDate(): ?\DateTime
    {
        return $this->eventDate;
    }

    public function getLocation(): string
    {
        return $this->location;
    }
}
