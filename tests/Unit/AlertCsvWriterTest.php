<?php

use App\Helpers\Alert\AlertCsvWriter;

uses(Tests\TestCase::class);

function readCsv(string $path, string $delimiter = ';'): array
{
    $content = file_get_contents($path);

    expect(substr($content, 0, 3))->toBe("\xEF\xBB\xBF");

    $lines = array_filter(explode("\n", trim(substr($content, 3))), fn ($l) => $l !== '');

    return array_map(fn ($line) => str_getcsv($line, $delimiter), $lines);
}

it('пишет заголовок по ключам первой строки и BOM для Excel', function () {
    $path = sys_get_temp_dir().'/alert_csv_'.uniqid().'.csv';

    $count = AlertCsvWriter::write([
        ['label' => 'Москва', 'value' => 10],
        ['label' => 'Питер', 'value' => 7],
    ], $path);

    expect($count)->toBe(2);

    $rows = readCsv($path);
    expect($rows[0])->toBe(['label', 'value'])
        ->and($rows[1])->toBe(['Москва', '10'])
        ->and($rows[2])->toBe(['Питер', '7']);

    @unlink($path);
});

it('создаёт пустой файл с одним BOM, когда строк нет', function () {
    $path = sys_get_temp_dir().'/alert_csv_'.uniqid().'.csv';

    $count = AlertCsvWriter::write([], $path);

    expect($count)->toBe(0)
        ->and(file_get_contents($path))->toBe("\xEF\xBB\xBF");

    @unlink($path);
});

it('сериализует вложенные значения вместо предупреждения PHP', function () {
    $path = sys_get_temp_dir().'/alert_csv_'.uniqid().'.csv';

    AlertCsvWriter::write([
        ['name' => 'заказ', 'meta' => ['id' => 1, 'ok' => true]],
    ], $path);

    $rows = readCsv($path);
    expect($rows[1][0])->toBe('заказ')
        ->and($rows[1][1])->toContain('"id":1');

    @unlink($path);
});

it('создаёт недостающие каталоги на пути к файлу', function () {
    $dir = sys_get_temp_dir().'/alert_csv_dir_'.uniqid();
    $path = $dir.'/nested/result.csv';

    AlertCsvWriter::write([['a' => 1]], $path);

    expect(is_file($path))->toBeTrue();

    \Illuminate\Support\Facades\File::deleteDirectory($dir);
});
