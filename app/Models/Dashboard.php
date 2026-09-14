<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dashboard extends Model
{

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

    protected $attributes = [
        'origin' => self::ORIGIN_AI,
    ];

    public const ORIGIN_AI = 'ai';

    public const ORIGIN_MANUAL = 'manual';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function widgets(){
        return $this->hasMany(DashboardWidget::class, 'dashboard_id');
    }

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

    public function resolveDataSource(array $with = ['type', 'extracted']): ?DataSource
    {
        if ($this->data_source_id) {
            return DataSource::query()->with($with)->find($this->data_source_id);
        }

        $chat = $this->relationLoaded('chat') ? $this->chat : $this->chat()->first();

        return $chat?->resolveDataSource($with);
    }
}
