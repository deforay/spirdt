<?php

declare(strict_types=1);

namespace App\Http\Action\Admin;

use App\Service\SettingsService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * How the installation is configured.
 *
 * Behind RequirePermissionMiddleware(Permission::SETTINGS_MANAGE). Two verbs
 * and no parameters: there is one installation, and the organisation whose
 * localisation this edits comes from the token rather than the path.
 *
 * The response never carries the SMTP password, on either verb. Saving one and
 * being handed it back would put it in a browser's memory, in whatever proxy
 * log sits between, and in the network tab of anybody who was shown the screen.
 */
final class SettingsAction
{
    public function __construct(private readonly SettingsService $settings = new SettingsService())
    {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, 200, $this->settings->read());
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return $this->json($response, 422, ['error' => ['message' => 'A settings object is required.']]);
        }

        try {
            return $this->json($response, 200, $this->settings->update($body));
        } catch (InvalidArgumentException $e) {
            return $this->json($response, 422, ['error' => ['message' => $e->getMessage()]]);
        }
    }

    /** @param array<string,mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
