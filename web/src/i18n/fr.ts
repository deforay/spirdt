import type { Messages } from './index'

/**
 * French.
 *
 * A first pass, and it has not been read by a native speaker or by anyone who
 * runs these assessments in French. It is worded to be reviewed rather than
 * trusted: see docs/i18n.md before this goes to a country.
 */

export const name = 'Français'

/** French counts zero as singular: « 0 champ obligatoire », not « 0 champs ». */
export const plural = (count: number): 'one' | 'other' => (count < 2 ? 'one' : 'other')

export const messages: Messages = {
    'action.add': 'Ajouter',
    'action.remove': 'Retirer',

    'signIn.subtitle': 'Connectez-vous pour commencer une évaluation.',
    'signIn.email': 'E-mail',
    'signIn.password': 'Mot de passe',
    'signIn.organization': 'Code org.',
    'signIn.submit': 'Se connecter',
    'signIn.submitting': 'Connexion en cours',
    'signIn.failed': 'Connexion impossible.',

    'sites.title': 'Sites de test',
    'sites.subtitle': 'Choisissez le site que vous évaluez.',
    'sites.search': 'Rechercher',
    'sites.loading': 'Chargement des sites.',
    'sites.empty':
        "Aucun site pour l'instant. Un administrateur les ajoute, ou créez-en localement avec",

    'sync.blocked': 'Action requise',
    'sync.running': 'Synchronisation',
    'sync.pending': '{count} en attente',
    'sync.never': 'Non synchronisé',
    'sync.synced': 'Synchronisé',

    'storage.notWritable':
        "Cet appareil n'enregistre rien. Désactivez la navigation privée, ou utilisez un autre navigateur, avant de commencer.",
    'storage.mayClear':
        "Ce navigateur peut effacer les évaluations enregistrées. Ajoutez l'application à votre écran d'accueil, et synchronisez avant de quitter le site.",
    'storage.notPersisted':
        "Les évaluations enregistrées ne sont pas protégées contre l'effacement. Synchronisez avant de quitter le site.",
    'storage.almostFull':
        "Cet appareil n'a presque plus d'espace de stockage. Libérez de l'espace avant de commencer.",
    'storage.saveFailed':
        "La dernière réponse n'a pas été enregistrée sur cet appareil. Ne fermez pas cette page.",
    'storage.answerNotSaved': "La réponse n'a pas été enregistrée sur cet appareil.",

    'response.Y': 'Oui',
    'response.P': 'Partiel',
    'response.N': 'Non',
    'response.NA': 'S.O.',

    'question.guidance': "Ce qu'il faut vérifier",
    'question.whyNotApplicable': "Pourquoi cela ne s'applique-t-il pas ?",
    'question.describeGap': "Décrivez l'écart",
    'question.noteRequired': 'Une note est requise avant de pouvoir soumettre.',
    'question.responseLabel': 'Réponse à la question {code}',
    'question.noteLabel': 'Note pour la question {code}',

    'context.changesChecklist': 'Modifie la liste',
    'context.specify': 'Veuillez préciser',
    'context.add': 'Ajouter {label}',

    'pathogens.empty': 'Ajoutez chaque test rapide réalisé sur ce site.',
    'pathogens.placeholder': 'Ajouter un pathogène',
    'pathogens.repeatNote': {
        one: 'La section {number} se répète pour chacun. {count} question.',
        other: 'La section {number} se répète pour chacun. {count} questions.',
    },

    'setup.title': 'Préparer la visite',
    'setup.pathogensHeading': 'Tests réalisés ici',
    'setup.contextHeading': 'À propos du site',
    'setup.start': 'Commencer la liste',
    'setup.needPathogen': 'Ajoutez au moins un test réalisé sur ce site.',
    'setup.missingFields': {
        one: '{count} champ obligatoire reste à remplir.',
        other: '{count} champs obligatoires restent à remplir.',
    },

    'checklist.loading': 'Chargement',
    'checklist.answered': '{answered} sur {total} répondues',
    'checklist.sections': 'Sections',
    'checklist.pathogens': 'Pathogènes',
    'checklist.sectionScore': 'Score de la section',
    'checklist.review': 'Vérifier',

    'save.error': 'Non enregistré',
    'save.saving': 'Enregistrement',
    'save.nothing': 'Rien à enregistrer',
    'save.saved': 'Enregistré à {time}',

    'score.notScorable': 'Non calculable',
    'score.level': 'Niveau {level}',

    'review.back': 'Retour à la liste',
    'review.title': 'Vérification',
    'review.points': '{score} sur {possible} points',
    'review.unanswered': 'Sans réponse',
    'review.unansweredNote':
        "Le pourcentage ci-dessus ne compte que les questions répondues ; il est donc plus élevé que celui de l'évaluation terminée.",
    'review.gaps': 'Écarts',
    'review.described': '{described} sur {total} décrits',
    'review.gapPlaceholder': "Ce qui manque ou n'est pas fait",
    'review.recommendationPlaceholder': 'Action recommandée',
    'review.whoActs': 'Qui doit agir',
    'review.responsiblePerson': 'Personne responsable',
    'review.noGaps': 'Aucun écart enregistré. Toutes les réponses sont Oui ou Sans objet.',
    'review.bySection': 'Par section',
    'review.notApplicable': 'Sans objet',
    'review.submit': "Soumettre l'évaluation",
    'review.submitting': 'Soumission',
    'review.stillNeeded': {
        one: '{count} question attend encore une réponse.',
        other: '{count} questions attendent encore une réponse.',
    },

    'submit.failed': "L'évaluation n'a pas pu être soumise.",
    'submit.noAssessment': "Il n'y a aucune évaluation à soumettre.",
    'submit.invalidAnswers': 'Certaines réponses ne sont pas autorisées pour leur question.',

    'responsibility.site': 'Site',
    'responsibility.facility': 'Établissement',
    'responsibility.district': 'District',
    'responsibility.regional': 'Région',
    'responsibility.national': 'National',

    'locale.label': 'Langue',
    'locale.instrumentNote':
        "La liste elle-même reste dans sa langue de publication tant que l'instrument n'est pas traduit.",
}
