<?php

declare(strict_types=1);

namespace App\Http\Action;

use App\Helper\Log;
use App\Service\AttachmentService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Takes a signature or photograph from a device.
 *
 * A separate channel from the assessment on purpose. Media dominates upload
 * size, and a signature that will not go through must not hold up the visit it
 * belongs to — so this endpoint failing leaves a synced assessment and a
 * pending image, which is the right way round.
 *
 * The status codes mean the same things they do on the sync endpoint, because
 * the device decides from them whether to retry:
 *
 *   200  Stored, or already stored. Mark it clean either way.
 *   404  The assessment is not this organisation's. Never retry.
 *   422  The file or its metadata is wrong. Retrying sends the same thing.
 *   500  Our fault. Retry later.
 */
final class AttachmentAction
{
    public function __construct(private readonly ?AttachmentService $attachments = null)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;

        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
            return $this->json($response, 422, [
                'error' => ['message' => 'A file is required, sent as multipart form data.'],
            ]);
        }

        $meta = $request->getParsedBody();

        if (!is_array($meta)) {
            $meta = [];
        }

        try {
            $result = $this->service()->store($file, $meta);
        } catch (InvalidArgumentException $e) {
            return $this->json($response, 422, ['error' => ['message' => $e->getMessage()]]);
        } catch (RuntimeException $e) {
            Log::warning('Attachment refused: {reason}', ['reason' => $e->getMessage()]);

            return $this->json($response, 404, ['error' => ['message' => $e->getMessage()]]);
        }

        return $this->json($response, 200, $result);
    }

    /**
     * Serve one back.
     *
     * Read through PHP rather than handed to the web server, because the files
     * sit outside the document root and the organisation scope is the only
     * thing standing between one tenant's signatures and another's.
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

        // Caching is deliberately left alone. SecurityHeadersMiddleware sets
        // no-store across the API, and that is the right answer for an image
        // of somebody's name — it has no business sitting in a shared proxy,
        // and it is a few kilobytes to fetch again.
        return $response
            ->withHeader('Content-Type', $found['mime'])
            // The type was verified on the way in, and this says so — a
            // browser that sniffs its way to something else would undo that.
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function service(): AttachmentService
    {
        return $this->attachments ?? new AttachmentService(dirname(__DIR__, 3) . '/var/uploads');
    }

    /** @param array<string,mixed> $body */
    private function json(ResponseInterface $response, int $status, array $body): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
