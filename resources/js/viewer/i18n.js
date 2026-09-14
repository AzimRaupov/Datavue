import { createI18n } from 'vue-i18n';

const messages = {
    ru: {
        header: {
            home: 'Главная',
            terms: 'Условия',
            privacy: 'Конфиденциальность',
            startBtn: 'Начать'
        },

        footer: {
            rights: 'Все права защищены.',
        },

        home: {
            hero: {
                title: 'Превратите ваши данные в интерактивный дашборд за секунды',
                description: ' — это умный ИИ-агент, который анализирует ваши Excel, CSV файлы или базы данных (SQLite, удаленные БД) и мгновенно создает красивые интерактивные дашборды с виджетами по вашему текстовому запросу.',
                cta_primary: 'Попробовать бесплатно',
                cta_secondary: 'Посмотреть демо',
                demo_tooltip: 'Открыть демо дашборда!',
                demo_alt: 'Интерфейс ИИ-агента для создания дашбордов',
            },
            features: {
                sources: {
                    title: 'Любые источники данных',
                    description: 'Загружайте файлы Excel и CSV или подключайте SQLite и удаленные базы данных (PostgreSQL, MySQL и др.) в пару кликов.',
                },
                ai: {
                    title: 'ИИ-агент для визуализации',
                    description: 'Просто опишите в чате, что вы хотите увидеть. ИИ автоматически подберет нужные графики, таблицы и KPI-виджеты для ваших данных.',
                },
                instant: {
                    title: 'Мгновенный результат',
                    description: 'Забудьте о ручном создании отчетов. Получайте готовые, красивые и интерактивные дашборды за считанные секунды без навыков программирования.',
                },
            },
            detail1: {
                title: 'Все необходимое для глубокой аналитики',
                description: 'Инструменты, которые помогают вам понимать данные, а не просто смотреть на цифры.',
                chat: {
                    title: 'Интуитивный чат с ИИ',
                    description: 'Общайтесь с данными на естественном языке. Спросите «Покажи продажи по регионам за последний квартал», и агент мгновенно построит нужную диаграмму.',
                },
                widgets: {
                    title: 'Гибкая настройка виджетов',
                    description: 'После генерации вы можете легко перетаскивать, изменять размер и настраивать каждый виджет, чтобы дашборд идеально соответствовал вашим задачам.',
                },
                security: {
                    title: 'Безопасность данных',
                    description: 'Ваши данные надежно защищены. Мы поддерживаем безопасные подключения к удаленным базам данных и не используем вашу конфиденциальную информацию для обучения моделей.',
                },
            },
            cta: {
                title: 'Повысьте эффективность аналитики с нашим ИИ-агентом,<br />доступным прямо сейчас.',
                description: 'Перестаньте тратить часы на создание отчетов в Excel. Доверьте рутину искусственному интеллекту и сосредоточьтесь на принятии решений на основе данных.',
                start: 'Начать работу',
                learn_more: 'Узнать больше',
            },
            how: {
                title: 'Три простых шага к вашему идеальному дашборду',
                description: 'От сырых данных до готовой визуализации быстрее, чем вы успеете выпить чашку кофе.',
                step1: {
                    title: '1. Загрузите данные',
                    description: 'Импортируйте файл Excel, CSV или укажите параметры подключения к вашей базе данных (SQLite, PostgreSQL, MySQL).',
                },
                step2: {
                    title: '2. Опишите задачу',
                    description: 'Напишите в чате с ИИ-агентом, какую метрику, тренд или закономерность вы хотите проанализировать.',
                },
                step3: {
                    title: '3. Получите дашборд',
                    description: 'ИИ мгновенно сгенерирует интерактивный дашборд с подходящими виджетами, который можно сохранить, экспортировать или поделиться ссылкой.',
                },
            },
            pricing: {
                starter: {
                    title: 'Стартовый',
                    period: 'навсегда',
                    feature1: 'До 3 источников данных',
                    feature2: 'Базовые типы графиков и виджетов',
                    feature3: 'Экспорт дашборда в PNG',
                    feature4: 'До 10 запросов к ИИ в день',
                    cta: 'Начать бесплатно',
                },
                pro: {
                    badge: 'Популярный',
                    title: 'Профессиональный',
                    period: 'в месяц',
                    feature1: 'Неограниченные источники данных',
                    feature2: 'Все типы виджетов и продвинутая аналитика',
                    feature3: 'Прямое подключение к любым БД',
                    feature4: 'Неограниченные запросы к ИИ и экспорт в PDF/Excel',
                    cta: 'Выбрать тариф',
                },
                enterprise: {
                    title: 'Корпоративный',
                    price: 'По запросу',
                    period: 'индивидуально',
                    feature1: 'Все возможности "Профессионального"',
                    feature2: 'Развертывание на вашем сервере (On-premise)',
                    feature3: 'Приоритетная техническая поддержка',
                    feature4: 'Персональное обучение команды',
                    cta: 'Связаться с нами',
                },
            },
            team: {
                title: 'Лицензия для команд',
                description: 'Получите доступ к ИИ-аналитике для всей вашей команды аналитиков, менеджеров и руководителей.',
                feature1: 'До 10 активных пользователей',
                feature2: 'Общие рабочие пространства и дашборды',
                feature3: 'Централизованное управление источниками данных',
                feature4: 'Приоритетная скорость генерации',
                price_per: 'за команду',
                price_period: 'в месяц',
                cta: 'Подключить команду',
            },
            faq: {
                title: 'Часто задаваемые вопросы',
                q1: {
                    title: 'Какие форматы данных поддерживаются?',
                    description: 'Мы поддерживаем загрузку файлов Excel (.xlsx) и CSV, а также прямое подключение к SQLite, PostgreSQL, MySQL и другим популярным реляционным базам данных через защищенное соединение.',
                },
                q2: {
                    title: 'Насколько безопасны мои данные?',
                    description: 'Мы используем шифрование при передаче и хранении. При подключении удаленных баз данных мы не храним ваши учетные данные, а ваши данные не используются для обучения наших ИИ-моделей без явного согласия.',
                },
                q3: {
                    title: 'Можно ли редактировать дашборд после создания?',
                    description: 'Да, конечно! ИИ создает оптимальную базовую структуру, но вы можете в любой момент изменить тип графика, применить фильтры, поменять цвета и расположение виджетов вручную.',
                },
                q4: {
                    title: 'Нужны ли мне навыки программирования или SQL?',
                    description: 'Нет, наш продукт создан для пользователей любого уровня подготовки. Просто загрузите данные и опишите свою задачу обычным человеческим языком в чате.',
                },
                q5: {
                    title: 'Что делать, если ИИ неправильно понял запрос?',
                    description: 'Вы можете уточнить запрос в чате, например: «Сделай график столбчатым» или «Добавь фильтр по дате». ИИ-агент мгновенно внесет правки в дашборд.',
                },
                q6: {
                    title: 'Какие ограничения есть у лицензии?',
                    item1: 'Перепродавать саму платформу как свой собственный SaaS-продукт',
                    item2: 'Использовать сгенерированные дашборды для создания конкурирующего сервиса визуализации данных',
                },
            },
            newsletter: {
                title: 'Подпишитесь на наши обновления',
                description: 'Узнавайте первыми о новых типах графиков, интеграциях с новыми базами данных и возможностях ИИ-агента.',
                placeholder: 'Ваш Email',
                button: 'Подписаться',
            },
            support: {
                title: 'Остались вопросы?',
                description: 'Не нашли ответ? Свяжитесь с нашей службой поддержки, и мы поможем вам настроить ваш первый дашборд.',
                q1: {
                    title: 'Можно ли интегрировать дашборд в мой сайт или CRM?',
                    description: 'Да, мы предоставляем код для встраивания (iframe), чтобы вы могли легко добавить ваш интерактивный дашборд на любой веб-сайт, во внутреннюю систему или CRM.',
                },
                q2: {
                    title: 'Как быстро ИИ обрабатывает большие объемы данных?',
                    description: 'Благодаря оптимизированным алгоритмам, обработка файлов до 100 МБ или выполнение сложных запросов к БД занимает считанные секунды.',
                },
                q3: {
                    title: 'Есть ли у вас API для автоматизации?',
                    description: 'Да, тарифы "Профессиональный" и "Корпоративный" включают доступ к REST API, что позволяет генерировать дашборды программно на основе ваших внутренних процессов.',
                },
                cta: 'Написать в поддержку',
            },
        },

        auth: {
            page_login: 'Вход',
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
        header: {
            home: 'Home',
            terms: 'Terms',
            privacy: 'Privacy',
            startBtn: 'Get Started'
        },

        footer: {
            rights: 'All rights reserved.',
        },

        home: {
            hero: {
                title: 'Turn your data into an interactive dashboard in seconds',
                description: ' is a smart AI agent that analyzes your Excel and CSV files or databases (SQLite, remote DBs) and instantly builds beautiful interactive dashboards with widgets from your text request.',
                cta_primary: 'Try it for free',
                cta_secondary: 'Watch the demo',
                demo_tooltip: 'Open the demo dashboard!',
                demo_alt: 'AI agent interface for building dashboards',
            },
            features: {
                sources: {
                    title: 'Any data source',
                    description: 'Upload Excel and CSV files, or connect SQLite and remote databases (PostgreSQL, MySQL, and more) in a couple of clicks.',
                },
                ai: {
                    title: 'AI agent for visualization',
                    description: 'Just describe what you want to see in the chat. The AI automatically picks the right charts, tables, and KPI widgets for your data.',
                },
                instant: {
                    title: 'Instant results',
                    description: 'Forget manual reporting. Get polished, interactive dashboards in seconds, no coding skills required.',
                },
            },
            detail1: {
                title: 'Everything you need for deep analytics',
                description: 'Tools that help you understand your data, not just look at numbers.',
                chat: {
                    title: 'Intuitive AI chat',
                    description: 'Talk to your data in plain language. Ask "Show sales by region for the last quarter" and the agent instantly builds the right chart.',
                },
                widgets: {
                    title: 'Flexible widget tuning',
                    description: 'After generation, you can freely drag, resize, and configure every widget so the dashboard fits your task exactly.',
                },
                security: {
                    title: 'Data security',
                    description: 'Your data is kept secure. We support safe connections to remote databases and never use your confidential information to train models.',
                },
            },
            cta: {
                title: 'Boost your analytics with our AI agent,<br />available right now.',
                description: 'Stop spending hours building reports in Excel. Leave the routine to AI and focus on making decisions based on data.',
                start: 'Get started',
                learn_more: 'Learn more',
            },
            how: {
                title: 'Three simple steps to your perfect dashboard',
                description: 'From raw data to a finished visualization faster than you can finish a cup of coffee.',
                step1: {
                    title: '1. Upload your data',
                    description: 'Import an Excel or CSV file, or provide connection details for your database (SQLite, PostgreSQL, MySQL).',
                },
                step2: {
                    title: '2. Describe the task',
                    description: 'Tell the AI agent in chat which metric, trend, or pattern you want to analyze.',
                },
                step3: {
                    title: '3. Get your dashboard',
                    description: 'The AI instantly generates an interactive dashboard with the right widgets, which you can save, export, or share via a link.',
                },
            },
            pricing: {
                starter: {
                    title: 'Starter',
                    period: 'forever',
                    feature1: 'Up to 3 data sources',
                    feature2: 'Basic chart and widget types',
                    feature3: 'Export dashboard to PNG',
                    feature4: 'Up to 10 AI requests per day',
                    cta: 'Start for free',
                },
                pro: {
                    badge: 'Most popular',
                    title: 'Professional',
                    period: 'per month',
                    feature1: 'Unlimited data sources',
                    feature2: 'All widget types and advanced analytics',
                    feature3: 'Direct connection to any database',
                    feature4: 'Unlimited AI requests and export to PDF/Excel',
                    cta: 'Choose plan',
                },
                enterprise: {
                    title: 'Enterprise',
                    price: 'Contact us',
                    period: 'custom pricing',
                    feature1: 'Everything in "Professional"',
                    feature2: 'On-premise deployment on your own server',
                    feature3: 'Priority technical support',
                    feature4: 'Dedicated team onboarding',
                    cta: 'Contact us',
                },
            },
            team: {
                title: 'Team license',
                description: 'Give your entire team of analysts, managers, and executives access to AI-powered analytics.',
                feature1: 'Up to 10 active users',
                feature2: 'Shared workspaces and dashboards',
                feature3: 'Centralized data source management',
                feature4: 'Priority generation speed',
                price_per: 'per team',
                price_period: 'per month',
                cta: 'Set up your team',
            },
            faq: {
                title: 'Frequently asked questions',
                q1: {
                    title: 'Which data formats are supported?',
                    description: 'We support uploading Excel (.xlsx) and CSV files, as well as direct connections to SQLite, PostgreSQL, MySQL, and other popular relational databases over a secure connection.',
                },
                q2: {
                    title: 'How secure is my data?',
                    description: 'We use encryption in transit and at rest. When connecting remote databases, we do not store your credentials, and your data is never used to train our AI models without explicit consent.',
                },
                q3: {
                    title: 'Can I edit a dashboard after it is generated?',
                    description: 'Absolutely. The AI builds an optimal base structure, but you can change chart types, apply filters, adjust colors, and rearrange widgets manually at any time.',
                },
                q4: {
                    title: 'Do I need coding or SQL skills?',
                    description: 'No, our product is built for users of any skill level. Just upload your data and describe your task in plain language in the chat.',
                },
                q5: {
                    title: "What if the AI misunderstands my request?",
                    description: 'You can clarify it right in the chat, e.g. "Make this a bar chart" or "Add a date filter." The AI agent instantly updates the dashboard.',
                },
                q6: {
                    title: 'What restrictions does the license have?',
                    item1: 'Reselling the platform itself as your own SaaS product',
                    item2: 'Using generated dashboards to build a competing data visualization service',
                },
            },
            newsletter: {
                title: 'Subscribe to our updates',
                description: 'Be the first to know about new chart types, new database integrations, and AI agent features.',
                placeholder: 'Your email',
                button: 'Subscribe',
            },
            support: {
                title: 'Still have questions?',
                description: "Can't find an answer? Contact our support team, and we'll help you set up your first dashboard.",
                q1: {
                    title: 'Can I embed a dashboard into my site or CRM?',
                    description: 'Yes, we provide embed code (iframe) so you can easily add your interactive dashboard to any website, internal system, or CRM.',
                },
                q2: {
                    title: 'How fast does the AI handle large volumes of data?',
                    description: 'Thanks to optimized algorithms, processing files up to 100 MB or running complex database queries takes just seconds.',
                },
                q3: {
                    title: 'Do you have an API for automation?',
                    description: 'Yes, the "Professional" and "Enterprise" plans include REST API access, letting you generate dashboards programmatically as part of your internal workflows.',
                },
                cta: 'Contact support',
            },
        },

        auth: {
            page_login: 'Login',
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
        header: {
            home: 'Асосӣ',
            terms: 'Шартҳо',
            privacy: 'Махфият',
            startBtn: 'Оғоз кардан'
        },

        footer: {
            rights: 'Ҳамаи ҳуқуқҳо ҳифз шудаанд.',
        },

        home: {
            hero: {
                title: 'Маълумоти худро дар якчанд сония ба дашборди интерактивӣ табдил диҳед',
                description: ' ёрдамчии зукуви ИИ аст, ки файлҳои Excel, CSV ё пойгоҳи додаҳои шуморо (SQLite, пойгоҳҳои дурдаст) таҳлил карда, аз рӯи дархости матнии шумо дарҳол дашбордҳои зебо ва интерактивӣ бо виҷетҳо месозад.',
                cta_primary: 'Ройгон санҷед',
                cta_secondary: 'Демо бинед',
                demo_tooltip: 'Демои дашбордро кушоед!',
                demo_alt: 'Интерфейси ёрдамчии ИИ барои сохтани дашборд',
            },
            features: {
                sources: {
                    title: 'Ҳар гуна манбаи маълумот',
                    description: 'Файлҳои Excel ва CSV-ро бор кунед ё SQLite ва пойгоҳҳои дурдасти додаҳо (PostgreSQL, MySQL ва диг.)-ро дар якчанд клик пайваст кунед.',
                },
                ai: {
                    title: 'Ёрдамчии ИИ барои тасвиркунӣ',
                    description: 'Танҳо дар чат тасвир кунед, ки чиро дидан мехоҳед. ИИ худкор графикҳо, ҷадвалҳо ва виҷетҳои KPI-и лозимаро барои маълумоти шумо интихоб мекунад.',
                },
                instant: {
                    title: 'Натиҷаи фаврӣ',
                    description: 'Дигар ҳисоботро дастӣ насозед. Дашбордҳои тайёр, зебо ва интерактивиро дар якчанд сония бидуни донистани барномасозӣ гиред.',
                },
            },
            detail1: {
                title: 'Ҳама чизи лозима барои таҳлили амиқ',
                description: 'Абзорҳое, ки ба шумо дар фаҳмидани маълумот кӯмак мекунанд, на танҳо нигоҳ кардан ба рақамҳо.',
                chat: {
                    title: 'Чати осони ИИ',
                    description: 'Бо маълумоти худ бо забони оддӣ гуфтугӯ кунед. Бипурсед «Фурӯшро аз рӯи минтақаҳо барои семоҳаи охир нишон деҳ» ва агент дарҳол графики лозимаро месозад.',
                },
                widgets: {
                    title: 'Танзими еластикии виҷетҳо',
                    description: 'Пас аз сохтан, шумо метавонед ҳар виҷетро озодона кашед, андозаашро тағйир диҳед ва танзим кунед, то дашборд комилан ба вазифаи шумо мувофиқ бошад.',
                },
                security: {
                    title: 'Бехатарии маълумот',
                    description: 'Маълумоти шумо боэътимод ҳифз мешавад. Мо пайвасти бехатарро ба пойгоҳҳои дурдасти додаҳо дастгирӣ мекунем ва маълумоти махфии шуморо барои омӯзонидани моделҳо истифода намебарем.',
                },
            },
            cta: {
                title: 'Самаранокии таҳлилро бо ёрдамчии ИИ баланд бардоред,<br />ки ҳозир дастрас аст.',
                description: 'Дигар соатҳоро барои сохтани ҳисобот дар Excel сарф накунед. Корҳои такрориро ба зимаи ҳуши сунъӣ гузоред ва диққати худро ба қабули қарорҳо дар асоси маълумот равона кунед.',
                start: 'Оғози кор',
                learn_more: 'Бештар донед',
            },
            how: {
                title: 'Се қадами оддӣ то дашборди дилхоҳи шумо',
                description: 'Аз маълумоти хом то тасвири тайёр — тезтар аз он ки як пиёла қаҳва нӯшед.',
                step1: {
                    title: '1. Маълумотро бор кунед',
                    description: 'Файли Excel, CSV-ро ворид кунед ё параметрҳои пайвастшавӣ ба пойгоҳи додаҳои худро (SQLite, PostgreSQL, MySQL) нишон диҳед.',
                },
                step2: {
                    title: '2. Вазифаро тасвир кунед',
                    description: 'Дар чат ба ёрдамчии ИИ бинависед, ки кадом нишондиҳанда, тамоюл ё қонунмандиро таҳлил кардан мехоҳед.',
                },
                step3: {
                    title: '3. Дашбордро гиред',
                    description: 'ИИ дарҳол дашборди интерактивиро бо виҷетҳои мувофиқ месозад, ки онро захира, содир ё бо пайванд мубодила кардан мумкин аст.',
                },
            },
            pricing: {
                starter: {
                    title: 'Ибтидоӣ',
                    period: 'ҳамеша',
                    feature1: 'То 3 манбаи маълумот',
                    feature2: 'Навъҳои асосии график ва виҷет',
                    feature3: 'Содироти дашборд ба PNG',
                    feature4: 'То 10 дархост ба ИИ дар як рӯз',
                    cta: 'Ройгон оғоз кунед',
                },
                pro: {
                    badge: 'Маъмултарин',
                    title: 'Касбӣ',
                    period: 'дар як моҳ',
                    feature1: 'Манбаъҳои маълумоти беохир',
                    feature2: 'Ҳамаи навъҳои виҷет ва таҳлили пешрафта',
                    feature3: 'Пайвасти мустақим ба ҳар гуна пойгоҳи додаҳо',
                    feature4: 'Дархостҳои беохир ба ИИ ва содирот ба PDF/Excel',
                    cta: 'Тарифро интихоб кунед',
                },
                enterprise: {
                    title: 'Корпоративӣ',
                    price: 'Аз рӯи дархост',
                    period: 'инфиродӣ',
                    feature1: 'Ҳамаи имконоти "Касбӣ"',
                    feature2: 'Ҷойгиркунӣ дар сервери худи шумо (On-premise)',
                    feature3: 'Дастгирии техникии афзалиятнок',
                    feature4: 'Омӯзиши шахсии дастаи корӣ',
                    cta: 'Бо мо тамос гиред',
                },
            },
            team: {
                title: 'Литсензия барои дастаҳо',
                description: 'Ба тамоми дастаи таҳлилгарон, мудирон ва роҳбарони худ дастрасӣ ба таҳлили ИИ диҳед.',
                feature1: 'То 10 корбари фаъол',
                feature2: 'Фазоҳои кории умумӣ ва дашбордҳо',
                feature3: 'Идоракунии марказонидашудаи манбаъҳои маълумот',
                feature4: 'Суръати афзалиятноки сохтан',
                price_per: 'барои даста',
                price_period: 'дар як моҳ',
                cta: 'Дастаро пайваст кунед',
            },
            faq: {
                title: 'Саволҳои зуд-зуд додашаванда',
                q1: {
                    title: 'Кадом форматҳои маълумот дастгирӣ мешаванд?',
                    description: 'Мо боркунии файлҳои Excel (.xlsx) ва CSV, инчунин пайвасти мустақим ба SQLite, PostgreSQL, MySQL ва дигар пойгоҳҳои маъмули реляционии додаҳоро тавассути пайвасти бехатар дастгирӣ мекунем.',
                },
                q2: {
                    title: 'Маълумоти ман то чӣ андоза бехатар аст?',
                    description: 'Мо ҳангоми интиқол ва нигоҳдорӣ рамзгузорӣ истифода мебарем. Ҳангоми пайвасти пойгоҳҳои дурдасти додаҳо маълумоти воридшавии шуморо нигоҳ намедорем, ва маълумоти шумо бидуни розигии равшан барои омӯзонидани моделҳои ИИ истифода намешавад.',
                },
                q3: {
                    title: 'Оё дашбордро пас аз сохтан таҳрир кардан мумкин аст?',
                    description: 'Албатта! ИИ сохтори базавии беҳтаринро месозад, аммо шумо метавонед дар ҳар лаҳза навъи графикро тағйир диҳед, филтр татбиқ кунед, рангҳо ва ҷойгиршавии виҷетҳоро дастӣ иваз кунед.',
                },
                q4: {
                    title: 'Оё ба ман малакаи барномасозӣ ё SQL лозим аст?',
                    description: 'Не, маҳсулоти мо барои корбарони ҳар сатҳи омодагӣ сохта шудааст. Танҳо маълумотро бор кунед ва вазифаи худро бо забони оддии инсонӣ дар чат тасвир кунед.',
                },
                q5: {
                    title: 'Агар ИИ дархостро нодуруст фаҳмад, чӣ бояд кард?',
                    description: 'Шумо метавонед дархостро дар чат аниқ кунед, масалан: «Графикро сутунӣ кун» ё «Филтр аз рӯи сана илова кун». Ёрдамчии ИИ дарҳол ба дашборд тағйирот ворид мекунад.',
                },
                q6: {
                    title: 'Литсензия чӣ маҳдудиятҳо дорад?',
                    item1: 'Худи платформаро ҳамчун маҳсулоти SaaS-и худ бозфурӯш кардан',
                    item2: 'Истифодаи дашбордҳои сохташуда барои сохтани хидмати рақиби тасвиркунии маълумот',
                },
            },
            newsletter: {
                title: 'Ба навсозиҳои мо обуна шавед',
                description: 'Аввалин шуда дар бораи навъҳои нави график, ҳамгироӣ бо пойгоҳҳои нави додаҳо ва имконоти ёрдамчии ИИ хабардор шавед.',
                placeholder: 'Email-и шумо',
                button: 'Обуна шудан',
            },
            support: {
                title: 'Савол доред?',
                description: 'Ҷавоб наёфтед? Бо хадамоти дастгирии мо тамос гиред — мо дар танзими аввалин дашборди шумо кӯмак мекунем.',
                q1: {
                    title: 'Оё дашбордро ба сомона ё CRM-и худ ҷойгир кардан мумкин аст?',
                    description: 'Ҳа, мо коди ҷойгиркунӣ (iframe) медиҳем, то шумо дашборди интерактивии худро ба ҳар гуна сомона, системаи дохилӣ ё CRM осон илова кунед.',
                },
                q2: {
                    title: 'ИИ ҳаҷми калони маълумотро чӣ қадар тез коркард мекунад?',
                    description: 'Ба шарофати алгоритмҳои оптимизатсияшуда, коркарди файлҳо то 100 МБ ё иҷрои дархостҳои мураккаб ба пойгоҳи додаҳо якчанд сония вақт мегирад.',
                },
                q3: {
                    title: 'Оё шумо API барои худкорсозӣ доред?',
                    description: 'Ҳа, тарифҳои "Касбӣ" ва "Корпоративӣ" дастрасӣ ба REST API-ро дар бар мегиранд, ки ба шумо имкон медиҳад дашбордҳоро дар асоси равандҳои дохилии худ барномавӣ созед.',
                },
                cta: 'Ба дастгирӣ нависед',
            },
        },

        auth: {
            page_login: 'Воридшавӣ',
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
