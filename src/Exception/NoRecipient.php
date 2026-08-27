<?php

declare(strict_types=1);

namespace App\Exception;

use InvalidArgumentException;

/**
 * There is nowhere to send this, or what was given is not an address.
 *
 * Its own type rather than a plain InvalidArgumentException so the endpoint can
 * tell it apart from "no such assessment" without reading the message. Those
 * two answer different status codes — 422 for something the person at the
 * screen can fix, 404 for an id that names nothing they may see — and choosing
 * between them by looking for a word in a sentence is a decision that changes
 * the moment somebody rewords the sentence.
 */
final class NoRecipient extends InvalidArgumentException
{
}
