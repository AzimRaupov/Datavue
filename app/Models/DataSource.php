<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataSource extends Model
{
    protected $fillable = [
        'company_id',
        'chat_id',
        'type_id',
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

}
