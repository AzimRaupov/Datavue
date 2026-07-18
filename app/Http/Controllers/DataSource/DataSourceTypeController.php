<?php

namespace App\Http\Controllers\DataSource;

use App\Http\Controllers\Controller;
use App\Models\DataSourceType;
use Illuminate\Http\Request;

class DataSourceTypeController extends Controller
{
    public function index(){
        $types = DataSourceType::all();
        return response()->json($types);
    }
}
