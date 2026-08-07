<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * One field, and what is wrong with it.
 *
 * Carries a REASON CODE rather than a sentence. The assessor reads this on a
 * device set to their own language, and the server has no notion of who is
 * asking — so a message worded here would arrive in English on a French tablet.
 * The device holds the wording, keyed by this code.
 *
 * `params` is what the wording needs to fill in: the limit that was exceeded,
 * not the value that exceeded it. The value is already on screen in front of
 * the person reading.
 */
final class Problem
{
    /**
     * @param string             $field  the context field's code
     * @param string             $reason one of: not_an_integer, below_min, above_max, not_a_date, in_the_future
     * @param array<string,int>  $params values the message needs, e.g. the minimum
     */
    public function __construct(
        public readonly string $field,
        public readonly string $reason,
        public readonly array $params = [],
    ) {
    }

    /** @return array{field:string,reason:string,params:array<string,int>} */
    public function toArray(): array
    {
        return [
            'field'  => $this->field,
            'reason' => $this->reason,
            'params' => $this->params,
        ];
    }
}
