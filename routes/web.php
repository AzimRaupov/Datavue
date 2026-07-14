<?php

use App\Jobs\RouterTaskJob;
use App\Models\Task;
use Illuminate\Support\Facades\Route;


Route::get('/test',function(){

    $d=new \App\Helpers\Dashboard\DashboardReGenerator(11,4);
    $d->determineChanges(" 'виджет Количество продаж по категориям' чтобы был первым");
    $d->applyChanges();

});
Route::view('/admin', 'admin');
Route::view('/admin/{any}', 'admin')->where('any', '.*');

Route::view('/company', 'company');
Route::view('/company/{any}', 'company')->where('any', '.*');

Route::view('/', 'viewer');
Route::view('/{any}', 'viewer')->where('any', '.*');
