<?php

declare(strict_types=1);

namespace App\Http\Action\Admin;

use App\Service\SitePhotoService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * The photograph of a testing site: put one there, read it back, take it away.
 *
 * Apart from RegistryAction because it deals in bytes rather than in JSON, and
 * because the two ends of it need different permissions — anybody who can read
 * the registry can see the bench, and only somebody who can change the
 * registry can change what it looks like. Routes place them accordingly.
 *
 * Status codes carry the same meanings as the sync channel's, because the
 * screen decides from them whether sending the same thing again could work:
 *
 *   200  Stored, or already stored.
 *   404  No such site in this programme, or it has no photograph.
 *   422  The file is wrong. Retrying sends the same thing.
 */
final class SitePhotoAction
{
    public function __construct(private readonly ?SitePhotoService $photos = null)
    {
    }

    /**
     * Serve the image.
     *
     * Read through PHP rather than handed to the web server: the files sit
     * outside the document root, and the programme scope is the only thing
     * keeping one country's registry away from another's.
     *
     * @param array<string,string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $found = $this->service()->read((string) ($args['id'] ?? ''));

        if ($found === null) {
            return $this->json($response, 404, ['error' => ['message' => 'Not found.']]);
        }

        $response->getBody()->write($found['bytes']);

        return $response
            ->withHeader('Content-Type', $found['mime'])
            // The type was verified on the way in, and this says so. A browser
            // that sniffed its way to something else would undo that.
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /** @param array<string,string> $args */
    public function store(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $file = $request->getUploadedFiles()['file'] ?? null;

        if (!$file instanceof UploadedFileInterface) {
            return $this->json($response, 422, [
                'error' => ['message' => 'A file is required, sent as multipart form data.'],
            ]);
        }

        try {
            $stored = $this->service()->store((string) ($args['id'] ?? ''), $file);
        } catch (InvalidArgumentException $e) {
            return $this->json($response, 422, ['error' => ['message' => $e->getMessage()]]);
        } catch (RuntimeException $e) {
            return $this->json($response, 404, ['error' => ['message' => $e->getMessage()]]);
        }

        return $this->json($response, 200, ['photo' => $stored]);
    }

    /** @param array<string,string> $args */
    public function destroy(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        try {
            $left = $this->service()->remove((string) ($args['id'] ?? ''));
        } catch (RuntimeException $e) {
            return $this->json($response, 404, ['error' => ['message' => $e->getMessage()]]);
        }

        return $this->json($response, 200, ['photo' => $left]);
    }

    private function service(): SitePhotoService
    {
        return $this->photos ?? new SitePhotoService(dirname(__DIR__, 4) . '/var/uploads');
    }

    /** @param array<string,mixed> $body */
    private function json(ResponseInterface $response, int $status, array $body): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
