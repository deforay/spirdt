<?php

declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

/**
 * The message did not reach a server.
 *
 * Distinct from an argument being wrong, which is the caller's fault and
 * answers 422. This is the network, the credentials, or a mail server saying
 * no, and it answers 502 — the request was fine and something we depend on was
 * not.
 */
final class MailFailed extends RuntimeException
{
}
