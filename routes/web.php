<?php

use App\Helpers\Ai\AIService;
use App\Jobs\DataHandlerJob;
use App\Jobs\GeneratorDashboardJob;
use Illuminate\Support\Facades\Route;



Route::get('/test',function(){
//
//    $generateWidgets = (new AIService(responseFormat: 'text'))->ask("helo");
//
//   return $generateWidgets;
    dispatch(new GeneratorDashboardJob(62,50,1));

});
Route::view('/admin', 'admin');
Route::view('/admin/{any}', 'admin')->where('any', '.*');

Route::view('/company', 'company');
Route::view('/company/{any}', 'company')->where('any', '.*');

Route::view('/', 'viewer');
Route::view('/{any}', 'viewer')->where('any', '.*');
