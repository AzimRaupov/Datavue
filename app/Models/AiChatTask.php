<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiChatTask extends Model
{
    protected $fillable = ['chat_id', 'message_id', 'task_id', 'status_id','title','description'];




    public function task()
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function status()
    {
        return $this->belongsTo(TaskStatus::class, 'status_id');
    }
}
