import { createI18n } from 'vue-i18n';
import home from './locales/home.js';

const messages = {
    ru: {
        notfound: {
            title: 'Страница не найдена',
            back: 'На главную',
        },

        header: {
            home: 'Главная',
            terms: 'Условия',
            privacy: 'Конфиденциальность',
            startBtn: 'Начать',
            sources: 'Источники',
            dashboards: 'Дашборды',
            alerts: 'Алерты',
            login: 'Войти',
        },

        footer: {
            rights: 'Все права защищены.',
            ai_consent: 'ИИ и данные',
            tagline: 'ИИ-аналитика: дашборды и алерты',
        },

        home: home.ru,

        auth: {
            page_login: 'Вход',
            side_title: 'Данные в дашборды и алерты',
            side_point1: 'Любые источники данных',
            side_point2: 'ИИ-чат или конструктор',
            side_point3: 'Алерты на почту',
            page_register: 'Регистрация',

            input_company_name: 'Название компании',
            input_name: 'Имя',
            input_email: 'Адрес электронной почты',
            input_password: 'Пароль',
            input_confirm_password: 'Подтвердите пароль',

            already_have_account: 'Уже есть аккаунт?',
            no_account: 'У вас ещё нет аккаунта?',

            ai_consent_checkbox: 'Я ознакомлен(а) и согласен(а) с условиями обработки данных ИИ',
            ai_consent_link: 'Подробнее',
            ai_consent_required: 'Необходимо принять условия обработки данных ИИ',

            terms_consent_prefix: 'Я принимаю',
            terms_consent_and: 'и',
            terms_consent_suffix: '',
            terms_link: 'Пользовательское соглашение',
            privacy_link: 'Политику конфиденциальности',
            terms_consent_required: 'Необходимо принять Пользовательское соглашение и Политику конфиденциальности',

            marketing_consent_checkbox: 'Хочу получать новости и полезные материалы о Datavue на почту',
        },

        consent: {
            page_title: 'Согласие на обработку данных с использованием ИИ',
            section1_title: 'Что мы передаём ИИ',
            section1_text: 'При подключении источника данных Datavue отправляет внешнему ИИ-сервису (OpenAI) только структуру данных: названия таблиц и столбцов, их типы и связи между ними — то, что нужно, чтобы спроектировать дашборд и написать код для расчёта показателей. Содержимое самих данных — значения строк, конкретные записи — ИИ не передаётся и не покидает источник данных: все расчёты по сгенерированному коду выполняются на стороне Datavue, напрямую по вашей базе данных или файлу.',
            section2_title: 'ИИ может ошибаться, но это можно проверить',
            section2_text: 'Дашборды и SQL/Python-код для расчёта показателей ИИ создаёт автоматически. Как и любая ИИ-система, он может допустить ошибку — неверно понять задачу или неправильно составить запрос. Чтобы не полагаться на непроверенную цифру, каждый виджет дашборда можно открыть в режиме «Конструктор» и увидеть точный код или SQL-запрос, по которому он посчитан. Если сотрудник сомневается в показателе, он всегда может посмотреть, откуда взялось число, и проверить логику расчёта самостоятельно.',
            footer_note: 'Регистрируясь в Datavue, вы подтверждаете, что ознакомлены с этими условиями и согласны с ними.',
        },

        terms: {
            page_title: 'Пользовательское соглашение',
            intro: 'Регистрируясь в Datavue, вы (представитель компании) заключаете это соглашение об использовании сервиса. Ниже — основные условия в понятном виде.',
            point1_title: 'Что такое Datavue',
            point1_text: 'Datavue — сервис автоматической генерации BI-дашбордов: вы подключаете источник данных (файл или базу данных), а ИИ проектирует виджеты и пишет код для расчёта показателей.',
            point2_title: 'Полномочия при регистрации',
            point2_text: 'Регистрируясь от имени компании, вы подтверждаете, что уполномочены представлять её и заключать это соглашение — именно вы становитесь владельцем создаваемого аккаунта компании.',
            point3_title: 'Ответственность за загружаемые данные',
            point3_text: 'Вы подключаете к Datavue собственные файлы и базы данных. Вы обязаны иметь законное право на их обработку — в том числе если они содержат персональные данные ваших клиентов или сотрудников. Datavue обрабатывает эти данные технически, по вашему поручению, и не проверяет их законность.',
            point4_title: 'Ограничение ответственности за ИИ',
            point4_text: 'Дашборды и код для расчёта показателей ИИ создаёт автоматически и может ошибаться (подробнее — в Согласии на обработку данных с ИИ). Datavue не несёт ответственности за управленческие решения, принятые на основании непроверенных показателей.',
            point5_title: 'Изменение и прекращение доступа',
            point5_text: 'Мы вправе приостановить или заблокировать аккаунт при нарушении условий сервиса. Вы можете прекратить использование сервиса и удалить аккаунт в любой момент.',
            footer_note: 'Продолжая регистрацию, вы подтверждаете, что прочитали и принимаете эти условия.',
        },

        privacy: {
            page_title: 'Политика конфиденциальности',
            intro: 'Эта страница объясняет, какие данные Datavue собирает о вас при регистрации и использовании сервиса и как их обрабатывает.',
            point1_title: 'Какие данные мы собираем',
            point1_text: 'При регистрации: имя, email, название компании и пароль (хранится в виде хэша, не в открытом виде). В процессе работы — данные об использовании сервиса, например история чата с ИИ-агентом.',
            point2_title: 'Для чего мы их используем',
            point2_text: 'Чтобы создать и обслуживать вашу учётную запись, обеспечить работу сервиса, связаться с вами по вопросам аккаунта и, если вы дали отдельное согласие, — присылать новости о продукте.',
            point3_title: 'Кому передаются данные',
            point3_text: 'Персональные данные, указанные при регистрации, третьим лицам не передаются, кроме случаев, предусмотренных законом. При генерации дашбордов структура (схема) ваших бизнес-данных передаётся ИИ-провайдеру (OpenAI) — подробнее в Согласии на обработку данных с ИИ; сами данные ему не передаются.',
            point4_title: 'Срок хранения',
            point4_text: 'Данные хранятся, пока ваш аккаунт активен. После удаления аккаунта данные удаляются, за исключением случаев, когда более долгий срок хранения требуется по закону.',
            point5_title: 'Ваши права',
            point5_text: 'Вы можете запросить доступ к своим данным, их исправление или удаление, написав в поддержку.',
            footer_note: 'Продолжая регистрацию, вы подтверждаете, что ознакомлены с этой политикой.',
        }
    },

    en: {
        notfound: {
            title: 'Page not found',
            back: 'Back to home',
        },

        header: {
            home: 'Home',
            terms: 'Terms',
            privacy: 'Privacy',
            startBtn: 'Get Started',
            sources: 'Sources',
            dashboards: 'Dashboards',
            alerts: 'Alerts',
            login: 'Sign in',
        },

        footer: {
            rights: 'All rights reserved.',
            ai_consent: 'AI and data',
            tagline: 'AI analytics: dashboards and alerts',
        },

        home: home.en,

        auth: {
            page_login: 'Login',
            side_title: 'Data into dashboards and alerts',
            side_point1: 'Any data source',
            side_point2: 'AI chat or builder',
            side_point3: 'Alerts by email',
            page_register: 'Sign Up',

            input_company_name: 'Company name',
            input_name: 'Name',
            input_email: 'Email address',
            input_password: 'Password',
            input_confirm_password: 'Confirm Password',

            already_have_account: 'Already have an account?',
            no_account: "Don't have an account yet?",

            ai_consent_checkbox: 'I have read and agree to the AI data processing terms',
            ai_consent_link: 'Learn more',
            ai_consent_required: 'You must accept the AI data processing terms',

            terms_consent_prefix: 'I accept the',
            terms_consent_and: 'and',
            terms_consent_suffix: '',
            terms_link: 'Terms of Service',
            privacy_link: 'Privacy Policy',
            terms_consent_required: 'You must accept the Terms of Service and Privacy Policy',

            marketing_consent_checkbox: 'I want to receive news and updates about Datavue by email',
        },

        consent: {
            page_title: 'AI Data Processing Consent',
            section1_title: 'What we send to the AI',
            section1_text: 'When you connect a data source, Datavue sends the external AI service (OpenAI) only the data structure: table and column names, their types, and the relationships between them — what is needed to design a dashboard and write the code that calculates the metrics. The actual data itself — row values, specific records — is never sent to the AI and never leaves your data source: all calculations from the generated code run on Datavue\'s side, directly against your database or file.',
            section2_title: 'The AI can make mistakes, but you can check',
            section2_text: 'Dashboards and the SQL/Python code used to calculate metrics are generated automatically by AI. Like any AI system, it can make a mistake — misunderstand the task or write an incorrect query. So you never have to rely on an unchecked number, every widget can be opened in Constructor mode to see the exact code or SQL query used to calculate it. If an employee is unsure about a figure, they can always see where the number came from and verify the calculation themselves.',
            footer_note: 'By registering with Datavue, you confirm that you have read and agree to these terms.',
        },

        terms: {
            page_title: 'Terms of Service',
            intro: 'By registering with Datavue, you (as a company representative) enter into this agreement to use the service. Below are the key terms in plain language.',
            point1_title: 'What Datavue is',
            point1_text: 'Datavue is a service for automatic BI dashboard generation: you connect a data source (a file or a database), and the AI designs the widgets and writes the code that calculates the metrics.',
            point2_title: 'Authority at registration',
            point2_text: 'By registering on behalf of a company, you confirm that you are authorized to represent it and to enter into this agreement — you become the owner of the company account being created.',
            point3_title: 'Responsibility for uploaded data',
            point3_text: 'You connect your own files and databases to Datavue. You must have the legal right to process them — including if they contain personal data of your customers or employees. Datavue processes this data technically, on your instructions, and does not verify its legality.',
            point4_title: 'Limitation of liability for AI',
            point4_text: 'Dashboards and the code used to calculate metrics are generated automatically by AI and can contain mistakes (see the AI Data Processing Consent for details). Datavue is not liable for management decisions made based on unverified figures.',
            point5_title: 'Changes and termination of access',
            point5_text: 'We may suspend or block an account for violating the terms of service. You may stop using the service and delete your account at any time.',
            footer_note: 'By continuing registration, you confirm that you have read and accept these terms.',
        },

        privacy: {
            page_title: 'Privacy Policy',
            intro: 'This page explains what data Datavue collects about you when you register and use the service, and how it is processed.',
            point1_title: 'What data we collect',
            point1_text: 'At registration: name, email, company name, and password (stored as a hash, never in plain text). While using the service — usage data, for example chat history with the AI agent.',
            point2_title: 'Why we use it',
            point2_text: 'To create and maintain your account, operate the service, contact you about your account and, if you gave separate consent, send you product updates.',
            point3_title: 'Who we share data with',
            point3_text: 'Personal data provided at registration is not shared with third parties, except as required by law. When generating dashboards, the structure (schema) of your business data is sent to the AI provider (OpenAI) — see the AI Data Processing Consent for details; the data itself is never sent to it.',
            point4_title: 'Retention period',
            point4_text: 'Data is stored while your account is active. After account deletion, data is removed, except where a longer retention period is required by law.',
            point5_title: 'Your rights',
            point5_text: 'You can request access to your data, its correction, or deletion by contacting support.',
            footer_note: 'By continuing registration, you confirm that you have read this policy.',
        }
    },

    tj: {
        notfound: {
            title: 'Саҳифа ёфт нашуд',
            back: 'Ба саҳифаи асосӣ',
        },

        header: {
            home: 'Асосӣ',
            terms: 'Шартҳо',
            privacy: 'Махфият',
            startBtn: 'Оғоз кардан',
            sources: 'Манбаъҳо',
            dashboards: 'Дашбордҳо',
            alerts: 'Огоҳиномаҳо',
            login: 'Даромадан',
        },

        footer: {
            rights: 'Ҳамаи ҳуқуқҳо ҳифз шудаанд.',
            ai_consent: 'ИИ ва маълумот',
            tagline: 'Таҳлили ИИ: дашбордҳо ва огоҳиномаҳо',
        },

        home: home.tj,

        auth: {
            page_login: 'Воридшавӣ',
            side_title: 'Маълумот ба дашборд ва огоҳинома',
            side_point1: 'Ҳар манбаи маълумот',
            side_point2: 'Чати ИИ ё конструктор',
            side_point3: 'Огоҳиномаҳо ба почта',
            page_register: 'Бақайдгирӣ',

            input_company_name: 'Номи ширкат',
            input_name: 'Ном',
            input_email: 'Суроғаи почтаи электронӣ',
            input_password: 'Парол',
            input_confirm_password: 'Паролро тасдиқ кунед',

            already_have_account: 'Аллакай ҳисоб доред?',
            no_account: 'Ҳанӯз ҳисоб надоред?',

            ai_consent_checkbox: 'Ман бо шартҳои коркарди маълумот бо ИИ шинос шудам ва розӣ ҳастам',
            ai_consent_link: 'Муфассалтар',
            ai_consent_required: 'Шумо бояд шартҳои коркарди маълумот бо ИИ-ро қабул кунед',

            terms_consent_prefix: 'Ман бо',
            terms_consent_and: 'ва',
            terms_consent_suffix: 'розӣ ҳастам',
            terms_link: 'Созишномаи корбарӣ',
            privacy_link: 'Сиёсати махфият',
            terms_consent_required: 'Шумо бояд бо Созишномаи корбарӣ ва Сиёсати махфият розӣ шавед',

            marketing_consent_checkbox: 'Мехоҳам хабарҳо ва маводи муфид дар бораи Datavue ба почта гирам',
        },

        consent: {
            page_title: 'Розигӣ ба коркарди маълумот бо ИИ',
            section1_title: 'Мо ба ИИ чӣ мефиристем',
            section1_text: 'Ҳангоми пайваст кардани манбаи маълумот, Datavue ба хидмати беруноии ИИ (OpenAI) танҳо сохтори маълумотро мефиристад: номи ҷадвалҳо ва сутунҳо, навъҳо ва алоқамандии байни онҳо — он чизе, ки барои тарҳрезии дашборд ва навиштани коди ҳисоб кардани нишондиҳандаҳо лозим аст. Худи маълумот — қиматҳои сатрҳо, сабтҳои мушаххас — ба ИИ фиристода намешавад ва аз манбаи маълумот берун намеравад: ҳамаи ҳисобҳо аз рӯи коди тавлидшуда дар тарафи Datavue, бевосита аз пойгоҳи додаҳо ё файли шумо иҷро мешаванд.',
            section2_title: 'ИИ метавонад хато кунад, аммо инро санҷидан мумкин аст',
            section2_text: 'Дашбордҳо ва коди SQL/Python барои ҳисоб кардани нишондиҳандаҳоро ИИ ба таври худкор эҷод мекунад. Мисли ҳар системаи ИИ, он метавонад хато кунад — вазифаро нодуруст фаҳмад ё дархостро нодуруст созад. Барои он ки ба рақами санҷиданашуда такя накунед, ҳар виҷети дашбордро дар реҷаи «Конструктор» кушода, коди дақиқ ё дархости SQL-и он дида мешавад, ки бо он ҳисоб шудааст. Агар корманд ба нишондиҳанда шубҳа кунад, ӯ ҳамеша метавонад бубинад, ки рақам аз куҷо омадааст, ва мантиқи ҳисобро худаш санҷад.',
            footer_note: 'Бо бақайдгирӣ дар Datavue шумо тасдиқ мекунед, ки бо ин шартҳо шинос шудаед ва розӣ ҳастед.',
        },

        terms: {
            page_title: 'Созишномаи корбарӣ',
            intro: 'Бо бақайдгирӣ дар Datavue, шумо (намояндаи ширкат) ин созишномаро оид ба истифодаи хидмат мебандед. Дар поён шартҳои асосӣ бо забони содда оварда шудаанд.',
            point1_title: 'Datavue чист',
            point1_text: 'Datavue хидмати эҷоди худкори дашбордҳои BI аст: шумо манбаи маълумот (файл ё пойгоҳи додаҳо)-ро пайваст мекунед, ва ИИ виҷетҳоро тарҳрезӣ карда, коди ҳисоб кардани нишондиҳандаҳоро менависад.',
            point2_title: 'Ваколат ҳангоми бақайдгирӣ',
            point2_text: 'Бо бақайдгирӣ аз номи ширкат, шумо тасдиқ мекунед, ки ваколатдоред онро намояндагӣ кунед ва ин созишномаро бандед — маҳз шумо соҳиби ҳисоби ширкати эҷодшаванда мешавед.',
            point3_title: 'Масъулият барои маълумоти боркардашуда',
            point3_text: 'Шумо файлҳо ва пойгоҳҳои додаҳои худро ба Datavue пайваст мекунед. Шумо бояд ҳуқуқи қонунии коркарди онҳоро дошта бошед — аз ҷумла агар онҳо маълумоти шахсии мизоҷон ё кормандони шуморо дар бар гиранд. Datavue ин маълумотро аз рӯи супориши шумо, аз ҷиҳати техникӣ коркард мекунад ва қонунияти онро санҷида намебарояд.',
            point4_title: 'Маҳдудияти масъулият барои ИИ',
            point4_text: 'Дашбордҳо ва коди ҳисоб кардани нишондиҳандаҳоро ИИ ба таври худкор эҷод мекунад ва метавонад хато кунад (тафсилот — дар Розигӣ ба коркарди маълумот бо ИИ). Datavue барои қарорҳои идоракунӣ, ки дар асоси нишондиҳандаҳои санҷиданашуда қабул шудаанд, масъулият надорад.',
            point5_title: 'Тағйирот ва қатъи дастрасӣ',
            point5_text: 'Мо метавонем ҳисоби корбарро дар сурати вайрон кардани шартҳои хидмат боздорем ё бандем. Шумо метавонед истифодаи хидматро дар лаҳзаи дилхоҳ қатъ кунед ва ҳисобро нест намоед.',
            footer_note: 'Идома додани бақайдгирӣ маънои онро дорад, ки шумо ин шартҳоро хондаед ва қабул мекунед.',
        },

        privacy: {
            page_title: 'Сиёсати махфият',
            intro: 'Ин саҳифа шарҳ медиҳад, ки Datavue ҳангоми бақайдгирӣ ва истифодаи хидмат кадом маълумоти шуморо ҷамъ мекунад ва чӣ гуна коркард мекунад.',
            point1_title: 'Кадом маълумотро ҷамъ мекунем',
            point1_text: 'Ҳангоми бақайдгирӣ: ном, почтаи электронӣ, номи ширкат ва парол (дар шакли хэш нигоҳ дошта мешавад, на ба таври кушод). Ҳангоми истифодаи хидмат — маълумот дар бораи истифодабарӣ, масалан таърихи чат бо агенти ИИ.',
            point2_title: 'Барои чӣ истифода мебарем',
            point2_text: 'Барои эҷод ва нигоҳдории ҳисоби шумо, таъмини кори хидмат, тамос бо шумо оид ба ҳисоб ва, агар шумо розигии алоҳида додаед, — фиристодани хабарҳо дар бораи маҳсулот.',
            point3_title: 'Ба кӣ маълумот дода мешавад',
            point3_text: 'Маълумоти шахсии ҳангоми бақайдгирӣ додашуда ба шахсони сеюм дода намешавад, ба ғайр аз ҳолатҳое, ки қонун талаб мекунад. Ҳангоми эҷоди дашбордҳо сохтори (схемаи) маълумоти тиҷоратии шумо ба провайдери ИИ (OpenAI) фиристода мешавад — тафсилот дар Розигӣ ба коркарди маълумот бо ИИ; худи маълумот ба он фиристода намешавад.',
            point4_title: 'Мӯҳлати нигоҳдорӣ',
            point4_text: 'Маълумот то фаъол будани ҳисоби шумо нигоҳ дошта мешавад. Пас аз нест кардани ҳисоб маълумот нест карда мешавад, ба ғайр аз ҳолатҳое, ки мӯҳлати дарозтари нигоҳдорӣ аз рӯи қонун талаб карда мешавад.',
            point5_title: 'Ҳуқуқҳои шумо',
            point5_text: 'Шумо метавонед бо мурожиат ба дастгирӣ дастрасӣ ба маълумоти худ, ислоҳ ё нест кардани онро дархост кунед.',
            footer_note: 'Идома додани бақайдгирӣ маънои онро дорад, ки шумо бо ин сиёсат шинос шудаед.',
        }
    }
};

export default createI18n({
    legacy: false,
    locale: localStorage.getItem('lang') || 'ru',
    fallbackLocale: 'en',
    messages
});
