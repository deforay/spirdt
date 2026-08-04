<?php

declare(strict_types=1);

namespace App;

use App\Bootstrap\Providers\CoreServicesProvider;
use App\Bootstrap\ServiceProvider;
use App\Handler\ErrorHandler;
use DI\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory;

final class Bootstrap
{
    /**
     * Domain-grouped DI providers, walked in order at boot. Each registers the
     * bindings for one slice of the app. Add a new service by extending the
     * matching provider — never set() a binding inline here.
     *
     * @var list<class-string<ServiceProvider>>
     */
    private const SERVICE_PROVIDERS = [
        CoreServicesProvider::class,
    ];

    /** @return App<Container> */
    public static function createApp(): App
    {
        self::validateSecurityConfig();

        // Pin PHP's timezone so every date() call shares a clock with MySQL.
        // Without this the two drift on php.ini vs MySQL's SYSTEM tz, which
        // silently breaks any windowed query — token expiry, rate limits,
        // assessment date filters.
        //
        // NOTE: this is the SERVER clock, deliberately UTC by default. Each
        // organisation carries its own timezone and date_format for display,
        // because the SPI-RDT User's Guide makes date format a per-country
        // customisation point. Never format for a user off this value.
        date_default_timezone_set((string) env('APP_TZ', 'UTC'));

        self::bootDatabase();

        $container = new Container();
        foreach (self::SERVICE_PROVIDERS as $provider) {
            $provider::register($container);
        }

        // Seed the static Log gateway with the container's logger so services
        // emit request-UID-correlated lines instead of bare error_log calls.
        Helper\Log::setLogger($container->get(LoggerInterface::class));

        // createFromContainer() rather than setContainer() + create():
        // it carries the concrete container type through, so the app is
        // typed as App<Container> instead of App<ContainerInterface|null>.
        $app = AppFactory::createFromContainer($container);
        $app->setBasePath('/api');

        // Middleware stack — outermost first.
        $app->add(new Middleware\SecurityHeadersMiddleware());
        $app->add(new Middleware\JsonBodyParserMiddleware());
        $app->add(new Middleware\CorsMiddleware());
        $app->addRoutingMiddleware();

        $errorMiddleware = $app->addErrorMiddleware(
            (bool) env('APP_DEBUG', false),
            true,
            true,
            $container->get(LoggerInterface::class),
        );
        $errorMiddleware->setDefaultErrorHandler(
            new ErrorHandler($container->get(LoggerInterface::class)),
        );

        (require dirname(__DIR__) . '/routes/api.php')($app);

        return $app;
    }

    /**
     * Fail fast on a misconfigured deployment rather than serving traffic
     * insecurely. Cheap to run, and catches the two mistakes that actually
     * happen: shipping with APP_DEBUG on, and an unset JWT secret.
     */
    private static function validateSecurityConfig(): void
    {
        $env = (string) env('APP_ENV', 'production');
        $errors = [];

        if ($env === 'production' && (bool) env('APP_DEBUG', false)) {
            $errors[] = 'APP_DEBUG must be false when APP_ENV=production.';
        }

        $secret = (string) env('JWT_SECRET', '');
        if ($secret === '' || strlen($secret) < 32) {
            $errors[] = 'JWT_SECRET must be set and at least 32 characters. '
                . 'Generate one with: php -r "echo bin2hex(random_bytes(32));"';
        }

        if ($errors !== []) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => ['status' => 500, 'message' => 'Configuration error', 'details' => $errors]]);
            exit(1);
        }
    }

    private static function bootDatabase(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'    => 'mysql',
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => env('DB_PORT', '3306'),
            'database'  => env('DB_NAME', 'spirdt'),
            'username'  => env('DB_USER', 'root'),
            'password'  => env('DB_PASS', ''),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            // Align MySQL's session clock with PHP's — see the note in
            // createApp() about windowed queries.
            'timezone'  => '+00:00',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }
}
