<?php

use App\Helpers\Export\ExportFormat;

/**
 * Формат файла приходит из свободного текста — и от пользователя, и от модели.
 * Ошибка здесь означает, что человек просил pdf, а получил csv.
 */

// Потолки строк живут в config/exports.php, а Unit-набор по умолчанию
// приложение не поднимает — этому файлу контейнер нужен.
uses(Tests\TestCase::class);

it('распознаёт формат в просьбе пользователя', function (string $text, string $expected) {
    expect(ExportFormat::detect($text))->toBe($expected);
})->with([
    ['Топ 10 клиентов посчитай и сохрани в csv', ExportFormat::CSV],
    ['выгрузи заказы за год в excel', ExportFormat::XLSX],
    ['сохрани в эксель', ExportFormat::XLSX],
    ['сделай отчёт в PDF', ExportFormat::PDF],
    ['сохрани это в пдф', ExportFormat::PDF],
    ['сохрани в word', ExportFormat::DOCX],
    ['вордовский файл сделай', ExportFormat::DOCX],
    ['сохрани в docx', ExportFormat::DOCX],
    ['выгрузи в xlsx', ExportFormat::XLSX],
]);

it('не находит формат там, где его не называли', function () {
    expect(ExportFormat::detect('покажи топ 10 клиентов'))->toBeNull();
});

it('при нескольких форматах выбирает названный первым', function () {
    // «сохрани в pdf, csv не нужен» — решает то, что пользователь поставил вперёд.
    expect(ExportFormat::detect('сохрани в pdf, csv не нужен'))->toBe(ExportFormat::PDF);
});

it('нормализует ответ модели к каноническому формату', function () {
    expect(ExportFormat::normalize('Excel (xlsx)'))->toBe(ExportFormat::XLSX)
        ->and(ExportFormat::normalize('xls'))->toBe(ExportFormat::XLSX)
        ->and(ExportFormat::normalize('Word'))->toBe(ExportFormat::DOCX)
        ->and(ExportFormat::normalize('PDF'))->toBe(ExportFormat::PDF)
        ->and(ExportFormat::normalize(''))->toBe(ExportFormat::CSV)
        ->and(ExportFormat::normalize(null))->toBe(ExportFormat::CSV)
        ->and(ExportFormat::normalize('чепуха'))->toBe(ExportFormat::CSV);
});

it('отдаёт расширение и mime под формат', function () {
    expect(ExportFormat::extension('excel'))->toBe('xlsx')
        ->and(ExportFormat::mime('pdf'))->toBe('application/pdf')
        ->and(ExportFormat::mime('csv'))->toStartWith('text/csv');
});

it('держит потолок строк для документов ниже, чем для таблиц', function () {
    config()->set('exports.max_rows', 100000);
    config()->set('exports.pdf_max_rows', 2000);
    config()->set('exports.docx_max_rows', 5000);

    expect(ExportFormat::rowLimit(ExportFormat::CSV))->toBe(100000)
        ->and(ExportFormat::rowLimit(ExportFormat::PDF))->toBe(2000)
        ->and(ExportFormat::rowLimit(ExportFormat::DOCX))->toBe(5000);
});

it('не позволяет формату превысить общий потолок строк', function () {
    // Общий лимит снизили — частные лимиты форматов обязаны за ним последовать,
    // иначе PDF на слабом воркере продолжит собирать 2000 строк вместо 500.
    config()->set('exports.max_rows', 500);
    config()->set('exports.pdf_max_rows', 2000);

    expect(ExportFormat::rowLimit(ExportFormat::PDF))->toBe(500);
});
