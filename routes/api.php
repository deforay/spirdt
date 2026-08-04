<?php

declare(strict_types=1);

use Slim\App;

/**
 * Route registration entry point.
 *
 * Routes are split by audience under routes/api/ as they are added —
 * auditor sync, admin, platform. Keeping the split by WHO CALLS IT (rather
 * than by entity) makes the permission boundary visible in the file tree,
 * and gives the OpenAPI generator a natural grouping.
 */
return static function (App $app): void {
    $app->get('/health', function ($request, $response) {
        $payload = [
            'status'  => 'ok',
            'app'     => env('APP_NAME', 'SPI-RDT'),
            'version' => trim((string) file_get_contents(dirname(__DIR__) . '/VERSION')),
            'time'    => gmdate('c'),
        ];

        $response->getBody()->write((string) json_encode($payload));

        return $response->withHeader('Content-Type', 'application/json');
    });
};
