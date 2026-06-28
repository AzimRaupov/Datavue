<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DashboardWidget extends Model
{
    protected $fillable = ['dashboard_id', 'widget_id', 'instruction', 'title', 'position', 'status', 'container', 'tables'];

    public function dashboard()
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function widget()
    {
        return $this->belongsTo(Widget::class);
    }
}
