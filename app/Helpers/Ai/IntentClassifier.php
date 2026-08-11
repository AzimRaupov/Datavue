<?php

namespace App\Helpers\Ai;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Локальный классификатор намерений пользователя.
 *
 * Определяет, чего человек хочет — ответа в чате, изменения дашборда или
 * выгрузки файла, — до обращения к языковой модели. Раньше этот вопрос
 * задавался GPT на каждое сообщение: 6–20 секунд ожидания, оплаченные токены
 * и полная зависимость от сети ради выбора из трёх вариантов.
 *
 * Модель — мультиномиальная логистическая регрессия поверх TF-IDF символьных
 * n-грамм, обученная в ml/intents/train.py. Здесь только вычисление: словарь,
 * веса и смещения читаются из выгруженного JSON.
 *
 * Почему предсказание считает PHP, а не Python: каждый вызов Python в платформе
 * означает новый процесс, и запуск интерпретатора с библиотеками занимает
 * больше времени, чем сама классификация. Логистическая регрессия — это
 * разреженное скалярное произведение, его незачем считать в другом процессе.
 *
 * ВАЖНО: normalize() и ngrams() обязаны совпадать с ml/intents/common.py
 * символ в символ. Разойдутся — модель начнёт видеть другие признаки
 * и деградирует молча. Против этого в артефакт встроены контрольные примеры
 * (см. selfTest() и tests/Unit/IntentClassifierTest.php).
 */
class IntentClassifier
{
    public const CHAT = 'chat';
    public const DASHBOARD = 'dashboard';
    public const EXPORT = 'export';

    /**
     * Разобранная модель. Статическая: за время запроса классификация
     * выполняется один раз, но разбирать 300 КБ JSON дважды всё равно незачем.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $model = null;

    private static bool $loadFailed = false;

    /**
     * Время правки файла, с которым модель была загружена.
     *
     * Воркер очереди живёт часами: без этой проверки он продолжал бы считать
     * по весам, прочитанным при старте, и переобучение доходило бы до него
     * только после перезапуска.
     */
    private static ?int $loadedAt = null;

    /**
     * Предсказание для фразы.
     *
     * @return array{label: string, confidence: float, probabilities: array<string, float>}|null
     *         null — классификатор недоступен, решение принимает языковая модель
     */
    public function predict(string $text, string $context = ''): ?array
    {
        if (!config('intents.enabled', true)) {
            return null;
        }

        $model = $this->model();

        if ($model === null || trim($text) === '') {
            return null;
        }

        try {
            return $this->compute($model, $text, $context);
        } catch (Throwable $e) {
            // Классификатор — ускоритель, а не обязательное звено: его поломка
            // не должна ронять обработку сообщения, система просто вернётся
            // к обращению в языковую модель.
            Log::warning('IntentClassifier: предсказание не выполнено', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Канонический контекст из служебного поля агента.
     *
     * Агент отдаёт два текста: развёрнутый ответ человеку и короткое описание
     * того, что он предложил, — для нас. Второе и попадает сюда.
     *
     * Зачем: предложение, сформулированное десятью разными способами, даёт
     * один и тот же признак «c:offer dashboard». Обрезок markdown-ответа такой
     * устойчивости не даёт — там каждый раз новая проза, из которой модель
     * вылавливает случайные куски.
     *
     * В признаки идёт ТОЛЬКО тип предложения, без описания. Описание —
     * это задание для исполнителя (см. RouterTask::instructionFor), а для
     * классификации оно лишь шум: слова «выручка по месяцам» встречаются
     * и в вопросах, и в выгрузках. Замер на живом ответе агента: с описанием
     * уверенность 0.722, без него 0.853 — при пороге 0.80 это разница между
     * «решили сами» и «заплатили за вызов модели».
     *
     * Тип склеен с префиксом в один токен намеренно: у «offer dashboard»
     * и «offer question» общее начало «offer », его n-граммы одинаковы для всех
     * типов и размывают сигнал. У «offer_dashboard» и «offer_question»
     * различия начинаются на третьем символе.
     */
    public static function offerContext(?string $type, ?string $summary = null): string
    {
        $type = trim((string) $type);

        return $type === '' ? '' : 'offer_'.$type;
    }

    /**
     * Контекст предыдущего хода: служебное поле агента или хвост его ответа.
     *
     * Отдельный метод, а не просто offerContext(), из-за одной несимметричной
     * опасности. Из четырёх значений чаще всего ошибочно ставится «none»:
     * агент в тексте предлагает («могу подготовить конфигурацию под ваш выбор»),
     * а в поле пишет, что ничего не предлагал. И это не просто потеря сигнала —
     * строка «offer_none» ПОДМЕНЯЕТ собой хвост ответа, то есть активно стирает
     * то, по чему смысл ещё можно было восстановить.
     *
     * Поэтому «none» принимается на веру только у ответа, который и правда ничем
     * не заканчивается. Если ответ завершается вопросом — агент о чём-то спросил
     * или что-то предложил, чем бы он это ни назвал, — берём его текст.
     *
     * Остальные три значения такой проверки не требуют: их незачем выдумывать.
     */
    public static function contextFrom(?string $offerType, ?string $offerSummary, ?string $answer): string
    {
        $type = trim((string) $offerType);
        $answer = (string) $answer;

        if ($type !== '' && $type !== 'none') {
            return self::offerContext($type, $offerSummary);
        }

        if (self::looksLikeOffer($answer)) {
            // Тип предложения определяем сами — той же моделью, только применяя
            // её не к сообщению пользователя, а к предложению агента. Словарь
            // у них общий: «добавить виджеты в дашборд» и «подготовить выгрузку
            // в excel» она различает независимо от того, кто это сказал.
            //
            // Понадобилось это потому, что gpt-5-nano поле offer заполняет как
            // придётся: ответ заканчивается словами «добавил эти виджеты
            // в дашборд?», а в поле стоит «none». Прямой запрет в промпте
            // не помог — модель просто не удерживает лишнее поле.
            $inferred = (new self())->inferOffer($answer);

            return $inferred !== null ? self::offerContext($inferred) : $answer;
        }

        return $type !== '' ? self::offerContext($type) : $answer;
    }

    /**
     * О чём предложение агента: dashboard, export или ни о чём конкретном.
     *
     * null означает «уверенности нет» — тогда контекстом останется сам текст
     * ответа, и модель разберётся по нему.
     */
    public function inferOffer(string $answer): ?string
    {
        $model = $this->model();

        if ($model === null) {
            return null;
        }

        // Окно здесь шире, чем при обычном контексте: там нужна одна фраза
        // «Применить?», а тут — суть предложения, и она растянута на два-три
        // предложения. На последней фразе «Могу подготовить конфигурацию под
        // ваш выбор» разобрать нечего, а вместе с предыдущей — «виджет
        // с более длинной цепочкой этапов» — уже понятно, что речь о дашборде.
        $tail = $this->answerTail($answer, (int) config('intents.infer_offer_chars', 220));

        if ($tail === '') {
            return null;
        }

        $prediction = $this->predict($tail);

        if ($prediction === null || $prediction['label'] === self::CHAT) {
            return null;
        }

        return $prediction['confidence'] >= (float) config('intents.infer_offer_threshold', 0.60)
            ? $prediction['label']
            : null;
    }

    /**
     * Похож ли ответ на предложение или вопрос — то есть на приглашение
     * ответить коротко.
     */
    private static function looksLikeOffer(string $answer): bool
    {
        $tail = trim(preg_replace('/\s+/u', ' ', $answer) ?? $answer);

        if ($tail === '') {
            return false;
        }

        return str_ends_with($tail, '?')
            || (bool) preg_match('/\b(могу|хотите|хочешь|применить|давайте|предлагаю|стоит ли)\b/iu', mb_substr($tail, -200, null, 'UTF-8'));
    }

    /**
     * Годится ли фраза в обучающий пример.
     *
     * Отсеивается только бессмыслица: у случайного набора символов верной метки
     * нет вовсе, и запоминать его — значит учить модель на шуме.
     *
     * Реплики вроде «давай» раньше отсеивались тоже — их метка верна лишь
     * в одном разговоре. Теперь они, наоборот, самые ценные: модель видит
     * контекст, и такой пример учит её различать «давай» после предложения
     * применить изменения и «давай» после уточняющего вопроса. Главное —
     * сохранять их вместе с репликой агента.
     */
    public function isLearnable(string $text, ?array $prediction = null): bool
    {
        if ($this->isUnintelligible($text, $prediction)) {
            return false;
        }

        // Короткий мусор («асвамв», «валмвламвл») детектор бессмыслицы не ловит:
        // он не трогает строки короче двенадцати символов, иначе резал бы
        // законные «salom» и «helo». Для обучения планка другая — фраза, ни одна
        // n-грамма которой модели не знакома, всё равно ничего ей не даст,
        // а мусор в обучающем наборе живёт вечно.
        $coverage = $prediction['coverage'] ?? $this->coverage($text);

        if ($coverage === null) {
            return true;
        }

        // Для сообщения из одного слова планка выше, и это не догадка,
        // а замер: настоящие слова дают покрытие 0.79–1.00 («выгрузите» 0.79,
        // «спасибо» 1.00), а набранное вслепую — 0.00–0.15 («валмвламвл» 0.15,
        // «фывафыва» 0.14). Между ними пропасть, и порог ставится в неё.
        //
        // Ограничение по длине оставляет в покое короткие законные слова:
        // «csv», «salom», «helo» — у них покрытие тоже низкое, но просто
        // потому, что символов мало.
        $words = count(preg_split('/\s+/u', trim($text)) ?: []);

        if ($words === 1 && mb_strlen(trim($text), 'UTF-8') >= 6) {
            return $coverage >= (float) config('intents.learning.single_word_min_coverage', 0.40);
        }

        return $coverage >= (float) config('intents.learning.min_coverage', 0.10);
    }

    /**
     * Похоже ли сообщение на случайный набор символов.
     *
     * Классификатор обязан выбрать один из трёх классов — отказаться он не
     * может. На «яавмвфмвмвамвмыаввамывам» он выдавал низкую уверенность,
     * запрос уходил в языковую модель, та выбирала «ответить в чате», и агент
     * добросовестно шёл в базу сочинять SQL по бессмысленной фразе. Пользователю
     * при этом полезнее короткое «уточните, что нужно».
     *
     * Признак берётся из самой модели: доля n-грамм фразы, встречающихся
     * в словаре признаков. У осмысленного русского текста она 0.4–0.9,
     * у случайного набора символов — почти ноль. Это дешёвый детектор
     * выхода за пределы обучающего распределения.
     *
     * Одного покрытия мало: английская фраза «please export all my customers»
     * даёт 0.10, потому что латиницы в обучении почти не было, — а это законный
     * запрос, его нужно отдать языковой модели, а не отбить уточнением.
     * Поэтому вторым условием идёт структура: у случайного набора нет
     * словесного строения — либо это одно длинное «слово», либо повтор
     * нескольких символов по кругу.
     */
    public function isUnintelligible(string $text, ?array $prediction = null): bool
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $length = mb_strlen($normalized, 'UTF-8');

        if ($length === 0) {
            return true;
        }

        // Совсем без букв: «...», «?????», «12345678».
        if (!preg_match('/\p{L}/u', $normalized)) {
            return $length >= (int) config('intents.unintelligible.min_length', 6);
        }

        // Короткие сообщения не трогаем: «да», «norm», «salom», «helo» —
        // законные реплики, у которых покрытие тоже низкое просто из-за длины.
        if ($length < (int) config('intents.unintelligible.min_length', 12)) {
            return false;
        }

        $coverage = $prediction['coverage'] ?? $this->coverage($normalized);

        if ($coverage === null || $coverage >= (float) config('intents.unintelligible.max_coverage', 0.12)) {
            return false;
        }

        return $this->looksStructureless($normalized);
    }

    /**
     * Нет ли у текста словесного строения.
     *
     * Два признака случайного набора: непрерывная цепочка длиной с целую фразу
     * («яавмвфмвмвамвмыаввамывам») и хождение по кругу из нескольких символов
     * («фывафывафыва», «ццццццц») — во втором случае мало различных пар букв.
     */
    private function looksStructureless(string $text): bool
    {
        $words = preg_split('/\s+/u', $text) ?: [];
        $longest = 0;

        foreach ($words as $word) {
            $longest = max($longest, mb_strlen($word, 'UTF-8'));
        }

        if ($longest >= (int) config('intents.unintelligible.max_word_length', 12)) {
            return true;
        }

        $chars = mb_str_split($text, 1, 'UTF-8');
        $bigrams = [];

        for ($i = 0; $i + 1 < count($chars); $i++) {
            $bigrams[] = $chars[$i].$chars[$i + 1];
        }

        if (count($bigrams) < 4) {
            return false;
        }

        $diversity = count(array_unique($bigrams)) / count($bigrams);

        return $diversity < (float) config('intents.unintelligible.min_bigram_diversity', 0.5);
    }

    /**
     * Доля n-грамм текста, известных модели. null — модель недоступна.
     */
    public function coverage(string $text): ?float
    {
        $model = $this->model();

        if ($model === null) {
            return null;
        }

        $total = 0;
        $known = 0;

        foreach ($this->ngrams($this->normalize($text), $model['ngram_min'], $model['ngram_max']) as $gram) {
            $total++;

            // Считаем только по сообщению: покрытие отвечает на вопрос
            // «знакома ли модели эта фраза», а реплика агента тут ни при чём.
            if (isset($model['vocabulary']['m:'.$gram])) {
                $known++;
            }
        }

        return $total > 0 ? $known / $total : 0.0;
    }

    /**
     * Достаточно ли модель уверена, чтобы решать самостоятельно.
     *
     * Порог для «dashboard» выше: ошибочно принять вопрос за команду — значит
     * перестроить пользователю весь экран вместо ответа, тогда как обратная
     * ошибка стоит лишь лишнего абзаца в чате.
     */
    public function isConfident(array $prediction, ?string $offeredType = null): bool
    {
        $base = (float) config('intents.threshold', 0.70);

        // Повышенный порог для «dashboard» защищает от одной конкретной ошибки:
        // принять вопрос за команду и перестроить пользователю экран вместо
        // ответа. Но если систему об этом спросили не мы, а наоборот — она сама
        // предложила изменение и пользователь коротко согласился, — такой
        // ошибки быть не может: он отвечает на наш же вопрос. Держать здесь
        // строгий порог значит платить за вызов языковой модели ради «давай».
        if ($offeredType !== null && $offeredType === $prediction['label']) {
            return $prediction['confidence'] >= $base;
        }

        $threshold = $prediction['label'] === self::DASHBOARD
            ? (float) config('intents.threshold_dashboard', 0.80)
            : $base;

        return $prediction['confidence'] >= $threshold;
    }

    /**
     * Класс намерения по имени задачи роутера — обратное преобразование,
     * нужное, чтобы превратить ответ языковой модели в обучающий пример.
     */
    public static function labelForTask(?string $taskName): ?string
    {
        return match ($taskName) {
            'response_in_chat' => self::CHAT,
            'generate_dashboard', 're_generate_dashboard' => self::DASHBOARD,
            'export_data' => self::EXPORT,
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function labels(): array
    {
        return [self::CHAT, self::DASHBOARD, self::EXPORT];
    }

    public function isAvailable(): bool
    {
        return $this->model() !== null;
    }

    /**
     * Сведения о модели для журнала и интерфейса.
     *
     * @return array<string, mixed>|null
     */
    public function info(): ?array
    {
        $model = $this->model();

        if ($model === null) {
            return null;
        }

        return [
            'created_at' => $model['created_at'] ?? null,
            'classes' => $model['classes'] ?? [],
            'features' => count($model['vocabulary'] ?? []),
            'threshold' => (float) config('intents.threshold', 0.70),
        ];
    }

    /**
     * Сверка с эталонными вероятностями, посчитанными при обучении.
     *
     * Расхождение означает, что нормализация текста или формула TF-IDF здесь
     * разъехались с обучением. Проявилось бы это не ошибкой, а тихо
     * испортившейся маршрутизацией, поэтому проверка вынесена в автотест.
     *
     * @return array{checked: int, max_deviation: float}
     */
    public function selfTest(): array
    {
        $model = $this->model();

        if ($model === null) {
            throw new RuntimeException('Модель классификатора недоступна');
        }

        $cases = $model['selftest'] ?? [];
        $worst = 0.0;

        foreach ($cases as $case) {
            $prediction = $this->compute($model, $case['text'], $case['context'] ?? '');

            foreach ($model['classes'] as $index => $class) {
                $worst = max($worst, abs(
                    $prediction['probabilities'][$class] - (float) $case['proba'][$index]
                ));
            }
        }

        return ['checked' => count($cases), 'max_deviation' => $worst];
    }

    /**
     * Сбрасывает разобранную модель — нужно после переобучения,
     * когда файл на диске заменили новым.
     */
    public static function flush(): void
    {
        self::$model = null;
        self::$loadFailed = false;
        self::$loadedAt = null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function model(): ?array
    {
        $path = config('intents.model_path');

        if (self::$model !== null) {
            // Файл переписали — перечитываем. Проверка стоит копейки
            // по сравнению с разбором трёхсот килобайт JSON.
            if ($path && is_file($path) && @filemtime($path) === self::$loadedAt) {
                return self::$model;
            }

            self::flush();
        } elseif (self::$loadFailed) {
            return null;
        }

        if (!$path || !is_file($path)) {
            self::$loadFailed = true;

            Log::info('IntentClassifier: модель не найдена, маршрутизация идёт через языковую модель', [
                'path' => $path,
            ]);

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded) || empty($decoded['vocabulary']) || empty($decoded['coef'])) {
            self::$loadFailed = true;

            Log::warning('IntentClassifier: файл модели повреждён', ['path' => $path]);

            return null;
        }

        self::$loadedAt = @filemtime($path) ?: null;

        return self::$model = $decoded;
    }

    /**
     * Признаки + softmax. Ровно те же шаги, что делает sklearn при обучении.
     *
     * @return array{label: string, confidence: float, probabilities: array<string, float>}
     */
    private function compute(array $model, string $text, string $context = ''): array
    {
        $vocabulary = $model['vocabulary'];
        $idf = $model['idf'];

        $counts = [];
        $blocks = [];
        // Имя намеренно отличается от $total ниже: там знаменатель softmax,
        // и одноимённая переменная затирала счётчик n-грамм, превращая
        // покрытие в бессмыслицу вроде 1.65.
        $totalGrams = 0;

        foreach ($this->features($model, $text, $context) as $gram) {
            $totalGrams++;

            if (isset($vocabulary[$gram])) {
                $index = $vocabulary[$gram];
                $counts[$index] = ($counts[$index] ?? 0) + 1;
                // Первый символ признака — это его блок: «m:» сообщение,
                // «c:» контекст.
                $blocks[$index] = $gram[0];
            }
        }

        $raw = [];
        $blockNorms = ['m' => 0.0, 'c' => 0.0];
        $knownGrams = array_sum($counts);

        foreach ($counts as $index => $count) {
            // sublinear_tf: длинная фраза не должна перевешивать короткую
            // только за счёт повторов одной и той же n-граммы.
            $tf = ($model['sublinear_tf'] ?? true) ? 1.0 + log($count) : (float) $count;
            $value = $tf * (float) $idf[$index];

            $raw[$index] = $value;
            $blockNorms[$blocks[$index]] += $value * $value;
        }

        // Блоки нормируются раздельно, и лишь потом контекст умножается на вес.
        // Так его влияние равно ровно context_weight и не зависит от того,
        // насколько длинной вышла реплика агента: при общей нормировке
        // короткое «не надо» тонуло в длинном предложении и читалось как
        // согласие.
        $weight = (float) ($model['context_weight'] ?? 1.0);
        $vector = [];
        $norm = 0.0;

        foreach ($raw as $index => $value) {
            $block = $blocks[$index];
            $blockNorm = sqrt($blockNorms[$block]);

            if ($blockNorm <= 0.0) {
                continue;
            }

            $value = $value / $blockNorm * ($block === 'c' ? $weight : 1.0);

            $vector[$index] = $value;
            $norm += $value * $value;
        }

        $norm = sqrt($norm);

        if ($norm > 0.0) {
            foreach ($vector as $index => $value) {
                $vector[$index] = $value / $norm;
            }
        }

        $scores = [];

        foreach ($model['coef'] as $classIndex => $weights) {
            $score = (float) $model['intercept'][$classIndex];

            foreach ($vector as $index => $value) {
                $score += $value * (float) $weights[$index];
            }

            $scores[$classIndex] = $score;
        }

        // Вычитание максимума перед экспонентой — защита от переполнения.
        $max = max($scores);
        $exponents = [];
        $total = 0.0;

        foreach ($scores as $classIndex => $score) {
            $value = exp($score - $max);
            $exponents[$classIndex] = $value;
            $total += $value;
        }

        $probabilities = [];
        $bestClass = null;
        $bestValue = -1.0;

        foreach ($exponents as $classIndex => $value) {
            $probability = $value / $total;
            $class = $model['classes'][$classIndex];
            $probabilities[$class] = $probability;

            if ($probability > $bestValue) {
                $bestValue = $probability;
                $bestClass = $class;
            }
        }

        return [
            'label' => $bestClass,
            'confidence' => $bestValue,
            'probabilities' => $probabilities,
            // Доля признаков фразы, знакомых модели. Низкая означает, что текст
            // лежит за пределами обучающего распределения, и предсказанию
            // верить нельзя, каким бы уверенным оно ни выглядело.
            'coverage' => $totalGrams > 0 ? $knownGrams / $totalGrams : 0.0,
        ];
    }

    /**
     * Признаки документа: n-граммы сообщения и n-граммы контекста в разных
     * пространствах. Эталон — ml/intents/common.py::features().
     *
     * Префиксы «m:» и «c:» обязательны. Без них слово «выгрузи», сказанное
     * агентом, попадало бы в тот же признак, что и просьба пользователя
     * выгрузить данные, — и различить их было бы нечем.
     *
     * @return \Generator<string>
     */
    private function features(array $model, string $text, string $context = ''): \Generator
    {
        foreach ($this->ngrams($this->normalize($text), $model['ngram_min'], $model['ngram_max']) as $gram) {
            yield 'm:'.$gram;
        }

        $tail = $this->contextTail($context, (int) ($model['context_chars'] ?? 80));

        if ($tail === '') {
            return;
        }

        foreach ($this->ngrams($this->normalize($tail), $model['ngram_min'], $model['ngram_max']) as $gram) {
            yield 'c:'.$gram;
        }
    }

    /**
     * Конец ответа без разметки — окно произвольной длины.
     */
    private function answerTail(string $answer, int $limit): string
    {
        $text = preg_replace('/[*_`#>\[\]()|\-]+/u', ' ', $answer) ?? $answer;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }

        $tail = mb_substr($text, -$limit, null, 'UTF-8');
        $space = mb_strpos($tail, ' ', 0, 'UTF-8');

        return $space !== false ? mb_substr($tail, $space + 1, null, 'UTF-8') : $tail;
    }

    /**
     * Последнее предложение реплики агента. Эталон — common.py::context_tail().
     *
     * Именно в конце ответа стоит предложение действия («Применить?») или
     * уточняющий вопрос — то, что и определяет смысл короткого ответа
     * пользователя. Разбор данных выше по тексту только зашумляет признаки.
     */
    private function contextTail(string $answer, int $limit): string
    {
        $text = preg_replace('/[*_`#>\[\]()|\-]+/u', ' ', $answer) ?? $answer;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($text === '') {
            return '';
        }

        $sentences = array_values(array_filter(
            array_map('trim', preg_split('/[.!?…]+/u', $text) ?: []),
            fn ($part) => $part !== ''
        ));

        if ($sentences) {
            $text = end($sentences);
        }

        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }

        $tail = mb_substr($text, -$limit, null, 'UTF-8');

        // Не начинаем с середины слова: обрезок «дить карточки» дал бы модели
        // n-граммы, которых в языке не бывает.
        $space = mb_strpos($tail, ' ', 0, 'UTF-8');

        return ($space !== false && $space < 20)
            ? mb_substr($tail, $space + 1, null, 'UTF-8')
            : $tail;
    }

    /**
     * Нормализация текста. Эталон — ml/intents/common.py::normalize().
     */
    private function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace('ё', 'е', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return ' '.trim($text).' ';
    }

    /**
     * Символьные n-граммы. Эталон — ml/intents/common.py::char_ngrams().
     *
     * @return \Generator<string>
     */
    private function ngrams(string $text, int $min, int $max): \Generator
    {
        $chars = mb_str_split($text, 1, 'UTF-8');
        $length = count($chars);

        for ($n = $min; $n <= $max; $n++) {
            for ($i = 0; $i + $n <= $length; $i++) {
                yield implode('', array_slice($chars, $i, $n));
            }
        }
    }
}
