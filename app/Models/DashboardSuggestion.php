<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardSuggestion extends Model
{
    protected $fillable = [
        'data_source_id',
        'title',
        'prompt',
        'description',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
