<?php

declare(strict_types=1);

// Run by ErrorHandlersProcessTest as a separate process: one scenario per invocation.
require __DIR__ . '/../../vendor/autoload.php';

use Trunk\Error\EmergencyLog;
use Trunk\Error\ErrorHandlers;
use Trunk\Error\ExceptionHandler;
use Trunk\Logging\ContextHolder;
use Trunk\Logging\FileHandler;
use Trunk\Logging\JsonFormatter;
use Trunk\Logging\RequestContext;
use Trunk\Logging\StructuredLogger;

$arguments = is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
$scenario = is_string($arguments[1] ?? null) ? $arguments[1] : 'none';
$directory = is_string($arguments[2] ?? null) ? $arguments[2] : sys_get_temp_dir();

$kind = $scenario === 'oom' || $scenario === 'compile' ? 'http' : 'cli';
$debug = $scenario === 'warning-dev';
$holder = new ContextHolder();
$holder->set(new RequestContext('req_TESTREQUEST0001', 'trace', 'span', $kind));
$logger = new StructuredLogger(new JsonFormatter(), new FileHandler($directory), 'debug', $holder, 'proc', 'production');
$handlers = new ErrorHandlers(new EmergencyLog($directory . '/emergency.log'), false, $kind);
$handlers->register();

if ($scenario !== 'no-services') {
    $handlers->attach($logger, new ExceptionHandler($logger), $holder, $debug);
}

switch ($scenario) {
    case 'oom':
        ini_set('memory_limit', '16M');
        $hog = [];

        for ($i = 0; $i < \PHP_INT_MAX; ++$i) {
            $hog[] = str_repeat('x', 1024 * 1024);
        }

        // no break
    case 'compile':
        file_put_contents($directory . '/broken.php', "<?php\nfunction strlen() {}\n");
        require $directory . '/broken.php';

        break;

    case 'uncaught':
        throw new RuntimeException('database password is hunter2');

    case 'warning-prod':
        echo (string) file_get_contents('/definitely/not/here');
        trigger_error('a harmless warning', E_USER_WARNING);
        echo 'continued';

        break;

    case 'warning-dev':
        try {
            trigger_error('a strict warning', E_USER_WARNING);
            echo 'not reached';
        } catch (ErrorException $e) {
            echo 'caught:' . $e->getMessage();
        }

        break;

    case 'deprecation':
        trigger_error('old api', E_USER_DEPRECATED);
        echo 'continued';

        break;
}
