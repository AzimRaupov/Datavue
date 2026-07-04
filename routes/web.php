<?php

use Illuminate\Support\Facades\Route;


Route::get('/test',function(){

    $message=\App\Models\AiChatMessage::query()->where('chat_id',1)->first();

    $messages=\App\Models\AiChatMessage::query()
        ->where('id','!=',$message->id)->where('chat_id',1)
        ->select('message','answer')->get();

    dd(new \App\Helpers\Task\RouterTask($message->id,$message->chat_id));

    $result = new \App\Helpers\Task\DefineTask($messages,"Груперуй продажы по категориям");
    dd($result->defineTask());
//
//    $generateWidgets = (new AIService(responseFormat: 'text'))->ask("helo");
//
//   return $generateWidgets;
//    dispatch(new GeneratorDashboardJob(62,50,1));

});
Route::view('/admin', 'admin');
Route::view('/admin/{any}', 'admin')->where('any', '.*');

Route::view('/company', 'company');
Route::view('/company/{any}', 'company')->where('any', '.*');

Route::view('/', 'viewer');
Route::view('/{any}', 'viewer')->where('any', '.*');
