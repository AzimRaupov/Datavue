<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Провайдер источника данных.
 *
 * Описывает себя настолько полно, чтобы мастер подключения мог построить
 * по нему форму, не зная про конкретный провайдер ничего заранее.
 */
class DataSourceType extends Model
{
    /** Источник загружается файлом. */
    public const KIND_FILE = 'file';

    /** Внешняя СУБД: хост, порт, логин, пароль. */
    public const KIND_DATABASE = 'database';

    /** Внешний сервис по ссылке (например, Google Таблицы). */
    public const KIND_API = 'api';

    protected $fillable = [
        'name',
        'label',
        'description',
        'kind',
        'icon',
        'default_port',
        'is_active',
        'position',
    ];

    protected $casts = [
        'default_port' => 'integer',
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    /** Провайдеры, доступные пользователю в мастере. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isFile(): bool
    {
        return $this->kind === self::KIND_FILE;
    }

    public function isDatabase(): bool
    {
        return $this->kind === self::KIND_DATABASE;
    }

    public function isApi(): bool
    {
        return $this->kind === self::KIND_API;
    }

    /**
     * Подпись для интерфейса. Старые записи могли остаться без label —
     * тогда показываем техническое имя, а не пустоту.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->label ?: $this->name;
    }
}
