<?php

declare(strict_types=1);

namespace App\Scoring;

/**
 * One question the assessor is expected to answer, already resolved against
 * the assessment's context: Section 4 expanded once per pathogen, Section 5
 * present only where the site refers specimens.
 *
 * This is the list the form renders from and the list the engine scores
 * against, which is deliberate — if the two were derived separately, a
 * question could be shown but not scored, or scored but never shown.
 */
final readonly class ExpectedQuestion
{
    public function __construct(
        public int $sectionNumber,
        public string $sectionCode,
        public string $scope,
        public string $questionCode,
        public ?string $pathogen,
        public bool $naAllowed,
        /**
         * Responses the template obliges the assessor to explain, from the
         * question's comment_required_for. A Partial or a No is a gap the site
         * has to act on, and a gap with no words beside it is one nobody can
         * work from six months later.
         *
         * @var list<string>
         */
        public array $commentRequiredFor = [],
    ) {
    }

    /**
     * The natural key of an answer to this question, mirroring the
     * (question_code, pathogen_key) unique constraint on the answers table.
     */
    public function key(): string
    {
        return $this->questionCode . '|' . ($this->pathogen ?? '');
    }
}
