<?php

declare(strict_types=1);

namespace App\Mail;

use App\Helper\Log;
use App\Service\SettingsService;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * The mail server this installation was told about.
 *
 * The settings screen has asked for a host, a port, an encryption, a username
 * and a password since 0.1.0 and nothing has ever read them. This is what
 * reads them.
 *
 * BUILT PER MESSAGE rather than held. Sending is rare — somebody emailing a
 * report, a few times a day at most — and a connection held open across a
 * FastCGI process that lives for one request is a connection held open for
 * nothing. What it costs is a TCP handshake on an action that already spent a
 * second rendering a PDF.
 *
 * The credentials never appear in a log line or an exception message. A
 * transport DSN carries the password in the middle of it, and the one place an
 * SMTP password most easily ends up in plain text is a stack trace somebody
 * pastes into a bug report.
 */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly SettingsService $settings = new SettingsService(),
        private readonly ?TransportInterface $transport = null,
    ) {
    }

    public function send(Message $message): void
    {
        $config = $this->settings->mailer();

        $host = trim((string) ($config['host'] ?? ''));
        $from = trim((string) ($config['from_address'] ?? ''));

        // Refused before the connection rather than after it. An installation
        // that never filled the settings in should be told that, not handed
        // whatever a TCP connection to an empty hostname says.
        if ($host === '') {
            throw new MailFailed('No mail server is configured for this installation.');
        }

        if ($from === '') {
            throw new MailFailed('No sending address is configured for this installation.');
        }

        // Built INSIDE the try. A sender's display name and a subject carrying
        // a site's name are both text somebody typed, and a stray newline in
        // either is refused by the MIME layer rather than by the network — so
        // constructing outside it turns a bad setting into an unhandled 500,
        // and an attempted send that never reaches the audit trail.
        try {
            $email = (new Email())
                ->from($this->address($from, (string) ($config['from_name'] ?? '')))
                ->to($message->to)
                ->subject($message->subject)
                ->text($message->body);

            if ($message->attachment !== null) {
                $email->attach($message->attachment, $message->filename, $message->contentType);
            }

            ($this->transport ?? Transport::fromDsn($this->dsn($config)))->send($email);
        } catch (TransportExceptionInterface $e) {
            // The reason is kept — it is the only thing an administrator can
            // act on — and the DSN is not, because it holds the password.
            Log::warning('Mail to {to} was refused: {reason}', [
                'to'     => $message->to,
                'reason' => $e->getMessage(),
            ]);

            throw new MailFailed('The mail server refused the message: ' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            Log::warning('Mail to {to} could not be sent: {reason}', [
                'to'     => $message->to,
                'reason' => $e->getMessage(),
            ]);

            throw new MailFailed('The message could not be sent.', 0, $e);
        }
    }

    /**
     * A sender with a name on it, when there is one to put.
     *
     * Written by hand rather than through Symfony's parser: a display name is
     * free text an administrator typed into a settings box, and it can hold a
     * comma or a quotation mark that would turn one address into two.
     */
    private function address(string $address, string $name): string
    {
        $name = trim($name);

        return $name === '' ? $address : sprintf('"%s" <%s>', addcslashes($name, '"\\'), $address);
    }

    /**
     * The settings, as a transport string.
     *
     * `smtps://` for implicit TLS on 465 and `smtp://` for everything else,
     * because those are two different protocols rather than a flag: on 465 the
     * connection is encrypted before a byte of SMTP is spoken, and on 587 it is
     * plain until STARTTLS.
     *
     * @param array<string,mixed> $config
     */
    private function dsn(array $config): string
    {
        $host = rawurlencode(trim((string) $config['host']));
        $port = (int) ($config['port'] ?? 0);
        $encryption = (string) ($config['encryption'] ?? '');

        $scheme = $encryption === 'ssl' || $port === 465 ? 'smtps' : 'smtp';

        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');

        $credentials = $username === ''
            ? ''
            : rawurlencode($username) . ':' . rawurlencode($password) . '@';

        $dsn = $scheme . '://' . $credentials . $host;

        if ($port > 0) {
            $dsn .= ':' . $port;
        }

        // 'tls' means TLS, not TLS if the server happens to offer it. Symfony's
        // automatic STARTTLS is opportunistic: a server that omits the
        // capability — or a network that strips it on the way past — leaves the
        // message and the credentials going out in the clear, having been
        // configured not to. require_tls makes that a refusal instead.
        if ($encryption === 'tls') {
            return $dsn . '?require_tls=true';
        }

        // A server that offers no STARTTLS is one an administrator chose by
        // setting the encryption to none, and refusing to speak to it would be
        // this application overruling that choice on a network it cannot see.
        return $encryption === 'none' ? $dsn . '?auto_tls=false' : $dsn;
    }
}
