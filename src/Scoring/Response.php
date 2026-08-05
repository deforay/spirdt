<?php

declare(strict_types=1);

namespace App\Scoring;

/**
 * The four responses an assessor may record, matching answers.response in the
 * database exactly.
 *
 * Point values deliberately live in the template rather than here — the User's
 * Guide states countries adjust the instrument during customisation, so what
 * a Y is worth is data. What is fixed is the set of responses itself, because
 * the database column is an ENUM and the checklist has four boxes per row.
 */
enum Response: string
{
    case Yes = 'Y';
    case Partial = 'P';
    case No = 'N';
    case NotApplicable = 'NA';
}
