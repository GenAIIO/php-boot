<?php

/**
 * Auto-discovery flow end to end.
 *
 *   composer install
 *   php example.php
 *
 * We write zero build wiring. The Kernel reads each installed component's
 * "extra.genai.processors" from composer, registers them, compiles our Demo
 * namespace, then boots a Dispatcher.
 */

use GenAI\Boot\Kernel;
use GenAI\Http\Request;

$loader = require __DIR__ . '/vendor/autoload.php';

$kernel = new Kernel();

// --- build (PHP 8) -----------------------------------------------------------
$compiler = $kernel->compile(__DIR__, array('Demo'), $loader);

echo "Processors auto-discovered from installed packages:\n";
foreach ($compiler->discoverProcessors() as $processor) {
    echo "  - " . $processor . "\n";
}

// --- runtime (PHP 5.3-safe) --------------------------------------------------
$dispatcher = $kernel->boot();   // templates/ picked up by convention

echo "\n===== dispatch =====\n";
$requests = array(
    new Request('GET', '/hello/world'),
    new Request('GET', '/nope'),
);
foreach ($requests as $request) {
    $response = $dispatcher->dispatch($request);
    printf("%-4s %-12s -> %d\n", $request->getMethod(), $request->getUri()->getPath(), $response->getStatusCode());
    echo '    ' . trim((string) $response->getBody()) . "\n";
}
