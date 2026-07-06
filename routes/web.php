<?php

use App\Models\Task;
use Illuminate\Support\Facades\Route;


Route::get('/test',function(){
    $message= \App\Models\AiChatMessage::query()->where('chat_id',15)->with('tasks')->first();
    event(new \App\Events\MessageTasksChanged($message));

    dd($message);



});
Route::view('/admin', 'admin');
Route::view('/admin/{any}', 'admin')->where('any', '.*');

Route::view('/company', 'company');
Route::view('/company/{any}', 'company')->where('any', '.*');

Route::view('/', 'viewer');
Route::view('/{any}', 'viewer')->where('any', '.*');
