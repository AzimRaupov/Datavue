<?php

use App\Helpers\Ai\IntentClassifier;

/**
 * Классификатор обучается в Python, а считает в проде PHP. Две реализации
 * одной формулы неизбежно расходятся — и расходятся молча: модель начинает
 * видеть другие признаки, точность падает, ошибок в логах нет.
 *
 * Поэтому в артефакт встроены контрольные примеры с вероятностями от sklearn,
 * а этот тест сверяет с ними PHP-реализацию.
 */

uses(Tests\TestCase::class);

beforeEach(function () {
    IntentClassifier::flush();

    if (!is_file(config('intents.model_path'))) {
        $this->markTestSkipped('Модель не обучена: venv/bin/python ml/intents/train.py');
    }
});

it('повторяет вероятности, посчитанные при обучении', function () {
    $result = (new IntentClassifier())->selfTest();

    expect($result['checked'])->toBeGreaterThan(0)
        ->and($result['max_deviation'])->toBeLessThan(1e-5);
});

it('различает намерения пользователя', function (string $text, string $expected) {
    $prediction = (new IntentClassifier())->predict($text);

    expect($prediction)->not->toBeNull()
        ->and($prediction['label'])->toBe($expected);
})->with([
    ['сколько всего клиентов?', IntentClassifier::CHAT],
    ['что посоветуешь добавить на дашборд?', IntentClassifier::CHAT],
    ['добавь график по странам', IntentClassifier::DASHBOARD],
    ['удали второй виджет', IntentClassifier::DASHBOARD],
    ['выгрузи заказы за год в excel', IntentClassifier::EXPORT],
    ['сохрани это в pdf', IntentClassifier::EXPORT],
]);

it('различает показ данных и выгрузку файлом', function () {
    // Пара, на которой ошибался маршрутизатор: фразы отличаются одним хвостом,
    // а действия системы совершенно разные.
    $classifier = new IntentClassifier();

    expect($classifier->predict('покажи топ 10 клиентов')['label'])->toBe(IntentClassifier::CHAT)
        ->and($classifier->predict('покажи топ 10 клиентов и сохрани в csv')['label'])->toBe(IntentClassifier::EXPORT);
});

it('переживает опечатки пользователя', function () {
    $classifier = new IntentClassifier();

    expect($classifier->predict('добаф виджет па регионам')['label'])->toBe(IntentClassifier::DASHBOARD)
        ->and($classifier->predict('выгруз в эксел заказы')['label'])->toBe(IntentClassifier::EXPORT);
});

it('требует более высокой уверенности для перестройки дашборда', function () {
    // Ошибки неравноценны: принять вопрос за команду — перестроить пользователю
    // экран вместо ответа. Порог для dashboard намеренно выше остальных.
    config()->set('intents.threshold', 0.70);
    config()->set('intents.threshold_dashboard', 0.80);

    $classifier = new IntentClassifier();

    $chat = ['label' => IntentClassifier::CHAT, 'confidence' => 0.75, 'probabilities' => []];
    $dashboard = ['label' => IntentClassifier::DASHBOARD, 'confidence' => 0.75, 'probabilities' => []];

    expect($classifier->isConfident($chat))->toBeTrue()
        ->and($classifier->isConfident($dashboard))->toBeFalse()
        // …но если систему никто не спрашивал, а она сама предложила изменение
        // и пользователь согласился — строгий порог не нужен: он отвечает
        // на наш же вопрос.
        ->and($classifier->isConfident($dashboard, 'dashboard'))->toBeTrue()
        ->and($classifier->isConfident($dashboard, 'export'))->toBeFalse();
});

it('строит контекст из служебного поля агента', function () {
    // В признаки идёт только тип: описание — это задание исполнителю,
    // а для классификации оно шум.
    expect(IntentClassifier::offerContext('dashboard', 'объединить карточки'))->toBe('offer_dashboard')
        ->and(IntentClassifier::offerContext('question'))->toBe('offer_question')
        ->and(IntentClassifier::offerContext(null))->toBe('')
        ->and(IntentClassifier::offerContext(''))->toBe('');
});

it('не доверяет «none» у ответа с предложением', function () {
    // Из четырёх значений «none» ошибочно ставится чаще всего: агент в тексте
    // предлагает («могу подготовить конфигурацию под ваш выбор»), а в поле
    // пишет, что ничего не предлагал. И строка offer_none ещё и подменяет собой
    // хвост ответа, то есть стирает единственный оставшийся сигнал.
    $offerInText = 'В воронке всего три этапа. Могу подготовить виджет с более длинной цепочкой?';

    expect(IntentClassifier::contextFrom('none', '', $offerInText))->toBe($offerInText)
        // Ответ без предложения — «none» принимается как есть.
        ->and(IntentClassifier::contextFrom('none', '', 'Всего в базе 122 клиента из 21 страны.'))
        ->toBe('offer_none')
        // Остальные значения выдумывать незачем — им верим сразу.
        ->and(IntentClassifier::contextFrom('dashboard', 'объединить карточки', 'что угодно'))
        ->toBe('offer_dashboard')
        // Поля нет вовсе — работает запасной путь по тексту ответа.
        ->and(IntentClassifier::contextFrom(null, null, 'Готово, дашборд обновлён.'))
        ->toBe('Готово, дашборд обновлён.');
});

it('понимает согласие по типу предложения', function (string $reply, string $offer, string $expected) {
    expect((new IntentClassifier())->predict($reply, $offer)['label'])->toBe($expected);
})->with([
    ['давай', 'offer_dashboard', IntentClassifier::DASHBOARD],
    ['применяй', 'offer_dashboard', IntentClassifier::DASHBOARD],
    ['давай', 'offer_export', IntentClassifier::EXPORT],
    ['давай', 'offer_question', IntentClassifier::CHAT],
    ['нет', 'offer_dashboard', IntentClassifier::CHAT],
    ['не надо', 'offer_export', IntentClassifier::CHAT],
    ['выгрузи заказы в csv', 'offer_dashboard', IntentClassifier::EXPORT],
]);

it('распознаёт бессмысленный ввод', function (string $text) {
    // Классификатор обязан выбрать класс — отказаться он не может. Без этой
    // проверки агент шёл в базу сочинять SQL по случайному набору букв.
    $classifier = new IntentClassifier();

    expect($classifier->isUnintelligible($text, $classifier->predict($text)))->toBeTrue();
})->with([
    'яавмвфмвмвамвмыаввамывам',
    'фывафывафыва фывафыва',
    'ццццццццццццццццц',
    'аывпаывпаывпаывп',
    '...............',
    '?????????????',
    '',
]);

it('не глушит осмысленные сообщения', function (string $text) {
    $classifier = new IntentClassifier();

    expect($classifier->isUnintelligible($text, $classifier->predict($text)))->toBeFalse();
})->with([
    'сколько всего клиентов?',
    'выгрузи заказы в excel',
    'добаф виджет па регионам',
    'карточки не внезу должы быт',
    // Короткие реплики: покрытие у них низкое просто из-за длины.
    'да',
    'спасибо',
    'salom',
    'helo',
    // Таджикский и английский модель почти не знает, но это законные запросы —
    // их место в языковой модели, а не в заглушке «уточните».
    'чи хел кор мекунад ин график?',
    'шумораи муштарӣ ро хисоб кун',
    'please export all my customers to excel file',
]);

it('понимает короткую реплику по предыдущему ответу агента', function () {
    // Ядро механизма. Слово одно и то же — намерения разные, и различить их
    // можно только по тому, что агент сказал перед этим.
    $classifier = new IntentClassifier();

    $offerDashboard = 'Предлагаю объединить карточки в один виджет и убрать второй. Применить изменения?';
    $offerExport = 'Эти данные удобнее смотреть в файле. Подготовить выгрузку в Excel?';
    $question = 'За какой период показать данные?';

    expect($classifier->predict('давай', $offerDashboard)['label'])->toBe(IntentClassifier::DASHBOARD)
        ->and($classifier->predict('давай', $offerExport)['label'])->toBe(IntentClassifier::EXPORT)
        ->and($classifier->predict('давай', $question)['label'])->toBe(IntentClassifier::CHAT);
});

it('понимает отказ, что бы ни предлагал агент', function () {
    $classifier = new IntentClassifier();

    expect($classifier->predict('нет', 'Предлагаю объединить карточки. Применить изменения?')['label'])
        ->toBe(IntentClassifier::CHAT)
        ->and($classifier->predict('не надо', 'Подготовить выгрузку в Excel?')['label'])
        ->toBe(IntentClassifier::CHAT);
});

it('не позволяет контексту перебить самостоятельную фразу', function () {
    // Обратная опасность: модель, перекормленная диалогами, начинает отвечать
    // по реплике агента, игнорируя пользователя.
    $classifier = new IntentClassifier();

    $offer = 'Предлагаю объединить карточки в один виджет и убрать второй. Применить изменения?';

    expect($classifier->predict('выгрузи заказы в csv', $offer)['label'])->toBe(IntentClassifier::EXPORT)
        ->and($classifier->predict('сколько всего заказов?', $offer)['label'])->toBe(IntentClassifier::CHAT);
});

it('берёт в обучение всё, кроме бессмыслицы', function () {
    $classifier = new IntentClassifier();

    // Контекстные реплики теперь учить МОЖНО — они хранятся вместе с репликой
    // агента, и именно на них модель учится смотреть на контекст.
    expect($classifier->isLearnable('давай'))->toBeTrue()
        ->and($classifier->isLearnable('выгрузи заказы в excel'))->toBeTrue()
        ->and($classifier->isLearnable('спасибо'))->toBeTrue()
        ->and($classifier->isLearnable('яавмвфмвмвамвмыаввамывам'))->toBeFalse();
});

it('не берёт в обучение короткий мусор', function (string $text) {
    // Детектор бессмыслицы его не ловит: он не трогает строки короче
    // двенадцати символов, иначе резал бы «salom» и «helo». Для обучения
    // планка другая — испорченный пример живёт в наборе вечно.
    expect((new IntentClassifier())->isLearnable($text))->toBeFalse();
})->with(['валмвламвл', 'асвамв', 'фывафыва', 'аывпаывп']);

it('берёт в обучение настоящие однословные сообщения', function (string $text) {
    expect((new IntentClassifier())->isLearnable($text))->toBeTrue();
})->with(['выгрузите', 'экспортируй', 'дашборд', 'спасибо']);

it('молчит при выключенном классификаторе', function () {
    config()->set('intents.enabled', false);

    expect((new IntentClassifier())->predict('выгрузи в excel'))->toBeNull();
});

it('сопоставляет задачи роутера с классами намерений', function () {
    expect(IntentClassifier::labelForTask('response_in_chat'))->toBe(IntentClassifier::CHAT)
        ->and(IntentClassifier::labelForTask('generate_dashboard'))->toBe(IntentClassifier::DASHBOARD)
        ->and(IntentClassifier::labelForTask('re_generate_dashboard'))->toBe(IntentClassifier::DASHBOARD)
        ->and(IntentClassifier::labelForTask('export_data'))->toBe(IntentClassifier::EXPORT)
        ->and(IntentClassifier::labelForTask('нечто неизвестное'))->toBeNull();
});
