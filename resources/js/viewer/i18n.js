import { createI18n } from 'vue-i18n';

const messages = {
    ru: {
        header: {
            home: 'Главная',
            about: 'О нас',
            pricing: 'Цены',
            startBtn: 'Начать'
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
            no_account: 'У вас ещё нет аккаунта?'
        }
    },

    en: {
        header: {
            home: 'Home',
            about: 'About Us',
            pricing: 'Pricing',
            startBtn: 'Get Started'
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
            no_account: "Don't have an account yet?"
        }
    },

    tj: {
        header: {
            home: 'Асосӣ',
            about: 'Дар бораи мо',
            pricing: 'Нархҳо',
            startBtn: 'Оғоз кардан'
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
            no_account: 'Ҳанӯз ҳисоб надоред?'
        }
    }
};

export default createI18n({
    legacy: false,
    locale: localStorage.getItem('lang') || 'ru',
    fallbackLocale: 'en',
    messages
});
