/**
 * English — the source catalogue.
 *
 * Every other language is typed against this one, so a key added here and
 * forgotten elsewhere fails the build rather than showing up blank on a
 * tablet in the field.
 *
 * Two things are deliberately not in here. Question text, section titles and
 * response criteria live in the instrument template, because they are the
 * document being applied and change with its version. Server error messages
 * are returned already-worded by the API; translating those means giving the
 * API a locale, which is a separate piece of work.
 */

export const name = 'English'

/** English, Portuguese and Spanish all break at exactly one. */
export const plural = (count: number): 'one' | 'other' => (count === 1 ? 'one' : 'other')

export const messages = {
    'action.add': 'Add',
    'action.remove': 'Remove',
    'action.cancel': 'Cancel',

    'signIn.subtitle': 'Sign in to start an assessment.',
    'signIn.email': 'Email',
    'signIn.password': 'Password',
    'signIn.organization': 'Org code',
    'signIn.submit': 'Sign in',
    'signIn.submitting': 'Signing in',
    'signIn.failed': 'Could not sign in.',

    'password.title': 'Choose a password',
    'password.why': 'Your account is using a password somebody else set. Replace it before you start.',
    'password.current': 'Current',
    'password.new': 'New',
    'password.confirm': 'Repeat',
    'password.save': 'Save password',
    'password.saving': 'Saving',
    'password.failed': 'The password could not be changed.',
    'password.tooShort': 'Use at least {count} characters.',
    'password.mismatch': 'The two do not match.',
    'password.signsOutOthers': 'This signs you out on every other device.',

    'sites.title': 'Testing sites',
    'sites.subtitle': 'Choose the site you are assessing.',
    'sites.search': 'Search',
    'sites.showAll': 'Show all sites ({count} more)',
    'sites.showMine': 'Show only my sites',
    'sites.assignedToColleague': 'Assigned to a colleague',
    'sites.unassigned': 'Not assigned',
    'sites.loading': 'Loading sites.',
    'sites.empty': 'No sites yet. An administrator adds them, or seed some locally with',

    'sync.blocked': 'Needs attention',
    'sync.running': 'Syncing',
    'sync.pending': '{count} waiting',
    'sync.never': 'Not synced',
    'sync.synced': 'Synced',

    'storage.notWritable':
        'This device is not saving anything. Turn off private browsing, or use a different browser, before you start.',
    'storage.mayClear':
        'This browser may clear saved assessments. Add the app to your home screen, and sync before you leave the site.',
    'storage.notPersisted':
        'Saved assessments are not protected from being cleared. Sync before you leave the site.',
    'storage.almostFull': 'This device is almost out of storage. Free up space before you start.',
    'storage.saveFailed':
        'The last answer was not saved to this device. Do not close this page.',
    'storage.answerNotSaved': 'The answer was not saved to this device.',

    'response.Y': 'Yes',
    'response.P': 'Partial',
    'response.N': 'No',
    'response.NA': 'N/A',

    'question.guidance': 'What to look for',
    'question.whyNotApplicable': 'Why does this not apply?',
    'question.describeGap': 'Describe the gap',
    'question.noteRequired': 'A note is required before you can submit.',
    'question.responseLabel': 'Response to question {code}',
    'question.noteLabel': 'Note for question {code}',

    'context.changesChecklist': 'Changes the checklist',
    'context.specify': 'Please specify',
    'context.add': 'Add {label}',

    'pathogens.empty': 'Add every rapid test performed at this site.',
    'pathogens.placeholder': 'Add a pathogen',
    'pathogens.repeatNote': {
        one: 'Section {number} repeats for each one. {count} question.',
        other: 'Section {number} repeats for each one. {count} questions.',
    },

    'setup.title': 'Set up the visit',
    'setup.pathogensHeading': 'Tests performed here',
    'setup.contextHeading': 'About the site',
    'setup.start': 'Start the checklist',
    'setup.needPathogen': 'Add at least one test performed at this site.',
    'setup.missingFields': {
        one: '{count} required field still to fill in.',
        other: '{count} required fields still to fill in.',
    },

    'checklist.loading': 'Loading',
    'checklist.answered': '{answered} of {total} answered',
    'checklist.sections': 'Sections',
    'checklist.pathogens': 'Pathogens',
    'checklist.sectionScore': 'Section score',
    'checklist.review': 'Review',

    'save.error': 'Not saved',
    'save.saving': 'Saving',
    'save.nothing': 'Nothing to save yet',
    'save.saved': 'Saved {time}',

    'score.notScorable': 'Not scorable',
    'score.level': 'Level {level}',

    'review.back': 'Back to checklist',
    'review.title': 'Review',
    'review.points': '{score} of {possible} points',
    'review.unanswered': 'Unanswered',
    'review.unansweredNote':
        'The percentage above counts only answered questions, so it reads higher than the finished assessment will.',
    'review.gaps': 'Gaps',
    'review.described': '{described} of {total} described',
    'review.gapPlaceholder': 'What is missing or not being done',
    'review.recommendationPlaceholder': 'Recommended action',
    'review.whoActs': 'Who acts on this',
    'review.responsiblePerson': 'Responsible person',
    'review.noGaps': 'No gaps recorded. Every answer so far is a Yes or Not applicable.',
    'review.bySection': 'By section',
    'review.notApplicable': 'Not applicable',
    'review.submit': 'Submit assessment',
    'review.submitting': 'Submitting',
    'review.stillNeeded': {
        one: '{count} question still needs an answer.',
        other: '{count} questions still need an answer.',
    },

    'signature.heading': 'Signatures',
    'signature.canvasLabel': 'Sign here',
    'signature.sign': 'Sign',
    'signature.signAgain': 'Sign again',
    'signature.undo': 'Undo',
    'signature.clear': 'Clear',
    'signature.done': 'Done',
    'signature.unsigned': 'Not signed',
    'signature.signedAt': 'Signed {time}',
    'signature.pending': 'Waiting to upload',
    'signature.assessor': 'Assessor',
    'signature.secondAssessor': 'Second assessor',
    'signature.siteRepresentative': 'Site representative',
    'signature.namePlaceholder': 'Name, if a second assessor attended',
    'signature.noName': 'Name not recorded',
    'signature.siteNameHint': 'Fill in the interviewee’s name in Part A first.',
    'signature.optional': 'Signatures are not required to submit, and upload separately.',

    'submit.failed': 'The assessment could not be submitted.',
    'submit.noAssessment': 'There is no assessment to submit.',
    'submit.invalidAnswers': 'Some answers are not allowed for their question.',

    'responsibility.site': 'Site',
    'responsibility.facility': 'Facility',
    'responsibility.district': 'District',
    'responsibility.regional': 'Regional',
    'responsibility.national': 'National',

    'locale.label': 'Language',
    'locale.instrumentNote':
        'The checklist itself stays in its published language until the instrument is translated.',
}
