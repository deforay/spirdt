<?php

declare(strict_types=1);

namespace App\Bootstrap\Providers;

use App\Bootstrap\ServiceProvider;
use App\Helper\Log;
use DI\Container;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\UidProcessor;
use Psr\Log\LoggerInterface;

final class CoreServicesProvider implements ServiceProvider
{
    public static function register(Container $container): void
    {
        $container->set(LoggerInterface::class, static function (): LoggerInterface {
            $logger = new Logger('spirdt');

            // UidProcessor stamps one UID per process, so every line emitted
            // while handling a request shares it. That UID is what makes a
            // failed sync traceable across the middleware, the service layer
            // and the error handler.
            // Held rather than constructed inline: the request log table
            // records the same UID, and a row that cannot be joined to the
            // lines written beside it is half a record.
            $uid = new UidProcessor(12);
            Log::setRequestUid($uid->getUid());

            $logger->pushProcessor($uid);
            $logger->pushProcessor(new PsrLogMessageProcessor());

            $handler = new RotatingFileHandler(
                dirname(__DIR__, 3) . '/var/log/app.log',
                (int) env('LOG_RETENTION_DAYS', 30),
                self::level((string) env('LOG_LEVEL', 'info')),
            );
            $handler->setFormatter(new LineFormatter(null, 'Y-m-d H:i:s', true, true));
            $logger->pushHandler($handler);

            return $logger;
        });
    }

    private static function level(string $name): Level
    {
        return match (strtolower($name)) {
            'debug'   => Level::Debug,
            'notice'  => Level::Notice,
            'warning' => Level::Warning,
            'error'   => Level::Error,
            default   => Level::Info,
        };
    }
}
