<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\BinaryUuidCast;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasBinaryUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A gap recorded during a visit, and what is to be done about it.
 *
 * Outlives the assessment it came from. A score is a photograph of one day; a
 * finding is open until someone closes it, which is the part the site and the
 * programme actually work from.
 *
 * responsibility_level is why findings are not derivable from the answers.
 * Whether a gap belongs to the site, the district or the national programme is
 * a judgement the assessor makes on the day, and one recorded against a site
 * that cannot act on it stays open forever.
 */
final class Finding extends Model
{
    use BelongsToOrganization;
    use HasBinaryUuidKey;

    protected $table = 'findings';

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'id'            => BinaryUuidCast::class,
        'assessment_id' => BinaryUuidCast::class,
        'pathogen_id'   => BinaryUuidCast::class,
        'due_date'      => 'date',
        'closed_on'     => 'date',
    ];
}
