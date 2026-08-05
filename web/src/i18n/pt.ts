import type { Messages } from './index'

/**
 * Portuguese, in the European spelling used across Lusophone Africa —
 * "palavra-passe" rather than "senha", "a carregar" rather than "carregando".
 *
 * A first pass, unreviewed. See docs/i18n.md before this goes to a country.
 */

export const name = 'Português'

export const plural = (count: number): 'one' | 'other' => (count === 1 ? 'one' : 'other')

export const messages: Messages = {
    'action.add': 'Adicionar',
    'action.remove': 'Remover',
    'action.cancel': 'Cancelar',

    'signIn.subtitle': 'Inicie sessão para começar uma avaliação.',
    'signIn.email': 'E-mail',
    'signIn.password': 'Palavra-passe',
    'signIn.organization': 'Código org.',
    'signIn.submit': 'Iniciar sessão',
    'signIn.submitting': 'A iniciar sessão',
    'signIn.failed': 'Não foi possível iniciar sessão.',

    'password.title': 'Escolha uma palavra-passe',
    'password.why':
        'A sua conta usa uma palavra-passe definida por outra pessoa. Substitua-a antes de começar.',
    'password.current': 'Atual',
    'password.new': 'Nova',
    'password.confirm': 'Repetir',
    'password.save': 'Guardar',
    'password.saving': 'A guardar',
    'password.failed': 'Não foi possível alterar a palavra-passe.',
    'password.tooShort': 'Use pelo menos {count} caracteres.',
    'password.mismatch': 'As duas não coincidem.',
    'password.signsOutOthers': 'Isto termina a sessão em todos os outros dispositivos.',

    'sites.title': 'Locais de teste',
    'sites.subtitle': 'Escolha o local que está a avaliar.',
    'sites.search': 'Pesquisar',
    'sites.showAll': 'Mostrar todos os locais (mais {count})',
    'sites.showMine': 'Mostrar apenas os meus locais',
    'sites.assignedToColleague': 'Atribuído a um colega',
    'sites.unassigned': 'Não atribuído',
    'sites.loading': 'A carregar locais.',
    'sites.empty': 'Ainda não há locais. Um administrador adiciona-os, ou crie alguns localmente com',

    'sync.blocked': 'Requer atenção',
    'sync.running': 'A sincronizar',
    'sync.pending': '{count} em espera',
    'sync.never': 'Não sincronizado',
    'sync.synced': 'Sincronizado',

    'storage.notWritable':
        'Este dispositivo não está a guardar nada. Desative a navegação privada, ou use outro navegador, antes de começar.',
    'storage.mayClear':
        'Este navegador pode apagar as avaliações guardadas. Adicione a aplicação ao ecrã principal, e sincronize antes de sair do local.',
    'storage.notPersisted':
        'As avaliações guardadas não estão protegidas contra a eliminação. Sincronize antes de sair do local.',
    'storage.almostFull':
        'Este dispositivo está quase sem espaço. Liberte espaço antes de começar.',
    'storage.saveFailed':
        'A última resposta não foi guardada neste dispositivo. Não feche esta página.',
    'storage.answerNotSaved': 'A resposta não foi guardada neste dispositivo.',

    'response.Y': 'Sim',
    'response.P': 'Parcial',
    'response.N': 'Não',
    'response.NA': 'N/A',

    'question.guidance': 'O que verificar',
    'question.whyNotApplicable': 'Porque é que não se aplica?',
    'question.describeGap': 'Descreva a lacuna',
    'question.noteRequired': 'É necessária uma nota antes de poder submeter.',
    'question.responseLabel': 'Resposta à pergunta {code}',
    'question.noteLabel': 'Nota para a pergunta {code}',

    'context.changesChecklist': 'Altera a lista',
    'context.specify': 'Especifique',
    'context.add': 'Adicionar {label}',

    'pathogens.empty': 'Adicione todos os testes rápidos realizados neste local.',
    'pathogens.placeholder': 'Adicionar um agente patogénico',
    'pathogens.repeatNote': {
        one: 'A secção {number} repete-se para cada um. {count} pergunta.',
        other: 'A secção {number} repete-se para cada um. {count} perguntas.',
    },

    'setup.title': 'Preparar a visita',
    'setup.pathogensHeading': 'Testes realizados aqui',
    'setup.contextHeading': 'Sobre o local',
    'setup.start': 'Começar a lista',
    'setup.needPathogen': 'Adicione pelo menos um teste realizado neste local.',
    'setup.missingFields': {
        one: '{count} campo obrigatório por preencher.',
        other: '{count} campos obrigatórios por preencher.',
    },

    'checklist.loading': 'A carregar',
    'checklist.answered': '{answered} de {total} respondidas',
    'checklist.sections': 'Secções',
    'checklist.pathogens': 'Agentes patogénicos',
    'checklist.sectionScore': 'Pontuação da secção',
    'checklist.review': 'Rever',

    'save.error': 'Não guardado',
    'save.saving': 'A guardar',
    'save.nothing': 'Nada para guardar',
    'save.saved': 'Guardado às {time}',

    'score.notScorable': 'Não pontuável',
    'score.level': 'Nível {level}',

    'review.back': 'Voltar à lista',
    'review.title': 'Revisão',
    'review.points': '{score} de {possible} pontos',
    'review.unanswered': 'Sem resposta',
    'review.unansweredNote':
        'A percentagem acima conta apenas as perguntas respondidas, pelo que é mais alta do que a da avaliação terminada.',
    'review.gaps': 'Lacunas',
    'review.described': '{described} de {total} descritas',
    'review.gapPlaceholder': 'O que falta ou não está a ser feito',
    'review.recommendationPlaceholder': 'Ação recomendada',
    'review.whoActs': 'Quem deve agir',
    'review.responsiblePerson': 'Pessoa responsável',
    'review.noGaps':
        'Nenhuma lacuna registada. Todas as respostas até agora são Sim ou Não aplicável.',
    'review.bySection': 'Por secção',
    'review.notApplicable': 'Não aplicável',
    'review.submit': 'Submeter avaliação',
    'review.submitting': 'A submeter',
    'review.stillNeeded': {
        one: '{count} pergunta ainda precisa de resposta.',
        other: '{count} perguntas ainda precisam de resposta.',
    },

    'signature.heading': 'Assinaturas',
    'signature.canvasLabel': 'Assine aqui',
    'signature.sign': 'Assinar',
    'signature.signAgain': 'Assinar novamente',
    'signature.undo': 'Anular traço',
    'signature.clear': 'Limpar',
    'signature.done': 'Concluído',
    'signature.unsigned': 'Não assinado',
    'signature.signedAt': 'Assinado às {time}',
    'signature.pending': 'A aguardar envio',
    'signature.assessor': 'Avaliador',
    'signature.secondAssessor': 'Segundo avaliador',
    'signature.siteRepresentative': 'Representante do local',
    'signature.namePlaceholder': 'Nome, se um segundo avaliador esteve presente',
    'signature.noName': 'Nome não registado',
    'signature.siteNameHint': 'Preencha primeiro o nome do entrevistado na parte A.',
    'signature.optional':
        'As assinaturas não são obrigatórias para submeter, e são enviadas em separado.',

    'submit.failed': 'Não foi possível submeter a avaliação.',
    'submit.noAssessment': 'Não há nenhuma avaliação para submeter.',
    'submit.invalidAnswers': 'Algumas respostas não são permitidas para a sua pergunta.',

    'responsibility.site': 'Local',
    'responsibility.facility': 'Unidade sanitária',
    'responsibility.district': 'Distrito',
    'responsibility.regional': 'Regional',
    'responsibility.national': 'Nacional',

    'locale.label': 'Idioma',
    'locale.instrumentNote':
        'A lista em si permanece no idioma em que foi publicada até o instrumento ser traduzido.',

    'admin.users': 'Pessoas',
    'admin.usersSubtitle': 'Quem pode iniciar sessão, e como.',
    'admin.signOut': 'Terminar sessão',
    'admin.addUser': 'Adicionar pessoa',
    'admin.fullName': 'Nome completo',
    'admin.email': 'Endereço de e-mail',
    'admin.person': 'Pessoa',
    'admin.role': 'Função',
    'admin.lastSignIn': 'Última sessão',
    'admin.actions': 'Ações',
    'admin.never': 'Nunca',
    'admin.loading': 'A carregar.',
    'admin.loadFailed': 'Não foi possível carregar a lista.',
    'admin.actionFailed': 'Isso não funcionou.',
    'admin.resetPassword': 'Repor palavra-passe',
    'admin.deactivate': 'Desativar',
    'admin.activate': 'Ativar',
    'admin.mustChangePassword': 'Tem de escolher uma nova palavra-passe',
    'admin.passwordFor': 'Palavra-passe de {name}',
    'admin.passwordOnce': 'Mostrada uma só vez. Entregue-a pessoalmente, não por e-mail.',
    'admin.passwordNoted': 'Já a anotei',
    'role.admin': 'Administrador',
    'role.assessor': 'Avaliador',
    'role.viewer': 'Consulta',
    'role.site_user': 'Pessoal do local',
    'role.superadmin': 'Superadministrador',
}
