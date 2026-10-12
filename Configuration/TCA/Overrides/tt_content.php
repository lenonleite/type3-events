<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

$pluginSignature = ExtensionUtility::registerPlugin(
    'Events',
    'List',
    'LLL:EXT:events/Resources/Private/Language/locallang_db.xlf:plugin.list.title',
    'content-plugin',
    'plugins',
    'LLL:EXT:events/Resources/Private/Language/locallang_db.xlf:plugin.list.description',
);

// Editors choose the folder containing the events in the Starting point field.
ExtensionManagementUtility::addToAllTCAtypes(
    'tt_content',
    '--div--;core.form.tabs:plugin,pages',
    $pluginSignature,
    'after:palette:headers',
);
