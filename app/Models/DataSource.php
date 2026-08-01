<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataSource extends Model
{
    protected $fillable = [
        'company_id',
        'chat_id',
        'type_id',
        'extracted_id',
        'connection_type',
        'name',
        'host',
        'port',
        'database',
        'username',
        'password',
        'path',
        'version',
        'options',
    ];

    protected $casts = [
        'port' => 'integer',
        'options' => 'array',
    ];

    public function extracted(){
        return $this->belongsTo(DataSourceExtraction::class,'extracted_id');
    }
    public function type(){
        return $this->belongsTo(DataSourceType::class,'type_id');
    }

}
