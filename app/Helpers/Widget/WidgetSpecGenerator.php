<?php

namespace App\Helpers\Widget;

use App\Helpers\Ai\Dashboard\WidgetSpecAi;
use App\Helpers\Ai\WidgetQueryAi;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use Illuminate\Support\Facades\Log;

class WidgetSpecGenerator
{

    private const MAX_ATTEMPTS = 3;

    private WidgetSpecAi $planner;
    private WidgetQueryAi $sqlAi;
    private WidgetQueryComposer $composer;
    private WidgetSpecValidator $validator;

    public function __construct(private DataSource $dataSource)
    {
        $this->planner = new WidgetSpecAi();
        $this->sqlAi = new WidgetQueryAi($dataSource);
        $this->composer = new WidgetQueryComposer($dataSource);
        $this->validator = new WidgetSpecValidator($dataSource);
    }

    public function generate(DashboardWidget $widget, array $tablesScheme): array
    {
        $family = $widget->widget->name;
        $type = $widget->widgetType->name ?? $widget->effectiveType()?->name;
        $instruction = (string) $widget->instruction;

        $schema = $this->compactSchema($tablesScheme);
        $slots = WidgetQueryComposer::slotsFor($family, $type);

        $spentTokens = 0;

        if (count($schema) === 1) {
            $result = $this->viaBuilder($widget, $instruction, $family, $type, $schema, $slots);

            if ($result['ok'] || !$result['fallback']) {
                return $this->strip($result);
            }

            $spentTokens = $result['tokens'];

            Log::info('WidgetSpecGenerator: переходим на текстовый запрос', [
                'widget_id' => $widget->id,
                'reason' => $result['error'],
            ]);
        }

        return $this->strip(
            $this->viaSql($widget, $instruction, $family, $type, $tablesScheme, $spentTokens)
        );
    }

    public function repair(
        DashboardWidget $widget,
        array $tablesScheme,
        array $brokenSpec,
        string $error
    ): array {
        $family = $widget->widget->name;
        $type = $widget->widgetType->name ?? $widget->effectiveType()?->name;
        $instruction = (string) $widget->instruction;

        if (($brokenSpec['mode'] ?? null) === DashboardWidget::MODE_BUILDER) {
            $schema = $this->compactSchema($tablesScheme);
            $slots = WidgetQueryComposer::slotsFor($family, $type);

            $result = $this->viaBuilder(
                $widget,
                $instruction,
                $family,
                $type,
                $schema,
                $slots,
                $brokenSpec['builder'] ?? [],
                $error
            );

            if ($result['ok']) {
                return $this->strip($result);
            }
        }

        return $this->strip(
            $this->viaSql($widget, $instruction, $family, $type, $tablesScheme, 0, $brokenSpec, $error)
        );
    }

    private function viaBuilder(
        DashboardWidget $widget,
        string $instruction,
        string $family,
        ?string $type,
        array $schema,
        array $slots,
        ?array $brokenBuilder = null,
        ?string $initialError = null
    ): array {
        $builder = $brokenBuilder;
        $error = $initialError;
        $tokens = 0;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $answer = $error !== null && $builder !== null
                ? $this->planner->repair($instruction, $family, $type, $schema, $slots, $builder, $error)
                : $this->planner->plan($instruction, $family, $type, $schema, $slots);

            $tokens += $answer['total_tokens'] ?? 0;

            if (!empty($answer['api_error'])) {
                Log::warning('WidgetSpecGenerator: модель не ответила', [
                    'widget_id' => $widget->id,
                    'attempt' => $attempt,
                    'error' => $answer['api_error'],
                ]);

                usleep(1_500_000);

                continue;
            }

            if (!empty($answer['needs_sql'])) {
                return $this->builderResult(false, null, $answer['message'] ?? 'Нужен запрос текстом', $attempt, $tokens, fallback: true);
            }

            if (!$answer['ok']) {
                $error = $answer['message'] ?? 'Модель не вернула настройки.';

                continue;
            }

            $builder = $answer['builder'];

            $composed = $this->composer->compose($builder, $family, $type);

            if (!$composed['ok']) {
                $error = $composed['errors'][0] ?? 'Не удалось собрать запрос.';

                Log::info('WidgetSpecGenerator: настройки не собрались', [
                    'widget_id' => $widget->id,
                    'attempt' => $attempt,
                    'error' => $error,
                ]);

                continue;
            }

            $spec = WidgetSpecValidator::build($family, $composed['sql'], $composed['presentation'] ?? []);
            $spec['mode'] = DashboardWidget::MODE_BUILDER;
            $spec['builder'] = $builder;

            $check = $this->validator->validate($spec, $family, $type);

            if ($check['ok']) {
                return $this->builderResult(true, $spec, null, $attempt, $tokens, fallback: false);
            }

            $error = $check['errors'][0] ?? 'Запрос не прошёл проверку.';
        }

        return $this->builderResult(false, null, $error, self::MAX_ATTEMPTS, $tokens, fallback: true);
    }

    private function viaSql(
        DashboardWidget $widget,
        string $instruction,
        string $family,
        ?string $type,
        array $tablesScheme,
        int $tokensSoFar = 0,
        ?array $brokenSpec = null,
        ?string $initialError = null
    ): array {
        $spec = $brokenSpec;
        $error = $initialError;
        $tokens = $tokensSoFar;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $isRepair = $error !== null && $spec !== null;

            $answer = $isRepair
                ? $this->sqlAi->repair($instruction, $family, $type, $tablesScheme, $spec, $error, [])
                : $this->sqlAi->generate($instruction, $family, $type, $tablesScheme, []);

            $tokens += $answer['total_tokens'] ?? 0;

            if (!empty($answer['api_error'])) {
                usleep(1_500_000);

                continue;
            }

            if (empty($answer['spec'])) {
                $error = $answer['message'] ?? 'Модель не вернула запрос.';

                continue;
            }

            $spec = $answer['spec'];

            $spec['shape'] = WidgetShapeMapper::shapeFor($family);
            $spec['mode'] = DashboardWidget::MODE_SQL;

            $check = $this->validator->validate($spec, $family, $type);

            if ($check['ok']) {
                return [
                    'ok' => true,
                    'spec' => $spec,
                    'mode' => DashboardWidget::MODE_SQL,
                    'error' => null,
                    'attempts' => $attempt,
                    'tokens' => $tokens,
                ];
            }

            $error = $check['errors'][0] ?? 'Запрос не прошёл проверку.';

            Log::info('WidgetSpecGenerator: запрос не прошёл проверку', [
                'widget_id' => $widget->id,
                'attempt' => $attempt,
                'error' => $error,
            ]);
        }

        return [
            'ok' => false,
            'spec' => $spec,
            'mode' => DashboardWidget::MODE_SQL,
            'error' => $error,
            'attempts' => self::MAX_ATTEMPTS,
            'tokens' => $tokens,
        ];
    }

    private function compactSchema(array $tablesScheme): array
    {
        $schema = [];

        foreach ($tablesScheme as $table => $definition) {
            $columns = $definition['columns'] ?? $definition;

            if (!is_array($columns)) {
                continue;
            }

            foreach ($columns as $name => $meta) {
                if (is_int($name)) {

                    $schema[$table][(string) $meta] = ['type' => 'unknown'];

                    continue;
                }

                if (!is_array($meta)) {
                    $schema[$table][$name] = ['type' => (string) $meta];

                    continue;
                }

                $entry = ['type' => (string) ($meta['type'] ?? 'unknown')];

                if (!empty($meta['sample_values'])) {
                    $entry['samples'] = array_values(array_map('strval', $meta['sample_values']));
                }

                $schema[$table][$name] = $entry;
            }
        }

        return $schema;
    }

    private function builderResult(bool $ok, ?array $spec, ?string $error, int $attempts, int $tokens, bool $fallback): array
    {
        return [
            'ok' => $ok,
            'spec' => $spec,
            'mode' => DashboardWidget::MODE_BUILDER,
            'error' => $error,
            'attempts' => $attempts,
            'tokens' => $tokens,
            'fallback' => $fallback,
        ];
    }

    private function strip(array $result): array
    {
        unset($result['fallback']);

        return $result;
    }
}
