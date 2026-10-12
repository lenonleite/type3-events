<?php

declare(strict_types=1);

use TYPO3\TestingFramework\Core\Testbase;

// Based on the testing framework's FunctionalTestsBootstrap.php boilerplate.
(static function (): void {
    $testbase = new Testbase();
    $testbase->defineOriginalRootPath();
    $testbase->createDirectory($testbase->getWebRoot() . 'typo3temp/var/tests');
    $testbase->createDirectory($testbase->getWebRoot() . 'typo3temp/var/transient');
})();
