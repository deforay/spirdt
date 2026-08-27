<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Sending one message.
 *
 * An interface with one implementation, which is usually a smell and is not
 * one here. The application sends mail to addresses outside it, and a test
 * suite that exercises the code around that has to be able to run without a
 * mail server and without the risk of reaching a real mailbox by accident.
 * `SmtpMailer` talks to the server; `tests/Support/RecordingMailer` keeps what
 * it was given so the assertions can look at it.
 *
 * Failure is an exception rather than a false. Everything that calls this
 * records what happened in the audit trail, and "it did not work" is not an
 * answer somebody can act on six months later — the reason is.
 */
interface Mailer
{
    /**
     * @throws MailFailed when the message could not be handed to a server
     */
    public function send(Message $message): void;
}
