<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Core\BootService;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$loader = require getcwd() . '/vendor/autoload.php';
SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container = Bootstrap::init($loader, true)->get(BootService::class)->loadExtLocalconfDatabaseAndExtTables();
Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class);
Bootstrap::initializeBackendAuthentication();
$GLOBALS['LANG'] = $container->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

$siteFile = getcwd() . '/config/sites/events-test/config.yaml';
if (is_file($siteFile)) {
    $configuration = Yaml::parseFile($siteFile);
    $configuration['base'] = $argv[1];
} else {
    $handler = GeneralUtility::makeInstance(DataHandler::class);
    $handler->start([
        'pages' => [
            'NEW_root' => ['pid' => 0, 'title' => 'Events tests', 'slug' => '/', 'is_siteroot' => 1, 'hidden' => 0],
            'NEW_storage' => ['pid' => 'NEW_root', 'title' => 'Default events', 'doktype' => 254, 'hidden' => 0],
        ],
        'sys_template' => [
            'NEW_template' => [
                'pid' => 'NEW_root', 'title' => 'Minimal test rendering', 'root' => 1,
                'config' => "page = PAGE\npage.10 = CONTENT\npage.10.table = tt_content\n"
                    . "page.10.select.orderBy = sorting\npage.10.select.where = colPos=0\n",
            ],
        ],
    ], []);
    $handler->process_datamap();
    if ($handler->errorLog !== []) {
        throw new RuntimeException(implode("\n", $handler->errorLog));
    }
    $configuration = [
        'rootPageId' => $handler->substNEWwithIDs['NEW_root'],
        'base' => $argv[1],
        'dependencies' => ['typo3/fluid-styled-content', 'study/events'],
        'languages' => [[
            'title' => 'English', 'enabled' => true, 'languageId' => 0,
            'base' => '/', 'locale' => 'en-US', 'hreflang' => 'en-US',
            'navigationTitle' => 'English', 'flag' => 'global',
        ]],
    ];
    mkdir(dirname($siteFile), 0777, true);
    file_put_contents(dirname($siteFile) . '/settings.yaml', Yaml::dump([
        'events.storagePid' => $handler->substNEWwithIDs['NEW_storage'],
    ]));
}
file_put_contents($siteFile, Yaml::dump($configuration, 5));
