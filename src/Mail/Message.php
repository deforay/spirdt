<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * One message, as this application ever needs to describe one.
 *
 * A value object rather than a builder, because everything sent from here is
 * the same shape: one recipient, a subject, a few lines of text, and a
 * document. When something needs a second recipient or an inline image, this
 * grows a field — and until then a class that cannot express those things is a
 * class nobody has to read the documentation of.
 *
 * The body is plain text. A report going to a laboratory manager on a phone in
 * a district office is read in whatever client that phone has, and the one
 * thing worth saying — here is your audit, here is what is in it — is a
 * sentence rather than a layout.
 */
final class Message
{
    /**
     * @param string      $to          the recipient's address
     * @param string      $subject     one line, already in the reader's language
     * @param string      $body        plain text
     * @param string|null $attachment  the file's bytes, or null for none
     * @param string      $filename    what the attachment is saved as
     * @param string      $contentType the attachment's media type
     */
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $attachment = null,
        public readonly string $filename = 'attachment.pdf',
        public readonly string $contentType = 'application/pdf',
    ) {
    }
}
