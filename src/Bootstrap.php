<?php

declare(strict_types=1);

namespace App;

use App\Bootstrap\Providers\CoreServicesProvider;
use App\Bootstrap\ServiceProvider;
use App\Handler\ErrorHandler;
use DI\Container;
use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
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
        // Outermost of the application middleware, so it sees the status that
        // is actually returned — including one produced by the error handler,
        // which is the case most worth having a record of.
        $app->add(new Middleware\ApiLoggerMiddleware());
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
                . 'Generate one with: php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"';
        }

        if ($errors !== []) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => ['status' => 500, 'message' => 'Configuration error', 'details' => $errors]]);
            exit(1);
        }
    }

    /** Shared for the life of the process — see bootDatabase() for why. */
    private static ?Dispatcher $events = null;

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
        // Without a dispatcher, NO Eloquent model event fires. Not creating,
        // not saving, not deleting — they are registered, never called, and
        // nothing anywhere reports it. Standalone Illuminate does not wire one
        // for you the way the full framework does.
        //
        // Both tenancy traits hang a `creating` hook off this to stamp the
        // tenant on every insert, and until this line existed that hook did
        // nothing at all. It went unnoticed because every call site happened
        // to set the column explicitly; the first one that did not wrote a row
        // with no tenant.
        //
        // ONE DISPATCHER FOR THE PROCESS, and that is not a micro-optimisation.
        // A model registers its listeners on whichever dispatcher is current
        // when the class first boots, and Eloquent boots a class once. Handing
        // out a fresh dispatcher on a later createApp() would leave those
        // listeners on the discarded one — the events would stop firing again,
        // silently, and only where createApp() runs more than once per
        // process: the test suite, and anything long-running.
        $capsule->setEventDispatcher(self::$events ??= new Dispatcher(new IlluminateContainer()));

        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }
}
