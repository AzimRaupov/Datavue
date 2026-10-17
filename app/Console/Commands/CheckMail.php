<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

class CheckMail extends Command
{
    protected $signature = 'mail:check
        {--to= : Куда отправить тестовое письмо (по умолчанию — адрес отправителя MAIL_FROM_ADDRESS)}
        {--no-send : Только проверить настройки и вход на SMTP-сервер, письмо не отправлять}';

    protected $description = 'Проверяет почту: настройки, подключение и вход на SMTP-сервер, тестовое письмо';

    private int $problems = 0;

    public function handle(): int
    {
        $mailer = (string) config('mail.default');
        $config = (array) config("mail.mailers.{$mailer}", []);

        $this->line('<options=bold>Настройки почты</>');
        $this->line("  Способ отправки (MAIL_MAILER): {$mailer}");

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->problem(
                "MAIL_MAILER={$mailer}: письма НЕ отправляются, а {$this->where($mailer)}. "
                .'Для настоящей почты поставьте MAIL_MAILER=smtp и заполните MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD.'
            );

            return self::FAILURE;
        }

        $from = (string) config('mail.from.address');

        if ($mailer === 'smtp') {
            $this->line('  Сервер: '.($config['host'] ?? '—').':'.($config['port'] ?? '—')
                .'  шифрование: '.($config['scheme'] ?? $config['encryption'] ?? 'по умолчанию'));
            $this->line('  Логин: '.($config['username'] ?: '— не задан —'));
            $this->line('  Пароль: '.(empty($config['password']) ? '— не задан —' : 'задан ('.strlen((string) $config['password']).' симв.)'));
        }

        $this->line("  Отправитель: {$from} (".config('mail.from.name').')');

        if (str_ends_with($from, '@example.com')) {
            $this->problem('MAIL_FROM_ADDRESS — заглушка example.com: почтовые сервисы такие письма отклоняют или кладут в спам.');
        }

        if ($mailer === 'smtp' && !$this->checkSmtpLogin()) {
            return self::FAILURE;
        }

        if ($this->option('no-send')) {
            return $this->problems === 0 ? self::SUCCESS : self::FAILURE;
        }

        $to = (string) ($this->option('to') ?: $from);

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->problem("«{$to}» не похоже на адрес почты.");

            return self::FAILURE;
        }

        return $this->sendTest($to) && $this->problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkSmtpLogin(): bool
    {
        $this->newLine();
        $this->line('<options=bold>Подключение к SMTP</>');

        try {
            $transport = Mail::mailer()->getSymfonyTransport();

            if (!$transport instanceof EsmtpTransport) {
                $this->line('  (проверка входа доступна только для SMTP)');

                return true;
            }

            $started = microtime(true);
            // start() открывает соединение, включает шифрование и входит под логином/паролем.
            $transport->start();
            $transport->stop();

            $this->info(sprintf('  OK: сервер ответил и принял логин за %.1f с.', microtime(true) - $started));

            return true;
        } catch (Throwable $e) {
            $this->problem('Не удалось войти на SMTP-сервер: '.$this->short($e));
            $this->line('  '.$this->hint($e));

            return false;
        }
    }

    private function sendTest(string $to): bool
    {
        $this->newLine();
        $this->line("<options=bold>Тестовое письмо → {$to}</>");

        try {
            $started = microtime(true);

            Mail::raw(
                'Это проверочное письмо из '.config('app.name').".\n"
                .'Сервер: '.gethostname().', время: '.now()->format('d.m.Y H:i:s')."\n"
                .'Если вы его читаете — почта работает.',
                fn ($message) => $message->to($to)->subject(config('app.name').': проверка почты')
            );

            $this->info(sprintf('  OK: письмо принято почтовым сервером за %.1f с. Проверьте ящик (и папку «Спам»).', microtime(true) - $started));

            return true;
        } catch (Throwable $e) {
            $this->problem('Письмо не отправилось: '.$this->short($e));
            $this->line('  '.$this->hint($e));

            return false;
        }
    }

    private function problem(string $message): void
    {
        $this->problems++;
        $this->error('  ✗ '.$message);
    }

    private function where(string $mailer): string
    {
        return $mailer === 'log' ? 'записываются в storage/logs/laravel.log' : 'остаются в памяти (тестовый режим)';
    }

    private function short(Throwable $e): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 300);
    }

    /** Подсказка по самым частым причинам. */
    private function hint(Throwable $e): string
    {
        $text = mb_strtolower($e->getMessage());

        return match (true) {
            str_contains($text, '535'), str_contains($text, 'authenticat'), str_contains($text, 'username and password')
                => 'Похоже, неверный логин или пароль. Для Gmail нужен «пароль приложения» (16 символов), а не обычный пароль от почты.',
            str_contains($text, 'connection could not be established'), str_contains($text, 'timed out'), str_contains($text, 'connection refused')
                => 'Нет связи с сервером: проверьте MAIL_HOST и MAIL_PORT и что исходящий порт не закрыт файрволом хостинга (часто блокируют 25).',
            str_contains($text, 'certificate'), str_contains($text, 'ssl'), str_contains($text, 'tls')
                => 'Проблема с шифрованием: для порта 587 нужен STARTTLS, для 465 — SSL (MAIL_SCHEME=smtps).',
            default => 'Подробности — в storage/logs/laravel.log.',
        };
    }
}
