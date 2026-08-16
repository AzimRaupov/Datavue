<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Рабочее пространство — задача, над которой работают: свой источник данных,
 * свои дашборды и свой разговор с агентом.
 *
 * Пространство заводит человек, а не система: «Продажи» и «Склад» на одной
 * и той же базе — разная работа разных людей, и складывать их вместе только
 * потому, что таблицы лежат в одной базе, неправильно.
 */
class Workspace extends Model
{
    protected $fillable = [
        'company_id',
        'created_by',
        'data_source_id',
        'name',
        'description',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function dashboards(): HasMany
    {
        return $this->hasMany(Dashboard::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(AiChat::class);
    }

    /**
     * Разговор пространства.
     *
     * Он один: все дашборды внутри — про одну задачу, и держать под каждый
     * свою переписку значит терять контекст ровно там, где он нужен. Колонка
     * при этом допускает несколько — так пережил переезд источник, у которого
     * до пространств было заведено много чатов.
     */
    public function chat(): ?AiChat
    {
        return $this->chats()->orderBy('id')->first();
    }

    public function scopeOfCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
