<?php

use App\Helpers\Task\RouterTask;
use App\Jobs\RouterTaskJob;
use App\Models\Task;
use Illuminate\Support\Facades\Route;


Route::get('/test',function(){
    $task_list = Task::query()->where('name', 'generate_dashboard')
        ->orWhere('name', 'response_in_chat')
        ->select('name', 'description')
        ->get();
    $router= new RouterTask(3,25,$task_list,null,1);
    dd($router->define());

});
Route::view('/admin', 'admin');
Route::view('/admin/{any}', 'admin')->where('any', '.*');

Route::view('/company', 'company');
Route::view('/company/{any}', 'company')->where('any', '.*');

Route::view('/', 'viewer');
Route::view('/{any}', 'viewer')->where('any', '.*');
