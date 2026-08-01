<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSourceGroup extends Model
{
    protected $table = 'data_source_groups';

    protected $fillable = ['data_source_id', 'name', 'description'];

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(DataSourceTable::class, 'data_source_group_id');
    }
}
