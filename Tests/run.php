<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/vendor/autoload.php';

$extensionRoot = dirname(__DIR__);
$siteRoot = $extensionRoot . '/.build/site';
if (!is_dir($siteRoot)) {
    mkdir($siteRoot, 0777, true);
}
copy(__DIR__ . '/Fixtures/composer.json', $siteRoot . '/composer.json');

/**
 * @param list<string> $command
 * @param array<string, string> $environment
 */
function runCommand(array $command, string $directory, array $environment = []): void
{
    $process = new Process($command, $directory, $environment + siteEnvironment($directory));
    $process->setTimeout(300);
    $process->mustRun(static function (string $type, string $output): void {
        echo $output;
    });
}

/** @return array<string, string> */
function siteEnvironment(string $directory): array
{
    // The tooling autoloader sets these variables too. Each child must use the test site.
    return [
        'TYPO3_PATH_COMPOSER_ROOT' => $directory,
        'TYPO3_PATH_APP' => $directory,
        'TYPO3_PATH_ROOT' => $directory . '/public',
        'TYPO3_PATH_WEB' => $directory . '/public',
        'TYPO3_CONTEXT' => 'Production',
    ];
}

runCommand(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress'], $siteRoot);
// Only this disposable site's generated configuration and database are initialized.
if (!is_file($siteRoot . '/config/system/settings.php')) {
    runCommand([
        PHP_BINARY, 'vendor/bin/typo3', 'setup', '--no-interaction',
        '--driver=sqlite', '--admin-username=admin',
        '--admin-email=tests@example.com', '--project-name=Events tests',
        '--server-type=other', '--create-site=no',
    ], $siteRoot, ['TYPO3_SETUP_ADMIN_PASSWORD' => bin2hex(random_bytes(16)) . 'Aa!']);
}

runCommand([PHP_BINARY, 'vendor/bin/typo3', 'extension:setup'], $siteRoot);

$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($socket === false) {
    throw new RuntimeException($errorMessage ?? 'Cannot allocate test port.', $errorCode ?? 0);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
if ($address === false) {
    throw new RuntimeException('Cannot determine test server address.');
}
$baseUrl = 'http://' . $address . '/';
runCommand([PHP_BINARY, $extensionRoot . '/Tests/Fixtures/seed.php', $baseUrl], $siteRoot);
runCommand([PHP_BINARY, 'vendor/bin/typo3', 'cache:flush'], $siteRoot);

$server = new Process(
    [PHP_BINARY, '-S', $address, '-t', 'public', 'public/index.php'],
    $siteRoot,
    siteEnvironment($siteRoot),
);
$server->setTimeout(null);
$server->start();
try {
    $ready = false;
    for ($attempt = 0; $attempt < 100; ++$attempt) {
        if (!$server->isRunning()) {
            throw new RuntimeException('Test server stopped: ' . $server->getErrorOutput());
        }
        $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
        if ($connection !== false) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('Test server did not become ready.');
    }
    runCommand([PHP_BINARY, $extensionRoot . '/Tests/Smoke/event-pages.php'], $siteRoot);
} finally {
    $server->stop();
    file_put_contents($extensionRoot . '/.build/server.log', $server->getErrorOutput());
}
