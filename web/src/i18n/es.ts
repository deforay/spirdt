import type { Messages } from './index'

/**
 * Spanish, addressing the assessor formally (usted), which is how a visiting
 * assessor is spoken to in every country this is likely to be used in.
 *
 * A first pass, unreviewed. See docs/i18n.md before this goes to a country.
 */

export const name = 'Español'

export const plural = (count: number): 'one' | 'other' => (count === 1 ? 'one' : 'other')

export const messages: Messages = {
    'action.add': 'Añadir',
    'action.remove': 'Quitar',
    'action.cancel': 'Cancelar',

    'signIn.subtitle': 'Inicie sesión para comenzar una evaluación.',
    'signIn.email': 'Correo',
    'signIn.password': 'Contraseña',
    'signIn.organization': 'Código org.',
    'signIn.submit': 'Iniciar sesión',
    'signIn.submitting': 'Iniciando sesión',
    'signIn.failed': 'No se pudo iniciar sesión.',

    'password.title': 'Elija una contraseña',
    'password.why':
        'Su cuenta usa una contraseña que definió otra persona. Sustitúyala antes de empezar.',
    'password.current': 'Actual',
    'password.new': 'Nueva',
    'password.confirm': 'Repetir',
    'password.save': 'Guardar',
    'password.saving': 'Guardando',
    'password.failed': 'No se pudo cambiar la contraseña.',
    'password.tooShort': 'Use al menos {count} caracteres.',
    'password.mismatch': 'Las dos no coinciden.',
    'password.signsOutOthers': 'Esto cierra la sesión en todos los demás dispositivos.',

    'sites.title': 'Sitios de prueba',
    'sites.subtitle': 'Elija el sitio que está evaluando.',
    'sites.search': 'Buscar',
    'sites.showAll': 'Mostrar todos los sitios ({count} más)',
    'sites.showMine': 'Mostrar solo mis sitios',
    'sites.assignedToColleague': 'Asignado a un colega',
    'sites.unassigned': 'Sin asignar',
    'sites.loading': 'Cargando sitios.',
    'sites.empty': 'Aún no hay sitios. Un administrador los añade, o cree algunos localmente con',

    'sync.blocked': 'Requiere atención',
    'sync.running': 'Sincronizando',
    'sync.pending': '{count} en espera',
    'sync.never': 'Sin sincronizar',
    'sync.synced': 'Sincronizado',

    'storage.notWritable':
        'Este dispositivo no está guardando nada. Desactive la navegación privada, o use otro navegador, antes de empezar.',
    'storage.mayClear':
        'Este navegador puede borrar las evaluaciones guardadas. Añada la aplicación a la pantalla de inicio, y sincronice antes de salir del sitio.',
    'storage.notPersisted':
        'Las evaluaciones guardadas no están protegidas contra el borrado. Sincronice antes de salir del sitio.',
    'storage.almostFull':
        'Este dispositivo casi no tiene espacio. Libere espacio antes de empezar.',
    'storage.saveFailed':
        'La última respuesta no se guardó en este dispositivo. No cierre esta página.',
    'storage.answerNotSaved': 'La respuesta no se guardó en este dispositivo.',

    'response.Y': 'Sí',
    'response.P': 'Parcial',
    'response.N': 'No',
    'response.NA': 'N/A',

    'question.guidance': 'Qué comprobar',
    'question.whyNotApplicable': '¿Por qué no aplica?',
    'question.describeGap': 'Describa la brecha',
    'question.noteRequired': 'Se requiere una nota antes de poder enviar.',
    'question.responseLabel': 'Respuesta a la pregunta {code}',
    'question.noteLabel': 'Nota para la pregunta {code}',

    'context.changesChecklist': 'Cambia la lista',
    'context.specify': 'Especifique',
    'context.add': 'Añadir {label}',

    'pathogens.empty': 'Añada cada prueba rápida realizada en este sitio.',
    'pathogens.placeholder': 'Añadir un patógeno',
    'pathogens.repeatNote': {
        one: 'La sección {number} se repite para cada uno. {count} pregunta.',
        other: 'La sección {number} se repite para cada uno. {count} preguntas.',
    },

    'setup.title': 'Preparar la visita',
    'setup.pathogensHeading': 'Pruebas realizadas aquí',
    'setup.contextHeading': 'Sobre el sitio',
    'setup.start': 'Comenzar la lista',
    'setup.needPathogen': 'Añada al menos una prueba realizada en este sitio.',
    'setup.missingFields': {
        one: '{count} campo obligatorio por completar.',
        other: '{count} campos obligatorios por completar.',
    },

    'checklist.loading': 'Cargando',
    'checklist.answered': '{answered} de {total} respondidas',
    'checklist.sections': 'Secciones',
    'checklist.pathogens': 'Patógenos',
    'checklist.sectionScore': 'Puntuación de la sección',
    'checklist.review': 'Revisar',

    'save.error': 'No guardado',
    'save.saving': 'Guardando',
    'save.nothing': 'Nada que guardar',
    'save.saved': 'Guardado a las {time}',

    'score.notScorable': 'No puntuable',
    'score.level': 'Nivel {level}',

    'review.back': 'Volver a la lista',
    'review.title': 'Revisión',
    'review.points': '{score} de {possible} puntos',
    'review.unanswered': 'Sin responder',
    'review.unansweredNote':
        'El porcentaje anterior solo cuenta las preguntas respondidas, por lo que es más alto que el de la evaluación terminada.',
    'review.gaps': 'Brechas',
    'review.described': '{described} de {total} descritas',
    'review.gapPlaceholder': 'Qué falta o no se está haciendo',
    'review.recommendationPlaceholder': 'Acción recomendada',
    'review.whoActs': 'Quién debe actuar',
    'review.responsiblePerson': 'Persona responsable',
    'review.noGaps': 'No se registraron brechas. Todas las respuestas hasta ahora son Sí o No aplica.',
    'review.bySection': 'Por sección',
    'review.notApplicable': 'No aplica',
    'review.submit': 'Enviar evaluación',
    'review.submitting': 'Enviando',
    'review.stillNeeded': {
        one: '{count} pregunta aún necesita respuesta.',
        other: '{count} preguntas aún necesitan respuesta.',
    },

    'signature.heading': 'Firmas',
    'signature.canvasLabel': 'Firme aquí',
    'signature.sign': 'Firmar',
    'signature.signAgain': 'Firmar de nuevo',
    'signature.undo': 'Deshacer trazo',
    'signature.clear': 'Borrar',
    'signature.done': 'Listo',
    'signature.unsigned': 'Sin firmar',
    'signature.signedAt': 'Firmado a las {time}',
    'signature.pending': 'Pendiente de envío',
    'signature.assessor': 'Evaluador',
    'signature.secondAssessor': 'Segundo evaluador',
    'signature.siteRepresentative': 'Representante del sitio',
    'signature.namePlaceholder': 'Nombre, si asistió un segundo evaluador',
    'signature.noName': 'Nombre no registrado',
    'signature.siteNameHint': 'Complete primero el nombre de la persona entrevistada en la parte A.',
    'signature.optional':
        'Las firmas no son obligatorias para enviar, y se suben por separado.',

    'submit.failed': 'No se pudo enviar la evaluación.',
    'submit.noAssessment': 'No hay ninguna evaluación para enviar.',
    'submit.invalidAnswers': 'Algunas respuestas no están permitidas para su pregunta.',

    'responsibility.site': 'Sitio',
    'responsibility.facility': 'Establecimiento',
    'responsibility.district': 'Distrito',
    'responsibility.regional': 'Regional',
    'responsibility.national': 'Nacional',

    'locale.label': 'Idioma',
    'locale.instrumentNote':
        'La lista en sí permanece en el idioma en que se publicó hasta que se traduzca el instrumento.',

    'admin.users': 'Personas',
    'admin.usersSubtitle': 'Quién puede iniciar sesión, y como qué.',
    'admin.signOut': 'Cerrar sesión',
    'admin.addUser': 'Añadir persona',
    'admin.fullName': 'Nombre completo',
    'admin.email': 'Correo electrónico',
    'admin.person': 'Persona',
    'admin.role': 'Rol',
    'admin.lastSignIn': 'Último acceso',
    'admin.actions': 'Acciones',
    'admin.never': 'Nunca',
    'admin.loading': 'Cargando.',
    'admin.loadFailed': 'No se pudo cargar la lista.',
    'admin.actionFailed': 'Eso no funcionó.',
    'admin.resetPassword': 'Restablecer contraseña',
    'admin.deactivate': 'Desactivar',
    'admin.activate': 'Activar',
    'admin.mustChangePassword': 'Debe elegir una contraseña nueva',
    'admin.passwordFor': 'Contraseña de {name}',
    'admin.passwordOnce': 'Se muestra una sola vez. Entréguela en persona, no por correo.',
    'admin.passwordNoted': 'Ya la he anotado',
    'role.admin': 'Administrador',
    'role.assessor': 'Evaluador',
    'role.viewer': 'Consulta',
    'role.site_user': 'Personal del sitio',
    'role.superadmin': 'Superadministrador',
}
