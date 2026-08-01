<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataSourceTable extends Model
{
    protected $table = 'data_source_tables';

    protected $fillable = [
        'data_source_id',
        'data_source_group_id',
        'name',
        'description',
        'role',
    ];

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(DataSourceGroup::class, 'data_source_group_id');
    }
}
