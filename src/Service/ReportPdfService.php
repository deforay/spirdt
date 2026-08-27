<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Organization;
use App\Tenancy\TenantContext;
use Dompdf\Dompdf;
use Dompdf\Options;
use InvalidArgumentException;

/**
 * The visit as a file somebody can keep.
 *
 * The report screen prints, and printing is what a visit needs at the end of
 * the day. What it cannot do is be attached to an email, filed on a shared
 * drive, or produced for an audit two years later by somebody who was never
 * given the login — and those are the three things a programme actually does
 * with a report. So the same document is written out as PDF, from the server,
 * from the same assembled report the screen reads.
 *
 * IT IS NOT THE SCREEN, PRINTED. The screen is Vue and Tailwind and a grid;
 * this is one PHP view of tables, because the renderer is CSS 2.1 and because
 * a document paginated by a library is not the place for a layout that depends
 * on a viewport. What the two share is the ORDER — how they did, which
 * sections dragged it down, what has to be fixed, and then the detail behind
 * all three — and the wording, which is lifted from the same catalogues the
 * screen uses rather than written again here.
 *
 * It carries MORE than the screen does, on purpose. Part A never made it onto
 * the report screen, and a facility's type, level and staffing are the first
 * thing a reader of the paper copy looks for.
 *
 * PHOTOGRAPHS ARE OPTIONAL AND THAT IS THE POINT. Five per section at a phone
 * camera's resolution is a document nobody can email, so the caller says which
 * one they want. Without them this is a few dozen kilobytes.
 */
final class ReportPdfService
{
    /**
     * The document's own wording, in the four languages the app ships.
     *
     * Lifted verbatim from web/src/i18n rather than written again, because two
     * catalogues that disagree produce a screen and a file that name the same
     * thing differently — and the one nobody reads is the one that goes to the
     * ministry. Only the furniture is here. Every word about the INSTRUMENT —
     * a section title, a question, a band description — is localised by
     * ReportService out of the template, which is the only thing that knows
     * what a country's form says.
     *
     * @var array<string,array<string,string>>
     */
    private const STRINGS = [
        'en' => [
            'finding.in_progress' => 'In progress',
            'finding.closed' => 'Closed',
            'finding.escalated' => 'Escalated',
            'report.noFindings' => 'No corrective actions were recorded during this audit.',
            'report.imagesUndrawable' => 'This server cannot draw {count} of the images recorded during this audit. They are in the system and can be seen on the report screen.',
            'report.photographsTooLarge' => '{count} photographs are not shown here. The file would have been too large to send.',
            'signature.assessor' => 'Assessor',
            'signature.secondAssessor' => 'Second assessor',
            'signature.siteRepresentative' => 'Site representative',
            'report.photographsOmitted' => 'not included',
            'report.title' => 'Assessment report',
            'report.record' => 'Assessment record',
            'report.assessedOn' => 'Assessed on',
            'report.previousVisit' => 'Previous audit',
            'report.auditRound' => 'Audit round',
            'report.status' => 'Status',
            'report.place' => 'Place',
            'report.facilityCode' => 'Facility code',
            'report.pathogens' => 'Pathogens',
            'report.overall' => 'Overall',
            'report.level' => 'Level',
            'report.outOf' => '{score} of {possible} points',
            'report.sections' => 'Sections',
            'report.section' => 'Section',
            'report.scored' => 'Scored',
            'report.excluded' => 'Not applicable',
            'report.percent' => 'Percentage',
            'report.actionPlan' => 'Corrective action plan',
            'report.immediate' => 'Immediate',
            'report.followUp' => 'Follow-up',
            'report.due' => 'Due',
            'report.unanswered' => 'Not answered',
            'report.photographs' => 'Photographs',
            'report.sitePhotographs' => 'Photographs of the site',
            'report.signatures' => 'Signatures',
            'report.signedBy' => 'Signed by {name}',
            'report.draftWarning' => 'Draft — these figures are not final',
            'report.findings' => 'Findings',
            'report.started' => 'Assessment started',
            'report.ended' => 'Assessment ended',
            'report.submittedAt' => 'Submitted',
            'report.scoredAt' => 'Scored',
            'report.anomalies' => 'Some answers were recorded that the instrument did not expect. They are kept with the score.',
            'report.excludedCount' => '{count} not applicable',
            'reports.statusDraft' => 'Draft',
            'reports.statusSubmitted' => 'Submitted',
            'reports.statusReviewed' => 'Reviewed',
            'reports.statusFinalised' => 'Finalised',
            'setup.contextHeading' => 'About the site',
            'review.responsiblePerson' => 'Responsible person',
            'report.page' => 'Page {page} of {pages}',
        ],
        'fr' => [
            'finding.in_progress' => 'En cours',
            'finding.closed' => 'Clôturée',
            'finding.escalated' => 'Transmise',
            'report.noFindings' => "Aucune action corrective n'a été enregistrée lors de cet audit.",
            'report.imagesUndrawable' => "Ce serveur ne peut pas afficher {count} des images enregistrées pendant cet audit. Elles sont conservées et visibles sur l'écran du rapport.",
            'report.photographsTooLarge' => '{count} photographies ne sont pas affichées ici. Le fichier aurait été trop volumineux pour être envoyé.',
            'signature.assessor' => 'Évaluateur',
            'signature.secondAssessor' => 'Second évaluateur',
            'signature.siteRepresentative' => 'Représentant du site',
            'report.photographsOmitted' => 'non incluses',
            'report.title' => 'Rapport d\'évaluation',
            'report.record' => 'Registre d\'évaluation',
            'report.assessedOn' => 'Évalué le',
            'report.previousVisit' => 'Audit précédent',
            'report.auditRound' => 'Cycle d’audit',
            'report.status' => 'Statut',
            'report.place' => 'Lieu',
            'report.facilityCode' => 'Code de l\'établissement',
            'report.pathogens' => 'Agents pathogènes',
            'report.overall' => 'Global',
            'report.level' => 'Niveau',
            'report.outOf' => '{score} sur {possible} points',
            'report.sections' => 'Sections',
            'report.section' => 'Section',
            'report.scored' => 'Noté',
            'report.excluded' => 'Sans objet',
            'report.percent' => 'Pourcentage',
            'report.actionPlan' => 'Plan d\'action correctif',
            'report.immediate' => 'Immédiat',
            'report.followUp' => 'Suivi',
            'report.due' => 'Échéance',
            'report.unanswered' => 'Sans réponse',
            'report.photographs' => 'Photographies',
            'report.sitePhotographs' => 'Photographies du site',
            'report.signatures' => 'Signatures',
            'report.signedBy' => 'Signé par {name}',
            'report.draftWarning' => 'Brouillon — ces chiffres ne sont pas définitifs',
            'report.findings' => 'Constats',
            'report.started' => 'Évaluation commencée',
            'report.ended' => 'Évaluation terminée',
            'report.submittedAt' => 'Transmise',
            'report.scoredAt' => 'Notée',
            'report.anomalies' => 'Certaines réponses enregistrées n\'étaient pas attendues par l\'instrument. Elles sont conservées avec le score.',
            'report.excludedCount' => '{count} sans objet',
            'reports.statusDraft' => 'Brouillon',
            'reports.statusSubmitted' => 'Soumis',
            'reports.statusReviewed' => 'Revu',
            'reports.statusFinalised' => 'Finalisé',
            'setup.contextHeading' => 'À propos du site',
            'review.responsiblePerson' => 'Personne responsable',
            'report.page' => 'Page {page} sur {pages}',
        ],
        'pt' => [
            'finding.in_progress' => 'Em curso',
            'finding.closed' => 'Encerrada',
            'finding.escalated' => 'Escalada',
            'report.noFindings' => 'Não foram registadas ações corretivas durante esta auditoria.',
            'report.imagesUndrawable' => 'Este servidor não consegue desenhar {count} das imagens registadas durante esta auditoria. Estão guardadas e podem ser vistas no ecrã do relatório.',
            'report.photographsTooLarge' => '{count} fotografias não são mostradas aqui. O ficheiro teria sido demasiado grande para enviar.',
            'signature.assessor' => 'Avaliador',
            'signature.secondAssessor' => 'Segundo avaliador',
            'signature.siteRepresentative' => 'Representante do local',
            'report.photographsOmitted' => 'não incluídas',
            'report.title' => 'Relatório de avaliação',
            'report.record' => 'Registo de avaliação',
            'report.assessedOn' => 'Avaliado em',
            'report.previousVisit' => 'Auditoria anterior',
            'report.auditRound' => 'Ronda de auditoria',
            'report.status' => 'Estado',
            'report.place' => 'Local',
            'report.facilityCode' => 'Código da unidade',
            'report.pathogens' => 'Agentes patogénicos',
            'report.overall' => 'Global',
            'report.level' => 'Nível',
            'report.outOf' => '{score} de {possible} pontos',
            'report.sections' => 'Secções',
            'report.section' => 'Secção',
            'report.scored' => 'Pontuado',
            'report.excluded' => 'Não aplicável',
            'report.percent' => 'Percentagem',
            'report.actionPlan' => 'Plano de ação corretiva',
            'report.immediate' => 'Imediato',
            'report.followUp' => 'Seguimento',
            'report.due' => 'Prazo',
            'report.unanswered' => 'Sem resposta',
            'report.photographs' => 'Fotografias',
            'report.sitePhotographs' => 'Fotografias do local',
            'report.signatures' => 'Assinaturas',
            'report.signedBy' => 'Assinado por {name}',
            'report.draftWarning' => 'Rascunho — estes números não são definitivos',
            'report.findings' => 'Constatações',
            'report.started' => 'Avaliação iniciada',
            'report.ended' => 'Avaliação terminada',
            'report.submittedAt' => 'Enviada',
            'report.scoredAt' => 'Pontuada',
            'report.anomalies' => 'Foram registadas respostas que o instrumento não esperava. Ficam guardadas com a pontuação.',
            'report.excludedCount' => '{count} não aplicável',
            'reports.statusDraft' => 'Rascunho',
            'reports.statusSubmitted' => 'Enviado',
            'reports.statusReviewed' => 'Revisto',
            'reports.statusFinalised' => 'Finalizado',
            'setup.contextHeading' => 'Sobre o local',
            'review.responsiblePerson' => 'Pessoa responsável',
            'report.page' => 'Página {page} de {pages}',
        ],
        'es' => [
            'finding.in_progress' => 'En curso',
            'finding.closed' => 'Cerrada',
            'finding.escalated' => 'Escalada',
            'report.noFindings' => 'No se registraron acciones correctivas durante esta auditoría.',
            'report.imagesUndrawable' => 'Este servidor no puede dibujar {count} de las imágenes registradas durante esta auditoría. Están guardadas y pueden verse en la pantalla del informe.',
            'report.photographsTooLarge' => '{count} fotografías no se muestran aquí. El archivo habría sido demasiado grande para enviarlo.',
            'signature.assessor' => 'Evaluador',
            'signature.secondAssessor' => 'Segundo evaluador',
            'signature.siteRepresentative' => 'Representante del sitio',
            'report.photographsOmitted' => 'no incluidas',
            'report.title' => 'Informe de evaluación',
            'report.record' => 'Registro de evaluación',
            'report.assessedOn' => 'Evaluado el',
            'report.previousVisit' => 'Auditoría anterior',
            'report.auditRound' => 'Ronda de auditoría',
            'report.status' => 'Estado',
            'report.place' => 'Lugar',
            'report.facilityCode' => 'Código del establecimiento',
            'report.pathogens' => 'Patógenos',
            'report.overall' => 'Global',
            'report.level' => 'Nivel',
            'report.outOf' => '{score} de {possible} puntos',
            'report.sections' => 'Secciones',
            'report.section' => 'Sección',
            'report.scored' => 'Puntuado',
            'report.excluded' => 'No aplicable',
            'report.percent' => 'Porcentaje',
            'report.actionPlan' => 'Plan de acción correctiva',
            'report.immediate' => 'Inmediato',
            'report.followUp' => 'Seguimiento',
            'report.due' => 'Vencimiento',
            'report.unanswered' => 'Sin responder',
            'report.photographs' => 'Fotografías',
            'report.sitePhotographs' => 'Fotografías del sitio',
            'report.signatures' => 'Firmas',
            'report.signedBy' => 'Firmado por {name}',
            'report.draftWarning' => 'Borrador — estas cifras no son definitivas',
            'report.findings' => 'Hallazgos',
            'report.started' => 'Evaluación iniciada',
            'report.ended' => 'Evaluación finalizada',
            'report.submittedAt' => 'Enviada',
            'report.scoredAt' => 'Puntuada',
            'report.anomalies' => 'Se registraron respuestas que el instrumento no esperaba. Se conservan con la puntuación.',
            'report.excludedCount' => '{count} no aplicable',
            'reports.statusDraft' => 'Borrador',
            'reports.statusSubmitted' => 'Enviado',
            'reports.statusReviewed' => 'Revisado',
            'reports.statusFinalised' => 'Finalizado',
            'setup.contextHeading' => 'Sobre el sitio',
            'review.responsiblePerson' => 'Persona responsable',
            'report.page' => 'Página {page} de {pages}',
        ],
    ];

    /**
     * How many bytes of photograph one document may carry.
     *
     * Uploads are brought down to about half a megabyte apiece when the server
     * has ext-gd, and stored exactly as they arrived when it does not — which
     * `bin/preflight` warns about and no installation is obliged to fix. At ten
     * megabytes a picture, five a section, a visit can hold two hundred
     * megabytes of evidence, and embedding all of it fails the request rather
     * than producing a large file.
     *
     * So the pictures are drawn until this is spent and the rest are named
     * instead of shown. It is not a silent truncation: the document says how
     * many it left out, because a record that quietly drops evidence is worse
     * than one that admits to it.
     */
    private const PHOTOGRAPH_BUDGET_BYTES = 20 * 1024 * 1024;

    /** The whole visit, question by question. */
    public const FULL = 'full';

    /**
     * The site's details and what it has to do about them, and nothing else.
     *
     * The full report is a record: fifty-nine questions, whether or not each
     * was answered, because that is what makes it evidence. It is also six
     * pages, and the person who has to ACT on it needs two — who was audited
     * and what was found. Handing a laboratory manager the record and asking
     * them to find the work in it is how a corrective action plan becomes a
     * document nobody opens.
     */
    public const ACTIONS = 'actions';

    /** Response code to the two colours it wears — the tint and the ink. */
    private const RESPONSE_TONES = [
        'Y'  => ['#E8F6ED', '#15803D'],
        'P'  => ['#FDF1E3', '#B45309'],
        'N'  => ['#FBE9E9', '#C81E1E'],
        'NA' => ['#EFEFF1', '#6B6B73'],
    ];

    /** The certification ramp: one hue, deepening. See web/DESIGN.md. */
    private const LEVEL_TONES = [
        0 => ['#E7EAEF', '#46536A'],
        1 => ['#CFD9E6', '#32445E'],
        2 => ['#A6BAD1', '#1E3452'],
        3 => ['#4A6C94', '#FFFFFF'],
        4 => ['#1B3A63', '#FFFFFF'],
    ];

    /**
     * How many images this machine could not draw.
     *
     * Counted so the document can say so. An installation without ext-gd
     * produces a report with the signature block naming who signed and no mark
     * beside it, and a record that quietly loses the only witnessed thing on
     * it is worse than one that admits what is missing.
     */
    private int $undrawable = 0;

    public function __construct(
        private readonly ReportService $reports = new ReportService(),
        private readonly ?AttachmentService $attachments = null,
    ) {
    }

    /**
     * One assessment, as PDF bytes and the name to save them under.
     *
     * @throws InvalidArgumentException when there is no such assessment HERE —
     *                                  the same answer another organisation's
     *                                  id gets, on purpose
     * @return array{bytes:string,filename:string}
     */
    public function render(
        string $assessmentId,
        string $locale = 'en',
        bool $withPhotographs = true,
        string $variant = self::FULL,
    ): array {
        $locale = isset(self::STRINGS[$locale]) ? $locale : 'en';
        $variant = $variant === self::ACTIONS ? self::ACTIONS : self::FULL;

        // Assembled once. It is a dozen reads across answers, findings and
        // attachments, and the file needs both the document and the name off
        // the same copy.
        $report = $this->reports->report($assessmentId, $locale);

        return [
            'bytes'    => $this->pdf(
                $this->document($report, $locale, $withPhotographs, $variant),
                $locale,
            ),
            'filename' => $this->filename($report, $variant),
        ];
    }

    /**
     * The document before it is a file.
     *
     * Public because it is the only place the report's CONTENT can be checked.
     * What comes out of the renderer is glyph indexes into a subsetted font —
     * the words are not in there in any form a test can look for — so a suite
     * asserting on the PDF alone can only measure it, and a document that lost
     * all fifty-nine questions is still comfortably several kilobytes of font.
     */
    public function html(
        string $assessmentId,
        string $locale = 'en',
        bool $withPhotographs = true,
        string $variant = self::FULL,
    ): string {
        $locale = isset(self::STRINGS[$locale]) ? $locale : 'en';

        return $this->document(
            $this->reports->report($assessmentId, $locale),
            $locale,
            $withPhotographs,
            $variant,
        );
    }

    /**
     * The document, from a report already assembled.
     *
     * @param array<string,mixed> $report
     */
    private function document(
        array $report,
        string $locale,
        bool $withPhotographs,
        string $variant = self::FULL,
    ): string {
        $variant = $variant === self::ACTIONS ? self::ACTIONS : self::FULL;

        // Decided HERE and nowhere else. The short document draws no evidence,
        // so it must not pay to read any — and a condition spelled once at the
        // point the document is built cannot fall out of step with itself the
        // way the same condition spelled at each entrance can.
        $withPhotographs = $withPhotographs && $variant === self::FULL;

        $organisation = $this->organisation();

        $this->undrawable = 0;
        $withImages = $this->withImages($report, $withPhotographs);

        return $this->view([
            'report'       => $withImages,
            'undrawable'   => $this->undrawable,
            'variant'      => $variant,
            'organisation' => $organisation['name'],
            // Every stamp in the database is UTC. The organisation is where
            // the audit happened, and "started 08:00" is a claim about that
            // clock rather than about the server's.
            'timezone'     => $organisation['timezone'],
            'locale'       => $locale,
            'photographs'  => $withPhotographs,
        ]);
    }

    /**
     * Turn the assembled HTML into a file.
     *
     * Remote fetching stays OFF. The document is built from a database this
     * organisation owns, and an image tag is a URL somebody could get into a
     * caption — a renderer that dereferences one is a renderer making requests
     * from inside the network on a stranger's say-so. Everything drawn here is
     * already inline as a data URI.
     *
     * PHP-in-HTML stays off for the same reason, so the page numbers are
     * written onto the canvas afterwards rather than by a script inside the
     * document.
     */
    private function pdf(string $html, string $locale): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        // DejaVu covers every language the app ships and is the one font the
        // renderer carries, so nothing here depends on what the server has
        // installed.
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('defaultPaperSize', 'a4');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();

        // Written after the render because the total is not known until then,
        // and because the alternative is enabling PHP inside the document.
        // The catalogue is shared with the screen and speaks the screen's
        // placeholders, so they are translated into the renderer's here rather
        // than a second spelling of the same message being kept for this.
        $wording = str_replace(
            ['{page}', '{pages}'],
            ['{PAGE_NUM}', '{PAGE_COUNT}'],
            self::STRINGS[$locale]['report.page'] ?? 'Page {page} of {pages}',
        );

        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $size = 8;

        // Centred, which means measuring it — and measuring it means standing
        // in for the numbers, because they do not exist until the page they
        // are on is drawn.
        $sample = str_replace(['{PAGE_NUM}', '{PAGE_COUNT}'], ['00', '00'], $wording);
        $width = $canvas->get_text_width($sample, $font, $size);

        $canvas->page_text(
            ($canvas->get_width() - $width) / 2,
            $canvas->get_height() - 32,
            $wording,
            $font,
            $size,
            [0.4, 0.44, 0.5],
        );

        return $dompdf->output();
    }

    /**
     * What the browser saves it as.
     *
     * Named after the site and the day it was assessed, because a folder of
     * files called report.pdf is a folder nobody can search. Anything that is
     * not a letter, a digit or a dash goes, so the name survives every
     * filesystem it lands on.
     */
    /** @param array<string,mixed> $report */
    private function filename(array $report, string $variant = self::FULL): string
    {
        $assessment = is_array($report['assessment'] ?? null) ? $report['assessment'] : [];
        $site = is_array($assessment['site'] ?? null) ? $assessment['site'] : [];

        $name = (string) ($site['name'] ?? '');
        $name = (string) preg_replace('/[^A-Za-z0-9]+/', '-', $name);
        $name = trim($name, '-');

        $date = (string) ($assessment['assessed_on'] ?? '');

        // Named apart. The two documents are about the same visit on the same
        // day, and a folder holding both under one name is a folder where
        // somebody opens the wrong one.
        $parts = array_filter([
            'SPI-RDT',
            $name === '' ? 'report' : $name,
            $date,
            $variant === self::ACTIONS ? 'actions' : '',
        ]);

        return implode('-', $parts) . '.pdf';
    }

    /**
     * Read the pictures in, or leave them out.
     *
     * Signatures come in either way. They are a few kilobytes of line art and
     * they are the part that makes the document a record rather than a
     * printout — a report of a signed visit that does not show the signature
     * is missing the only thing on it that was witnessed.
     *
     * @param  array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function withImages(array $report, bool $withPhotographs): array
    {
        $signatures = is_array($report['signatures'] ?? null) ? $report['signatures'] : [];

        foreach ($signatures as $index => $signature) {
            $signatures[$index]['data'] = $this->dataUri((string) ($signature['id'] ?? ''));
        }

        $report['signatures'] = $signatures;

        if (!$withPhotographs) {
            // Dropped rather than emptied, so the view can still say how many
            // there were without having to draw them.
            $report['site_photographs'] = $this->countOnly($report['site_photographs'] ?? []);

            $sections = is_array($report['sections'] ?? null) ? $report['sections'] : [];

            foreach ($sections as $index => $section) {
                $sections[$index]['photographs'] = $this->countOnly($section['photographs'] ?? []);
            }

            $report['sections'] = $sections;

            return $report;
        }

        // One budget across the whole document rather than one per section, so
        // a visit that photographed everything in Section 2 does not push
        // Section 3 out entirely.
        $budget = self::PHOTOGRAPH_BUDGET_BYTES;

        $report['site_photographs'] = $this->embed($report['site_photographs'] ?? [], $budget);

        $sections = is_array($report['sections'] ?? null) ? $report['sections'] : [];

        foreach ($sections as $index => $section) {
            $sections[$index]['photographs'] = $this->embed($section['photographs'] ?? [], $budget);
        }

        $report['sections'] = $sections;

        return $report;
    }

    /**
     * Draw what the budget allows, and mark the rest as left out.
     *
     * @param  mixed                     $photographs
     * @param  int                       $budget      spent as it goes, across the whole document
     * @return list<array<string,mixed>>
     */
    private function embed(mixed $photographs, int &$budget): array
    {
        if (!is_array($photographs)) {
            return [];
        }

        $rows = [];

        foreach ($photographs as $photograph) {
            if (!is_array($photograph)) {
                continue;
            }

            // Nothing is even read once the budget is gone: the point of the
            // limit is the memory, and a ten-megabyte file weighs the same
            // whether or not it is drawn afterwards.
            $found = $budget <= 0 ? null : $this->bytes((string) ($photograph['id'] ?? ''));

            // Measured against what is left BEFORE it is spent. Subtracting
            // afterwards lets the picture that empties the budget be drawn in
            // full first, which on an installation storing uploads at the ten
            // megabytes the door allows is how a bounded document becomes an
            // unbounded one.
            if ($found === null || strlen($found['bytes']) > $budget) {
                $photograph['data'] = null;
                // A picture missing off the disk is not one the budget refused,
                // and the document should not say it was.
                $photograph['omitted'] = $budget <= 0 || $found !== null;
                $rows[] = $photograph;

                continue;
            }

            $budget -= strlen($found['bytes']);
            $photograph['data'] = 'data:' . $found['mime'] . ';base64,' . base64_encode($found['bytes']);
            $rows[] = $photograph;
        }

        return $rows;
    }

    /**
     * The same pictures with nothing in them.
     *
     * @param  mixed                     $photographs
     * @return list<array<string,mixed>>
     */
    private function countOnly(mixed $photographs): array
    {
        if (!is_array($photographs)) {
            return [];
        }

        $rows = [];

        foreach ($photographs as $photograph) {
            if (is_array($photograph)) {
                $photograph['data'] = null;
                $rows[] = $photograph;
            }
        }

        return $rows;
    }

    /**
     * One attachment as bytes the renderer can draw without leaving the
     * process.
     *
     * A picture that cannot be read is not an error: a file lost off the disk
     * should cost the report that photograph, not the whole document.
     */
    private function dataUri(string $attachmentId): ?string
    {
        $found = $this->bytes($attachmentId);

        if ($found === null) {
            return null;
        }

        return 'data:' . $found['mime'] . ';base64,' . base64_encode($found['bytes']);
    }

    /**
     * One attachment off the disk, if this machine can draw it.
     *
     * A JPEG goes into the file as the JPEG it already is. A PNG does not: the
     * renderer composites it, and compositing is ext-gd — which this
     * application does not require and `bin/preflight` only warns about.
     * Without the extension, handing it one turns a signed report into a 500,
     * which is a worse answer than a report whose signature block names who
     * signed and shows no mark.
     *
     * Signatures are the PNGs. They keep their transparency on the way in so
     * the ink composites onto the page rather than carrying a white rectangle
     * across it, and that is exactly the path that needs the extension.
     *
     * @return array{bytes:string,mime:string}|null
     */
    private function bytes(string $attachmentId): ?array
    {
        if ($attachmentId === '') {
            return null;
        }

        $found = $this->attachmentService()->read($attachmentId);

        if ($found === null) {
            return null;
        }

        if (str_contains($found['mime'], 'png') && !extension_loaded('gd')) {
            $this->undrawable++;

            return null;
        }

        return $found;
    }

    /**
     * The uploads live outside the document root, and the same path is spelled
     * where AttachmentAction serves one. Named here rather than injected so a
     * report can be rendered from a CLI process that wires nothing up.
     */
    private function attachmentService(): AttachmentService
    {
        return $this->attachments ?? new AttachmentService(dirname(__DIR__, 2) . '/var/uploads');
    }

    /**
     * Whose report this is, and what time it is where they are.
     *
     * @return array{name:string,timezone:string}
     */
    private function organisation(): array
    {
        $organization = Organization::query()
            ->where('id', TenantContext::requireOrganizationId())
            ->first();

        $timezone = $organization === null ? '' : (string) $organization->timezone;

        // Checked rather than trusted. The settings screen validates this, and
        // an organisation created through the administration API has been able
        // to hold whatever it was given — and a document that cannot be
        // downloaded at all is a worse answer to a bad timezone than one that
        // prints UTC.
        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }

        return [
            'name'     => $organization === null ? '' : (string) $organization->name,
            'timezone' => $timezone,
        ];
    }

    /**
     * Render the view file with the data in scope.
     *
     * A file rather than a heredoc because it is four hundred lines of markup,
     * and markup buried in a string is markup nobody will edit. It is included
     * inside a closure so the view cannot reach this object's internals — the
     * only things it sees are what is handed to it.
     *
     * @param array<string,mixed> $data
     */
    private function view(array $data): string
    {
        $data['strings'] = self::STRINGS[$data['locale']] ?? self::STRINGS['en'];
        $data['responseTones'] = self::RESPONSE_TONES;
        $data['levelTones'] = self::LEVEL_TONES;

        $render = static function (string $path, array $scope): string {
            extract($scope, EXTR_SKIP);
            ob_start();
            require $path;

            return (string) ob_get_clean();
        };

        return $render(dirname(__DIR__, 2) . '/resources/pdf/report.php', $data);
    }
}
