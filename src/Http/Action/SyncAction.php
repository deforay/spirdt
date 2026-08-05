<?php

declare(strict_types=1);

namespace App\Http\Action;

use App\Helper\Log;
use App\Service\SyncService;
use App\Support\BinaryUuid;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * Takes an assessment from a device.
 *
 * The payload is filed before it is interpreted, and filed again with the
 * reason if it is refused. This is an audit instrument: being able to show
 * what a device sent, separately from what the server made of it, is worth the
 * storage on the day someone disputes a certification level — and the payload
 * most worth keeping is the one that failed.
 *
 * Status codes matter more than usual here, because the device decides from
 * them whether to retry:
 *
 *   200  Stored. Mark the rows clean.
 *   409  It belongs to another organisation. Never retry; this needs a person.
 *   422  The payload is wrong. Retrying sends the same wrong payload.
 *   500  Our fault. Retry later.
 *
 * A device that retries a 422 forever is a device that never syncs anything
 * else, so the distinction is not cosmetic.
 */
final class SyncAction
{
    public function __construct(private readonly SyncService $sync = new SyncService())
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = $request->getParsedBody();

        if (!is_array($payload)) {
            return $this->json($response, 422, ['error' => ['message' => 'A JSON object is required.']]);
        }

        $submissionId = $this->file($request, $payload, null);

        try {
            $result = $this->sync->accept($payload);
        } catch (InvalidArgumentException $e) {
            $this->recordRefusal($submissionId, $e->getMessage());

            return $this->json($response, 422, ['error' => ['message' => $e->getMessage()]]);
        } catch (RuntimeException $e) {
            Log::warning('Sync refused: {reason}', ['reason' => $e->getMessage()]);
            $this->recordRefusal($submissionId, $e->getMessage());

            return $this->json($response, 409, ['error' => ['message' => $e->getMessage()]]);
        }

        return $this->json($response, 200, $result);
    }

    /**
     * @param  array<string,mixed> $payload
     * @return int|null            the submission row, or null if it could not be filed
     */
    private function file(ServerRequestInterface $request, array $payload, ?string $reason): ?int
    {
        try {
            $id = $payload['id'] ?? null;

            return (int) Capsule::table('submissions_raw')->insertGetId([
                'organization_id'  => (int) $request->getAttribute('organization_id'),
                'assessment_id'    => is_string($id) && BinaryUuid::isValid($id)
                    ? BinaryUuid::toBytes($id)
                    : str_repeat("\0", 16),
                'template_id'      => null,
                'payload'          => json_encode($payload, JSON_THROW_ON_ERROR),
                'rejected_reason'  => $reason,
                'app_version'      => $payload['app_version'] ?? null,
                'device_id'        => $payload['device_id'] ?? null,
                'received_from_ip' => $request->getServerParams()['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Throwable $e) {
            // Never fail a sync because the copy could not be filed. Getting
            // the assessment stored matters more than the record of it.
            Log::warning('Could not file the raw submission: {reason}', ['reason' => $e->getMessage()]);

            return null;
        }
    }

    private function recordRefusal(?int $submissionId, string $reason): void
    {
        if ($submissionId === null) {
            return;
        }

        try {
            Capsule::table('submissions_raw')
                ->where('id', $submissionId)
                ->update(['rejected_reason' => mb_substr($reason, 0, 500)]);
        } catch (Throwable) {
            // Already logged by the caller.
        }
    }

    /** @param array<string,mixed> $body */
    private function json(ResponseInterface $response, int $status, array $body): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
