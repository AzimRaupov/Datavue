<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Превращает data_source_types в полноценный справочник провайдеров.
 *
 * Раньше в таблице были только техническое имя и описание, поэтому фронт
 * знал про провайдеров ровно два факта — «mysql» и «postgres» — а всё
 * остальное (подпись, порт по умолчанию, какую форму рисовать) было
 * захардкожено в компоненте. Добавить Google Таблицы, не трогая фронт,
 * было невозможно.
 *
 * Теперь провайдер описывает себя сам:
 *   kind          — какую форму показывать (файл / база / внешний сервис);
 *   label         — как назвать в интерфейсе;
 *   icon          — ключ иконки на фронте;
 *   default_port  — подставляется в поле порта;
 *   is_active     — провайдер виден в мастере;
 *   position      — порядок в списке.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_source_types', function (Blueprint $table) {
            $table->string('label')->nullable()->after('name');

            // file    — источник загружается файлом
            // database — внешняя СУБД: хост, порт, логин
            // api     — внешний сервис по ссылке или ключу (Google Таблицы)
            $table->enum('kind', ['file', 'database', 'api'])
                ->default('database')
                ->after('label');

            $table->string('icon', 50)->nullable()->after('kind');
            $table->unsignedSmallInteger('default_port')->nullable()->after('icon');
            $table->boolean('is_active')->default(true)->after('default_port');
            $table->unsignedSmallInteger('position')->default(0)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('data_source_types', function (Blueprint $table) {
            $table->dropColumn([
                'label',
                'kind',
                'icon',
                'default_port',
                'is_active',
                'position',
            ]);
        });
    }
};
