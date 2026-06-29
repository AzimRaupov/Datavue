<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiChatTask extends Model
{
    protected $fillable = ['chat_id','task_id','status_id'];
}
