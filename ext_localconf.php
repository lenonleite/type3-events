<?php

declare(strict_types=1);

use Study\Events\Controller\EventController;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

ExtensionUtility::configurePlugin(
    'Events',
    'List',
    [EventController::class => 'list, show'],
);
