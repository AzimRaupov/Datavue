<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фильтры виджетов: чем пользователь может управлять на готовом дашборде.
 *
 * До сих пор виджет был статичной картинкой: что сгенерировали — то и видно.
 * Большой набор данных приходилось резать LIMIT'ом, причём молча, и часть
 * данных просто пропадала.
 *
 * Два уровня:
 *   widget_filters            — каталог доступных фильтров (справочник платформы)
 *   dashboard_widget_filters  — какие из них включены у конкретного виджета
 *
 * Каталогом фильтр описывает себя сам: какие параметры принимает, каким
 * семействам подходит, каким назначается обязательно. Поэтому добавление
 * нового фильтра — строка в сидере, а не правки по всему коду.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_filters', function (Blueprint $table) {
            $table->id();

            // Ключ, по которому фильтр опознаётся в коде и в спецификации:
            // paginate, search, date_range, day.
            $table->string('key', 40)->unique();

            $table->string('label');
            $table->text('description')->nullable();

            // Как фильтр применяется:
            //   wrapper — платформа оборачивает базовый запрос (пагинация, поиск);
            //   query   — условие пишет сам SQL виджета через плейсхолдеры (даты).
            $table->enum('applies_as', ['wrapper', 'query'])->default('wrapper');

            // Имена параметров, которые фильтр принимает от фронта.
            $table->json('params')->nullable();

            /**
             * Семейства, которым фильтр включается ОБЯЗАТЕЛЬНО и без участия ИИ.
             * Таблице всегда нужны пагинация и поиск — спрашивать про это модель
             * бессмысленно и дорого.
             */
            $table->json('required_for')->nullable();

            /**
             * Требует ли фильтр даты в схеме. Если да — предлагается модели
             * только тогда, когда в таблицах виджета реально есть дата.
             * Это и есть защита промпта от лишнего: кандидаты отбираются
             * механически, до обращения к модели.
             */
            $table->boolean('requires_date_column')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();
        });

        Schema::create('dashboard_widget_filters', function (Blueprint $table) {
            $table->id();

            $table->foreignId('dashboard_widget_id')
                ->constrained('dashboard_widgets')
                ->cascadeOnDelete();

            $table->string('filter_key', 40);

            // Настройки под конкретный виджет: колонки поиска, размер страницы,
            // значения по умолчанию для дат.
            $table->json('config')->nullable();

            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['dashboard_widget_id', 'filter_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widget_filters');
        Schema::dropIfExists('widget_filters');
    }
};
