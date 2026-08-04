<?php

declare(strict_types=1);

namespace App\Handler;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Interfaces\ErrorHandlerInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Single JSON error shape for the whole API.
 *
 * Every failure — thrown HttpException, uncaught Throwable, or a 404 from the
 * router — leaves here as the same envelope, so clients need exactly one
 * error path. The PWA in particular retries on sync failure and must be able
 * to tell "retry this" from "this will never succeed" without parsing prose.
 *
 * Detail is only ever exposed when APP_DEBUG is on. In production an
 * unexpected Throwable becomes a generic 500 with a reference ID; the real
 * message and stack go to the log under the same request UID.
 */
final class ErrorHandler implements ErrorHandlerInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(
        Request $request,
        \Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): Response {
        // Codes outside the HTTP range appear when a non-HTTP exception
        // carries an application error code — coerce those to 500 rather
        // than emitting a nonsense status.
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        $status = $status >= 400 && $status <= 599 ? $status : 500;

        $reference = bin2hex(random_bytes(6));

        if ($status >= 500) {
            $this->logger->error('Unhandled error', [
                'reference' => $reference,
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
                'method'    => $request->getMethod(),
                'path'      => $request->getUri()->getPath(),
            ]);
        }

        $payload = [
            'error' => [
                'status'    => $status,
                'message'   => $this->publicMessage($exception, $status, $displayErrorDetails),
                'reference' => $reference,
            ],
        ];

        if ($displayErrorDetails && $status >= 500) {
            $payload['error']['debug'] = [
                'exception' => $exception::class,
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
            ];
        }

        $response = new SlimResponse();
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    private function publicMessage(\Throwable $exception, int $status, bool $debug): string
    {
        if ($exception instanceof HttpException) {
            return $exception->getMessage();
        }

        return $debug || $status < 500
            ? $exception->getMessage()
            : 'An unexpected error occurred.';
    }
}
