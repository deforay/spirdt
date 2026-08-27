<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Mail\Mailer;
use App\Mail\MailFailed;
use App\Mail\Message;

/**
 * A mailer that keeps what it was handed.
 *
 * The suite must be able to exercise everything around sending without a mail
 * server and without any chance of reaching a real mailbox. Set `$refuse` and
 * it fails the way a server that will not take the message does, which is the
 * path the audit trail has to record as much as the successful one.
 */
final class RecordingMailer implements Mailer
{
    /** @var list<Message> */
    public array $sent = [];

    public function __construct(public ?string $refuse = null)
    {
    }

    public function send(Message $message): void
    {
        if ($this->refuse !== null) {
            throw new MailFailed($this->refuse);
        }

        $this->sent[] = $message;
    }

    public function last(): ?Message
    {
        return $this->sent === [] ? null : $this->sent[array_key_last($this->sent)];
    }
}
