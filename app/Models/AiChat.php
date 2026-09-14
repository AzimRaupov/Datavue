<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiChat extends Model
{

    protected $fillable = [
        'user_id',
        'company_id',
        'workspace_id',
        'data_source_id',
        'title',
        'status',
    ];

    protected function casts(): array
    {
        return [];
    }
    public function extractedData(): HasOne
    {
        return $this->hasOne(DataSourceExtraction::class, 'chat_id');
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function resolveDataSource(array $with = ['type', 'extracted']): ?DataSource
    {
        if ($this->data_source_id) {
            return DataSource::query()->with($with)->find($this->data_source_id);
        }

        return DataSource::query()
            ->with($with)
            ->where('chat_id', $this->id)
            ->first();
    }
    public function dashboard(): HasOne
    {
        return $this->hasOne(Dashboard::class, 'chat_id');
    }
    public function dashboards(): HasMany
    {
        return $this->hasMany(Dashboard::class, 'chat_id');
    }
    public function tasks(): HasMany
    {
        return $this->hasMany(AiChatTask::class, 'chat_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class, 'chat_id');
    }
}
