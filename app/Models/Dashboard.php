<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dashboard extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'workspace_id',
        'created_by',
        'chat_id',
        'data_source_id',
        'name',
        'status',
        'origin',
        'description',
        'version',
    ];

    /**
     * Значение по умолчанию есть и в схеме, но модель о нём должна знать сама:
     * после create() без origin объект в памяти иначе остаётся с null, и
     * проверка isManual() зависела бы от того, перечитали строку из базы или нет.
     */
    protected $attributes = [
        'origin' => self::ORIGIN_AI,
    ];

    /** Дашборд собрал пайплайн ИИ по сообщению в чате. */
    public const ORIGIN_AI = 'ai';

    /** Дашборд собрал человек в конструкторе. */
    public const ORIGIN_MANUAL = 'manual';

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    /**
     * Get the company that owns the dashboard.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function widgets(){
        return $this->hasMany(DashboardWidget::class, 'dashboard_id');
    }

    /**
     * Рабочее пространство, в котором лежит дашборд.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(AiChat::class, 'chat_id');
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isManual(): bool
    {
        return $this->origin === self::ORIGIN_MANUAL;
    }

    /**
     * Источник данных, по которому считаются виджеты этого дашборда.
     *
     * Порядок важен: у ручного дашборда источник указан прямо на нём, у
     * сгенерированного — приходит из чата. Пока оба пути живы, дашборды,
     * созданные до появления конструктора, продолжают работать без правок
     * данных.
     */
    public function resolveDataSource(array $with = ['type', 'extracted']): ?DataSource
    {
        if ($this->data_source_id) {
            return DataSource::query()->with($with)->find($this->data_source_id);
        }

        $chat = $this->relationLoaded('chat') ? $this->chat : $this->chat()->first();

        return $chat?->resolveDataSource($with);
    }
}
