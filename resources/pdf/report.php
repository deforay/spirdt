<?php

/**
 * The assessment report, laid out for paper.
 *
 * Included by ReportPdfService with $report, $strings, $organisation,
 * $photographs, $responseTones and $levelTones in scope, and nothing else.
 *
 * WRITTEN IN TABLES ON PURPOSE. The renderer is CSS 2.1 — no flexbox, no
 * grid — and a document that is paginated by a library wants a layout that
 * does not care where the page break falls. Every block here can be cut in
 * half by a break without becoming wrong.
 *
 * The order is the report screen's order, because the two are the same
 * document: how the site did, which sections dragged it down, what has to be
 * fixed, and then the detail behind all three.
 *
 * TWO DOCUMENTS OUT OF ONE VIEW. `$variant` decides how much: the full record,
 * or the short one — who was audited and what they have to do about it. The
 * short one is not a different report, it is the same one with the parts
 * nobody has to ACT on left out, which is why it is a flag here rather than a
 * second file to keep in step.
 *
 * @var array<string,mixed>              $report
 * @var array<string,string>             $strings
 * @var string                           $organisation
 * @var string                           $timezone
 * @var int                              $undrawable
 * @var string                           $variant
 * @var bool                             $photographs
 * @var array<string,array{0:string,1:string}> $responseTones
 * @var array<int,array{0:string,1:string}>    $levelTones
 */

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars(
    is_string($value) ? $value : (is_scalar($value) ? (string) $value : ''),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8',
);

/** @param array<string,string|int|float> $params */
$t = static function (string $key, array $params = []) use ($strings, $e): string {
    $message = $strings[$key] ?? $key;

    foreach ($params as $name => $value) {
        $message = str_replace('{' . $name . '}', (string) $value, $message);
    }

    return $e($message);
};

/** A percentage as the app writes one: two places, and a dash for nothing. */
$percent = static fn (?float $value): string => $value === null
    ? '—'
    : number_format($value, 2) . '%';

/**
 * A date somebody can read.
 *
 * Through intl when the server has it, so a French report does not say "Aug".
 * Falling back to the ISO string rather than to an English month, because
 * 2026-08-25 is wrong in no language.
 */
$date = static function (?string $iso) use ($locale): string {
    if ($iso === null || $iso === '') {
        return '—';
    }

    $day = substr($iso, 0, 10);
    $time = strtotime($day);

    if ($time === false) {
        return $day;
    }

    if (!class_exists(\IntlDateFormatter::class)) {
        return $day;
    }

    $formatter = new \IntlDateFormatter(
        $locale,
        \IntlDateFormatter::MEDIUM,
        \IntlDateFormatter::NONE,
    );

    return (string) $formatter->format($time);
};

/**
 * A moment, not just a day.
 *
 * When a visit started and when it ended is evidence about the audit itself —
 * an assessment recorded as taking eleven minutes says something the score
 * does not — and dropping the clock would leave the file saying less than the
 * screen it came from.
 */
$moment = static function (?string $stamp) use ($locale, $timezone, $date): string {
    if ($stamp === null || $stamp === '') {
        return '—';
    }

    // Stored in UTC, read where the audit happened. A visit that began at
    // eight in Lusaka is filed as 06:00Z, and a report printing that back is
    // a report contradicting everybody who was in the room.
    try {
        $moment = new \DateTimeImmutable($stamp, new \DateTimeZone('UTC'));
    } catch (\Exception) {
        return $date($stamp);
    }

    $zone = new \DateTimeZone($timezone);

    if (!class_exists(\IntlDateFormatter::class)) {
        return $moment->setTimezone($zone)->format('Y-m-d H:i');
    }

    $formatter = new \IntlDateFormatter(
        $locale,
        \IntlDateFormatter::MEDIUM,
        \IntlDateFormatter::SHORT,
        $zone,
    );

    return (string) $formatter->format($moment);
};

$assessment = is_array($report['assessment'] ?? null) ? $report['assessment'] : [];
$facility = is_array($assessment['facility'] ?? null) ? $assessment['facility'] : [];
$site = is_array($assessment['site'] ?? null) ? $assessment['site'] : [];
$score = is_array($report['score'] ?? null) ? $report['score'] : ['scored' => false];
$scored = ($score['scored'] ?? false) === true;
$sections = is_array($report['sections'] ?? null) ? $report['sections'] : [];
$findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];
$signatures = is_array($report['signatures'] ?? null) ? $report['signatures'] : [];
$contextFields = is_array($report['context_fields'] ?? null) ? $report['context_fields'] : [];
$sitePhotographs = is_array($report['site_photographs'] ?? null) ? $report['site_photographs'] : [];

/**
 * What a question was answered as, once per instance it applies to.
 *
 * Section 4 is asked again for every pathogen the site tests for, so a
 * question there has as many answers as there are pathogens — and a question
 * answered for HIV and skipped for malaria has ONE. Read as a list of answers,
 * that question looks answered; the missing one is still in the denominator
 * and still costs the site points nobody can account for.
 *
 * So the instances are worked out from the visit rather than from what came
 * back: every pathogen gets a row, and a row with nothing against it says so.
 *
 * @return list<array{pathogen:?string,answer:?array<string,mixed>}>
 */
$instances = static function (array $question, array $section) use ($assessment): array {
    /** @var list<array<string,mixed>> $answers */
    $answers = is_array($question['answers'] ?? null) ? $question['answers'] : [];

    if (($section['scope'] ?? 'assessment') !== 'pathogen') {
        return [['pathogen' => null, 'answer' => $answers[0] ?? null]];
    }

    $names = array_map(
        static fn (array $pathogen): string => (string) ($pathogen['name'] ?? ''),
        is_array($assessment['pathogens'] ?? null) ? $assessment['pathogens'] : [],
    );

    // A visit that named no pathogens has nothing to lay the answers against,
    // and whatever was recorded is still what happened.
    if ($names === []) {
        return $answers === []
            ? [['pathogen' => null, 'answer' => null]]
            : array_map(
                static fn (array $answer): array => [
                    'pathogen' => $answer['pathogen'] ?? null,
                    'answer'   => $answer,
                ],
                $answers,
            );
    }

    $rows = [];

    foreach ($names as $name) {
        $found = null;

        foreach ($answers as $answer) {
            if (($answer['pathogen'] ?? null) === $name) {
                $found = $answer;

                break;
            }
        }

        $rows[] = ['pathogen' => $name, 'answer' => $found];
    }

    return $rows;
};

/**
 * A finding that has moved on from open says so.
 *
 * Nothing closes one from the console yet, and the column has held four states
 * since the schema was written. A plan listing finished work as outstanding is
 * the one way this document could mislead the person it is written for, so the
 * state is drawn wherever it is not simply 'open' rather than waiting for the
 * screen that will set it.
 */
$findingStates = [
    'in_progress' => 'finding.in_progress',
    'closed'      => 'finding.closed',
    'escalated'   => 'finding.escalated',
];

/** The signature slots, named the way the screen that collects them names them. */
$signatureRoles = [
    'assessor_1'          => 'signature.assessor',
    'assessor_2'          => 'signature.secondAssessor',
    'site_representative' => 'signature.siteRepresentative',
];

$status = (string) ($assessment['status'] ?? '');
$statusLabels = [
    'draft'     => 'reports.statusDraft',
    'submitted' => 'reports.statusSubmitted',
    'reviewed'  => 'reports.statusReviewed',
    'finalised' => 'reports.statusFinalised',
];

/** The short document: the site, and the work. Everything else is the record. */
$full = $variant !== 'actions';

$level = $scored ? ($score['level'] ?? null) : null;
$levelTone = $levelTones[$level] ?? ['#EFEFF1', '#46536A'];

$immediate = array_values(array_filter(
    $findings,
    static fn (array $finding): bool => ($finding['urgency'] ?? '') === 'immediate',
));
$later = array_values(array_filter(
    $findings,
    static fn (array $finding): bool => ($finding['urgency'] ?? '') !== 'immediate',
));

/** How many pictures the visit holds, drawn or not. */
$photographCount = count($sitePhotographs);

foreach ($sections as $section) {
    $photographCount += count(is_array($section['photographs'] ?? null) ? $section['photographs'] : []);
}

/**
 * How many were asked for and could not be carried.
 *
 * A document that runs past what one file can hold stops drawing pictures, and
 * this is what stops that from being a silent omission — a record that quietly
 * drops evidence is worse than one that says what is missing from it.
 */
$omitted = 0;

foreach ([$sitePhotographs, ...array_map(
    static fn (array $section): array => is_array($section['photographs'] ?? null)
        ? $section['photographs']
        : [],
    $sections,
)] as $group) {
    foreach ($group as $shot) {
        if (($shot['omitted'] ?? false) === true) {
            $omitted++;
        }
    }
}

?>
<!doctype html>
<html lang="<?= $e($locale) ?>">
<head>
<meta charset="utf-8">
<title><?= $t('report.title') ?></title>
<style>
    @page { margin: 22mm 13mm 16mm; }

    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 9.5pt;
        line-height: 1.45;
        color: #1D2939;
        margin: 0;
    }

    /* Fixed, so it repeats on every page: past the first, the reader has no
       other way to tell which site the sheet in their hand is about. It sits
       in the page margin rather than in the flow. */
    #running {
        position: fixed;
        top: -14mm;
        left: 0;
        right: 0;
        border-bottom: 0.5pt solid #E4E7EC;
        padding-bottom: 3mm;
        font-size: 8pt;
        color: #667085;
    }

    #running .right { float: right; }

    h1 { font-size: 17pt; margin: 0; letter-spacing: -0.3pt; }
    h2 { font-size: 11pt; margin: 7mm 0 2mm; }
    h3 { font-size: 9.5pt; margin: 0 0 1.5mm; page-break-after: avoid; }

    .eyebrow {
        font-size: 7pt;
        letter-spacing: 1.4pt;
        text-transform: uppercase;
        color: #966B1E;
        margin: 0 0 1.5mm;
    }

    /* The one decorative mark in the application, spent here on the name of
       the thing of record and nowhere else. */
    .rule-brass { border-bottom: 1.5pt solid #C08A28; padding-bottom: 1.5mm; display: inline-block; }

    .muted { color: #667085; }
    .small { font-size: 8pt; }
    .right { text-align: right; }
    .center { text-align: center; }

    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; text-align: left; }

    .panel {
        border: 0.5pt solid #E4E7EC;
        margin-bottom: 4mm;
    }

    .panel td, .panel th { padding: 2.4mm 3mm; border-bottom: 0.5pt solid #E4E7EC; }
    .panel tr:last-child td, .panel tr:last-child th { border-bottom: 0; }

    .panel th {
        font-weight: normal;
        color: #667085;
        width: 34%;
    }

    thead th {
        font-size: 7.5pt;
        letter-spacing: 0.8pt;
        text-transform: uppercase;
        color: #667085;
        background: #F9FAFB;
        font-weight: bold;
    }

    /* The four figures somebody checks before reading anything, given equal
       weight because each is a fact about the visit rather than a claim. */
    .figures td {
        border: 0.5pt solid #E4E7EC;
        padding: 3mm;
        width: 25%;
    }

    .figure-value { font-size: 15pt; font-weight: bold; letter-spacing: -0.3pt; }

    .chip {
        display: inline-block;
        padding: 0.6mm 2mm;
        font-size: 8pt;
        font-weight: bold;
    }

    /* A bar with its figure beside it. Colour-adjust is set so a printer that
       drops backgrounds does not turn the comparison into empty boxes. */
    .meter {
        background: #F2F4F7;
        height: 2.2mm;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .meter div { background: #2563EB; height: 2.2mm; }

    .question td { padding: 2mm 3mm; border-bottom: 0.5pt solid #E4E7EC; }
    .question .code { width: 12%; color: #667085; }
    .question .answer { width: 22%; }

    .comment {
        color: #667085;
        margin: 1mm 0 0;
        padding-left: 3mm;
        border-left: 1pt solid #E4E7EC;
    }

    .finding td { padding: 2.4mm 3mm; border-bottom: 0.5pt solid #E4E7EC; }

    .photo { width: 50%; padding: 0 2mm 4mm 0; }
    .photo img { width: 100%; }

    .signature {
        border: 0.5pt solid #E4E7EC;
        padding: 3mm;
        width: 50%;
    }

    .signature img { height: 18mm; }

    .draft {
        border: 1pt solid #B45309;
        color: #B45309;
        font-weight: bold;
        padding: 2mm 3mm;
        margin-bottom: 4mm;
        font-size: 8.5pt;
        text-transform: uppercase;
        letter-spacing: 0.8pt;
    }

    /* A section heading with its questions overleaf is the one break that
       makes a printed report hard to follow. */
    .section { page-break-inside: auto; }
    .keep { page-break-inside: avoid; }
</style>
</head>
<body>

<div id="running">
    <?= $e($site['name'] ?? '') ?>
    <span class="right"><?= $t('report.title') ?><?= $organisation === '' ? '' : ' · ' . $e($organisation) ?></span>
</div>

<p class="eyebrow"><?= $t('report.record') ?></p>
<h1 class="rule-brass"><?= $e($facility['name'] ?? '') ?></h1>
<p class="muted" style="margin: 2.5mm 0 5mm;">
    <?= $e($site['name'] ?? '') ?><?php if (($site['location'] ?? null) !== null && $site['location'] !== '') { ?>
        · <?= $e($site['location']) ?>
    <?php } ?>
    <?php if (isset($statusLabels[$status])) { ?>
        · <?= $t($statusLabels[$status]) ?>
    <?php } ?>
</p>

<?php if ($status === 'draft') { ?>
    <p class="draft"><?= $t('report.draftWarning') ?></p>
<?php } ?>

<?php if ($scored) { ?>
    <!-- On BOTH documents. The short one is what a laboratory manager is
         handed to act on, and the first thing anybody acting on an audit asks
         is how far off they were — the predecessor's action plan carried the
         same summation for the same reason. What stays out of it is the
         section-by-section table below, which is the record's detail rather
         than the work. -->
    <table class="figures keep">
        <tr>
            <td>
                <div class="small muted"><?= $t('report.overall') ?></div>
                <div class="figure-value"><?= $e($percent($score['percentage'] ?? null)) ?></div>
            </td>
            <td>
                <div class="small muted"><?= $t('report.level') ?></div>
                <div style="margin-top: 1.5mm;">
                    <?php if ($level === null) { ?>
                        <span class="figure-value">—</span>
                    <?php } else { ?>
                        <span class="chip" style="background: <?= $e($levelTone[0]) ?>; color: <?= $e($levelTone[1]) ?>;">
                            <?= $t('report.level') ?> <?= $e((string) $level) ?>
                        </span>
                    <?php } ?>
                </div>
                <?php
                // The band's description, not its label — the label is "Level
                // 2", which is what the chip above already says.
                $band = is_array($score['band'] ?? null) ? $score['band'] : [];
                ?>
                <?php if (($band['description'] ?? null) !== null && $band['description'] !== '') { ?>
                    <div class="small muted" style="margin-top: 1mm;"><?= $e($band['description']) ?></div>
                <?php } ?>
            </td>
            <td>
                <div class="small muted"><?= $t('report.scored') ?></div>
                <div style="margin-top: 1.5mm;">
                    <?= $t('report.outOf', [
                        'score'    => (string) ($score['total_score'] ?? 0),
                        'possible' => (string) ($score['total_possible'] ?? 0),
                    ]) ?>
                </div>
            </td>
            <td>
                <div class="small muted"><?= $t('report.findings') ?></div>
                <div class="figure-value"><?= count($findings) ?></div>
            </td>
        </tr>
    </table>
<?php } ?>

<table class="panel">
    <tr>
        <th><?= $t('report.assessedOn') ?></th>
        <td><?= $e($date($assessment['assessed_on'] ?? null)) ?></td>
        <th><?= $t('report.auditRound') ?></th>
        <td><?= $e(($assessment['audit_round'] ?? '') === '' ? '—' : $assessment['audit_round']) ?></td>
    </tr>
    <tr>
        <th><?= $t('report.place') ?></th>
        <td><?= $e(($facility['place'] ?? '') === '' ? '—' : $facility['place']) ?></td>
        <th><?= $t('report.facilityCode') ?></th>
        <td><?= $e(($facility['code'] ?? '') === '' ? '—' : $facility['code']) ?></td>
    </tr>
    <tr>
        <th><?= $t('report.previousVisit') ?></th>
        <td><?= $e($date($assessment['previous_assessed_on'] ?? null)) ?></td>
        <th><?= $t('report.pathogens') ?></th>
        <td>
            <?php
            $names = array_map(
                static fn (array $pathogen): string => (string) ($pathogen['name'] ?? ''),
                is_array($assessment['pathogens'] ?? null) ? $assessment['pathogens'] : [],
            );
            echo $e($names === [] ? '—' : implode(', ', $names));
            ?>
        </td>
    </tr>
    <!-- When the visit happened, to the minute. A score says how the site
         did; these say how the audit was carried out, and they are the part
         somebody queries a year later. -->
    <tr>
        <th><?= $t('report.started') ?></th>
        <td><?= $e($moment($assessment['started_at'] ?? null)) ?></td>
        <th><?= $t('report.ended') ?></th>
        <td><?= $e($moment($assessment['ended_at'] ?? null)) ?></td>
    </tr>
    <tr>
        <th><?= $t('report.submittedAt') ?></th>
        <td><?= $e($moment($assessment['submitted_at'] ?? null)) ?></td>
        <th><?= $t('report.scoredAt') ?></th>
        <td><?= $e($moment($scored ? ($score['scored_at'] ?? null) : null)) ?></td>
    </tr>
    <?php if ($full) { ?>
        <tr>
            <th><?= $t('report.photographs') ?></th>
            <td colspan="3">
                <?= $e((string) $photographCount) ?><?= $photographs ? '' : ' · ' . $t('report.photographsOmitted') ?>
            </td>
        </tr>
    <?php } ?>
</table>

<?php if ($full && $scored && is_array($score['sections'] ?? null) && $score['sections'] !== []) { ?>
    <h2><?= $t('report.sections') ?></h2>

    <table class="panel">
        <thead>
            <tr>
                <th style="width: 6%;">#</th>
                <th><?= $t('report.section') ?></th>
                <th style="width: 18%;"><?= $t('report.scored') ?></th>
                <th style="width: 26%;"><?= $t('report.percent') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($score['sections'] as $row) { ?>
                <tr>
                    <td><?= $e((string) ($row['number'] ?? '')) ?></td>
                    <td>
                        <?= $e($row['title'] ?? '') ?>
                        <?php if (($row['applicable'] ?? true) === false) { ?>
                            <span class="small muted">· <?= $t('report.excluded') ?></span>
                        <?php } elseif ((int) ($row['excluded'] ?? 0) > 0) { ?>
                            <span class="small muted">
                                · <?= $t('report.excludedCount', ['count' => (string) $row['excluded']]) ?>
                            </span>
                        <?php } ?>
                    </td>
                    <td><?= $e((string) ($row['score'] ?? 0)) ?> / <?= $e((string) ($row['possible'] ?? 0)) ?></td>
                    <td>
                        <?= $e($percent(
                            $row['percentage'] === null ? null : (float) $row['percentage'],
                        )) ?>
                        <div class="meter" style="margin-top: 1mm;">
                            <div style="width: <?= $e((string) (int) round((float) ($row['percentage'] ?? 0))) ?>%;"></div>
                        </div>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
<?php } ?>

<?php if ($contextFields !== []) { ?>
    <h2><?= $t('setup.contextHeading') ?></h2>

    <table class="panel">
        <?php foreach ($contextFields as $field) { ?>
            <?php if (($field['type'] ?? '') === 'repeat') { ?>
                <?php if (($field['rows'] ?? []) !== []) { ?>
                    <tr>
                        <th><?= $e($field['label'] ?? '') ?></th>
                        <td>
                            <?php foreach ($field['rows'] as $row) { ?>
                                <div>
                                    <?php
                                    $cells = array_map(
                                        static fn (array $cell): string => $cell['value'],
                                        $row,
                                    );
                                    echo $e(implode(' · ', $cells));
                                    ?>
                                </div>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
            <?php } else { ?>
                <tr>
                    <th><?= $e($field['label'] ?? '') ?></th>
                    <td><?= $e(($field['value'] ?? null) === null ? '—' : $field['value']) ?></td>
                </tr>
            <?php } ?>
        <?php } ?>
    </table>
<?php } ?>

<?php if ($findings === [] && !$full) { ?>
    <h2><?= $t('report.actionPlan') ?></h2>
    <p class="muted"><?= $t('report.noFindings') ?></p>
<?php } ?>

<?php if ($findings !== []) { ?>
    <h2><?= $t('report.actionPlan') ?></h2>

    <?php foreach ([[$immediate, 'report.immediate'], [$later, 'report.followUp']] as [$group, $heading]) { ?>
        <?php if ($group !== []) { ?>
            <h3><?= $t($heading) ?></h3>

            <table class="panel">
                <thead>
                    <tr>
                        <th style="width: 12%;"><?= $t('report.section') ?></th>
                        <th><?= $t('report.findings') ?></th>
                        <th style="width: 26%;"><?= $t('review.responsiblePerson') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($group as $finding) { ?>
                        <tr class="finding">
                            <td>
                                <?= $e($finding['question_code'] ?? '') ?>
                                <?php $state = (string) ($finding['status'] ?? 'open'); ?>
                                <?php if (isset($findingStates[$state])) { ?>
                                    <div class="small muted"><?= $t($findingStates[$state]) ?></div>
                                <?php } ?>
                            </td>
                            <td>
                                <div><?= $e($finding['gap'] ?? '') ?></div>
                                <?php if (($finding['recommendation'] ?? null) !== null && $finding['recommendation'] !== '') { ?>
                                    <p class="comment small"><?= $e($finding['recommendation']) ?></p>
                                <?php } ?>
                            </td>
                            <td class="small">
                                <?= $e(($finding['responsible_person'] ?? '') === '' ? '—' : $finding['responsible_person']) ?>
                                <?php if (($finding['due_date'] ?? null) !== null) { ?>
                                    <div class="muted"><?= $t('report.due') ?> <?= $e($date($finding['due_date'])) ?></div>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        <?php } ?>
    <?php } ?>
<?php } ?>

<?php foreach ($full ? $sections : [] as $section) { ?>
    <div class="section">
        <h2>
            <?= $e((string) ($section['number'] ?? '')) ?>. <?= $e($section['title'] ?? '') ?>
        </h2>

        <table class="panel">
            <?php foreach (is_array($section['questions'] ?? null) ? $section['questions'] : [] as $question) { ?>
                <?php
                $answers = is_array($question['answers'] ?? null) ? $question['answers'] : [];
                ?>
                <tr class="question">
                    <td class="code"><?= $e($question['code'] ?? '') ?></td>
                    <td>
                        <?= $e($question['text'] ?? '') ?>
                        <?php foreach ($answers as $answer) { ?>
                            <?php if (($answer['comment'] ?? null) !== null && $answer['comment'] !== '') { ?>
                                <p class="comment small">
                                    <?php if (($answer['pathogen'] ?? null) !== null) { ?>
                                        <strong><?= $e($answer['pathogen']) ?>:</strong>
                                    <?php } ?>
                                    <?= $e($answer['comment']) ?>
                                </p>
                            <?php } ?>
                        <?php } ?>
                    </td>
                    <td class="answer">
                        <?php foreach ($instances($question, $section) as $row) { ?>
                            <?php
                            $answer = $row['answer'];
                            $tone = $answer === null
                                ? ['#EFEFF1', '#6B6B73']
                                : ($responseTones[$answer['response'] ?? ''] ?? ['#EFEFF1', '#6B6B73']);
                            ?>
                            <div style="margin-bottom: 0.8mm;">
                                <?php if ($row['pathogen'] !== null) { ?>
                                    <span class="small muted"><?= $e($row['pathogen']) ?></span>
                                <?php } ?>
                                <span class="chip" style="background: <?= $e($tone[0]) ?>; color: <?= $e($tone[1]) ?>;">
                                    <?= $answer === null
                                        ? $t('report.unanswered')
                                        : $e($answer['label'] ?? $answer['response'] ?? '') ?>
                                </span>
                            </div>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        </table>

        <?php
        $shots = is_array($section['photographs'] ?? null) ? $section['photographs'] : [];
        ?>
        <?php if ($shots !== [] && $photographs) { ?>
            <h3><?= $t('report.photographs') ?></h3>
            <table>
                <tr>
                    <?php foreach ($shots as $index => $shot) { ?>
                        <?php if ($index > 0 && $index % 2 === 0) { ?>
                            </tr><tr>
                        <?php } ?>
                        <td class="photo keep">
                            <?php if (($shot['data'] ?? null) !== null) { ?>
                                <img src="<?= $shot['data'] ?>" alt="">
                            <?php } ?>
                            <?php if (($shot['caption'] ?? null) !== null && $shot['caption'] !== '') { ?>
                                <div class="small muted"><?= $e($shot['caption']) ?></div>
                            <?php } ?>
                        </td>
                    <?php } ?>
                </tr>
            </table>
        <?php } ?>
    </div>
<?php } ?>

<?php if ($full && $sitePhotographs !== [] && $photographs) { ?>
    <h2><?= $t('report.sitePhotographs') ?></h2>
    <table>
        <tr>
            <?php foreach ($sitePhotographs as $index => $shot) { ?>
                <?php if ($index > 0 && $index % 2 === 0) { ?>
                    </tr><tr>
                <?php } ?>
                <td class="photo keep">
                    <?php if (($shot['data'] ?? null) !== null) { ?>
                        <img src="<?= $shot['data'] ?>" alt="">
                    <?php } ?>
                    <?php if (($shot['caption'] ?? null) !== null && $shot['caption'] !== '') { ?>
                        <div class="small muted"><?= $e($shot['caption']) ?></div>
                    <?php } ?>
                </td>
            <?php } ?>
        </tr>
    </table>
<?php } ?>

<?php if ($undrawable > 0) { ?>
    <p class="small muted" style="margin-top: 4mm;">
        <?= $t('report.imagesUndrawable', ['count' => (string) $undrawable]) ?>
    </p>
<?php } ?>

<?php if ($omitted > 0 && $photographs) { ?>
    <p class="small muted" style="margin-top: 4mm;">
        <?= $t('report.photographsTooLarge', ['count' => (string) $omitted]) ?>
    </p>
<?php } ?>

<?php if ($signatures !== []) { ?>
    <h2><?= $t('report.signatures') ?></h2>
    <table class="keep">
        <tr>
            <?php foreach ($signatures as $index => $signature) { ?>
                <?php if ($index > 0 && $index % 2 === 0) { ?>
                    </tr><tr>
                <?php } ?>
                <td class="signature">
                    <?php if (($signature['data'] ?? null) !== null) { ?>
                        <img src="<?= $signature['data'] ?>" alt="">
                    <?php } ?>
                    <div><?= $e($signature['signed_name'] ?? '') ?></div>
                    <div class="small muted">
                        <?php
                        $role = (string) ($signature['role'] ?? '');
                        echo isset($signatureRoles[$role]) ? $t($signatureRoles[$role]) : $e($role);
                        ?>
                        · <?= $e($moment($signature['uploaded_at'] ?? null)) ?>
                    </div>
                </td>
            <?php } ?>
        </tr>
    </table>
<?php } ?>

<?php if ($full && $scored && is_array($score['anomalies'] ?? null)) { ?>
    <?php
    $anomalies = array_merge(
        is_array($score['anomalies']['unexpected'] ?? null) ? $score['anomalies']['unexpected'] : [],
        is_array($score['anomalies']['violations'] ?? null) ? $score['anomalies']['violations'] : [],
    );
    ?>
    <?php if ($anomalies !== []) { ?>
        <p class="small muted" style="margin-top: 6mm;">
            <?= $t('report.anomalies') ?>
            <?= $e(implode(', ', $anomalies)) ?>
        </p>
    <?php } ?>
<?php } ?>

</body>
</html>
