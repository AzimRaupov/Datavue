<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)

    ->in('Feature');

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

function something()
{

}


/*
| Помощники для тестов, которым нужно «уронить» и «починить» почту.
*/

/** Почта «упала»: любая отправка заканчивается ошибкой транспорта. */
function smtpDown(): void
{
    \Illuminate\Support\Facades\Mail::extend('outage', fn () => new class extends \Symfony\Component\Mailer\Transport\AbstractTransport {
        protected function doSend(\Symfony\Component\Mailer\SentMessage $message): void
        {
            throw new \Symfony\Component\Mailer\Exception\TransportException('SMTP-сервер недоступен');
        }

        public function __toString(): string
        {
            return 'outage';
        }
    });

    config(['mail.default' => 'outage', 'mail.mailers.outage' => ['transport' => 'outage']]);
    \Illuminate\Support\Facades\Mail::forgetMailers();
}

/** Почта «починилась»: письма собираются в памяти, их можно проверить через Mail::assertSent. */
function smtpUp(): void
{
    config(['mail.default' => 'array']);
    \Illuminate\Support\Facades\Mail::forgetMailers();
    \Illuminate\Support\Facades\Mail::fake();
}
