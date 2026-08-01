<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DashboardWidget extends Model
{
    protected $fillable = ['dashboard_id', 'widget_id', 'instruction', 'title', 'code_path', 'position', 'status', 'container', 'tables'];
    protected $casts = [
        'tables' => 'array',
    ];

    public function dashboard()
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function widget()
    {
        return $this->belongsTo(Widget::class);
    }

    public function tablesRole()
    {
        return $this->hasMany();
    }
}
