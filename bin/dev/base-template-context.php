<?php

declare(strict_types=1);

/**
 * Part A field definitions for the base template.
 *
 * Hand-authored rather than derived, unlike the scored sections: Part A in the
 * source document is a free-form layout table, not a question list, so there is
 * nothing regular to parse. It is also the part countries customise most — the
 * User's Guide says explicitly that levels "should be updated to reflect the
 * local context" and that date and time formats are agreed during
 * customisation — so it belongs in a file a human edits.
 *
 * Facility, site and address are deliberately ABSENT: they come from the
 * registry (facilities / testing_sites), not from re-typing on every visit.
 * Only the per-visit answers live here.
 *
 * Returned by require from bin/dev/build-base-template.
 */

$en = static fn (string $text): array => ['en' => $text];

return [
    [
        'code'     => 'assessment_date',
        'type'     => 'date',
        'label'    => $en('Date of assessment'),
        'required' => true,
    ],
    [
        'code'     => 'assessment_time',
        'type'     => 'time',
        'label'    => $en('Time of assessment'),
        'hint'     => $en('When the assessment started.'),
        'required' => false,
    ],
    [
        'code'     => 'previous_assessment_date',
        'type'     => 'date',
        'label'    => $en('Date of previous assessment'),
        'hint'     => $en('Leave blank for a new site with no previous assessment.'),
        'required' => false,
    ],
    [
        'code'     => 'poc_site_count',
        'type'     => 'integer',
        'label'    => $en('Number of POC testing sites within the same facility'),
        'hint'     => $en('Contextual and planning information only, including sites not assessed during this visit. It does not imply a facility-level assessment.'),
        'required' => false,
    ],
    [
        'code'     => 'poc_tests_list',
        'type'     => 'textarea',
        'label'    => $en('Point of care tests conducted at this testing site'),
        'hint'     => $en('All rapid diagnostic tests in use, including any not assessed today.'),
        'required' => false,
    ],
    [
        'code'     => 'facility_type',
        'type'     => 'select_one',
        'label'    => $en('Type of facility'),
        'hint'     => $en('Select the single most applicable.'),
        'required' => true,
        'options'  => [
            ['key' => 'health_center',      'label' => $en('Health Centre')],
            ['key' => 'mobile_clinic',      'label' => $en('Mobile Clinic')],
            ['key' => 'laboratory',         'label' => $en('Laboratory'),          'specify' => true],
            ['key' => 'specialized_clinic', 'label' => $en('Specialised Clinic'),  'specify' => true],
            ['key' => 'hospital_bedside',   'label' => $en('Hospital Bedside')],
            ['key' => 'other',              'label' => $en('Other'),               'specify' => true],
        ],
    ],
    [
        // The tiered LABORATORY network position, not a geographic scope.
        // Countries relabel these during customisation.
        'code'     => 'level',
        'type'     => 'select_one',
        'label'    => $en('Level'),
        'hint'     => $en('Position within the tiered health network. Confirm with the supervisor.'),
        'required' => true,
        'options'  => [
            ['key' => 'region',        'label' => $en('Region, province or zone')],
            ['key' => 'district',      'label' => $en('District')],
            ['key' => 'health_center', 'label' => $en('Health Centre')],
            ['key' => 'other',         'label' => $en('Other'), 'specify' => true],
        ],
    ],
    [
        'code'     => 'affiliation',
        'type'     => 'select_one',
        'label'    => $en('Affiliation'),
        'required' => true,
        'options'  => [
            ['key' => 'government', 'label' => $en('Government')],
            ['key' => 'private',    'label' => $en('Private')],
            ['key' => 'faith_based', 'label' => $en('Faith-based organisation')],
            ['key' => 'ngo',        'label' => $en('Non-governmental organisation')],
            ['key' => 'other',      'label' => $en('Other'), 'specify' => true],
        ],
    ],
    [
        'code'     => 'refers_specimens',
        'type'     => 'select_one',
        'label'    => $en('Does this testing site refer specimens for further testing?'),
        'hint'     => $en('Answering no marks Section 5 not applicable in full — it then contributes nothing to the score or the possible total.'),
        'required' => true,
        'options'  => [
            ['key' => 'yes', 'label' => $en('Yes')],
            ['key' => 'no',  'label' => $en('No')],
        ],
    ],
    [
        'code'     => 'testing_staff',
        'type'     => 'repeat',
        'label'    => $en('Staff performing point of care testing'),
        'hint'     => $en('Full name and job title of every tester at this site.'),
        'required' => false,
        'fields'   => [
            ['code' => 'name',  'type' => 'text', 'label' => $en('Name of staff'),  'required' => true],
            ['code' => 'title', 'type' => 'text', 'label' => $en('Title of staff'), 'required' => false],
        ],
    ],
    [
        'code'     => 'interviewee_name',
        'type'     => 'text',
        'label'    => $en('Name of interviewee'),
        'hint'     => $en('Ideally the tester rather than the supervisor.'),
        'required' => true,
    ],
    [
        'code'     => 'interviewee_title',
        'type'     => 'text',
        'label'    => $en('Title of interviewee'),
        'required' => false,
    ],
    [
        'code'     => 'interviewee_phone',
        'type'     => 'text',
        'label'    => $en('Phone number of interviewee'),
        'required' => false,
    ],
];
