<?php

use App\Helpers\Task\RouterTask;
use App\Jobs\DashboardGeneratorJob;
use App\Jobs\RouterTaskJob;
use App\Models\Task;
use Illuminate\Support\Facades\Route;


Route::get('/test',function(){



 dispatch(new DashboardGeneratorJob(10,10,1));


//      $res = new \App\Helpers\DataSource\CodeTemplater(20);
//      dd($res->getQueryTemplate(true));

//    $result = new \App\Helpers\Ai\DashboardAi();
//
//    return $result->codeTemplate(18,'1|8HwhR3B05nYz4UJg9Y5JBRpn9HtU44sdufWGE3mn54493adc');

//
//       $res = new \App\Helpers\DataSource\ConnectionProviderRouter(15);
//       dd($res->query("select * from orders;"));


//    $task_list = Task::query()->where('name', 'generate_dashboard')
//        ->orWhere('name', 'response_in_chat')
//        ->select('name', 'description')
//        ->get();
//    $router= new RouterTask(17,23,$task_list,null,1);
//    dd($router->define());

});
Route::view('/admin', 'admin');
Route::view('/admin/{any}', 'admin')->where('any', '.*');

Route::view('/company', 'company');
Route::view('/company/{any}', 'company')->where('any', '.*');

Route::view('/', 'viewer');
Route::view('/{any}', 'viewer')->where('any', '.*');
