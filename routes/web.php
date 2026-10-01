<?php

use App\Jobs\DashboardGeneratorJob;
use App\Jobs\DashboardReGeneratorJob;
use App\Jobs\RouterTaskJob;
use App\Models\Task;
use Illuminate\Support\Facades\Route;

Route::get('/test2',function() {

});

Route::get('/test',function(){

    dispatch(new \App\Jobs\ReviewWidgetsDashboardJob(149,20,63));

        dispatch(new DashboardReGeneratorJob(16,117,35,"добаф виджет для показа клиентов по странам"));

});

Route::get('/exports/{token}', [\App\Http\Controllers\Chat\ExportController::class, 'download'])
    ->name('chat-exports.download')
    ->where('token', '[A-Za-z0-9]+');

Route::get('/alert-history/{token}/csv', [\App\Http\Controllers\Alert\AlertHistoryExportController::class, 'download'])
    ->name('alert-history.csv')
    ->where('token', '[A-Za-z0-9]+');

Route::view('/admin', 'admin');
Route::view('/admin/{any}', 'admin')->where('any', '.*');

Route::view('/company', 'company');
Route::view('/company/{any}', 'company')->where('any', '.*');

Route::view('/', 'viewer');
Route::view('/{any}', 'viewer')->where('any', '.*');
