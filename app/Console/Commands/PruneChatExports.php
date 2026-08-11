<?php

namespace App\Console\Commands;

use App\Models\ChatExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Удаляет протухшие выгрузки вместе с файлами.
 *
 * Без этого каталог компании растёт бесконечно: каждая просьба «выгрузи в
 * excel» оставляет файл навсегда, хотя ссылка на него перестаёт работать
 * через exports.ttl_days.
 */
class PruneChatExports extends Command
{
    protected $signature = 'exports:prune {--dry-run : Только показать, что будет удалено}';

    protected $description = 'Удаляет выгрузки чата с истёкшим сроком жизни';

    public function handle(): int
    {
        $expired = ChatExport::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        if ($expired->isEmpty()) {
            $this->info('Протухших выгрузок нет.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $removed = 0;

        foreach ($expired as $export) {
            $this->line(($dryRun ? '[dry-run] ' : '').'#'.$export->id.' '.$export->file_name);

            if ($dryRun) {
                continue;
            }

            try {
                // Каталог создаётся под каждую выгрузку отдельно — удаляем его
                // целиком вместе с файлом и сохранённым скриптом.
                $directory = dirname($export->path);

                if (is_dir($directory) && str_contains($directory, '/exports/')) {
                    File::deleteDirectory($directory);
                }

                $export->delete();
                $removed++;
            } catch (Throwable $e) {
                $this->error('Не удалось удалить #'.$export->id.': '.$e->getMessage());
            }
        }

        $this->info($dryRun
            ? 'Найдено выгрузок к удалению: '.$expired->count()
            : 'Удалено выгрузок: '.$removed);

        return self::SUCCESS;
    }
}
