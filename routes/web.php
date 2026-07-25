<?php

use App\Helpers\Task\RouterTask;
use App\Jobs\DashboardGeneratorJob;
use App\Jobs\DashboardReGeneratorJob;
use App\Jobs\RouterTaskJob;
use App\Models\Task;
use Illuminate\Support\Facades\Route;


Route::get('/test2',function() {

//
//    $new =new \App\Helpers\DataSource\Handlers\MySqlDataHandler("/home/azim/projects/Datavue/storage/app/company/1/chats/1/data/6a61dc95798797.30329683.sql","");
//
//    dd($new->handle());
////    dispatch(new DashboardGeneratorJob(19, 46, 1));
//
});

Route::get('/test',function(){

    dispatch(new DashboardReGeneratorJob(72,133,28,"в перед последнем виджете переводит заголоки таблици на русский"));

//    $task_list = Task::query()->where('name', 're_generate_dashboard')
//        ->orWhere('name', 'response_in_chat')
//        ->select('name', 'description')
//        ->get();
//
//    $router= new RouterTask(26,69,$task_list,129,1);
//
//    dd($router->define());

//    dispatch(new DashboardGeneratorJob(20,47,1));


//           $res = new \App\Helpers\DataSource\ConnectionProviderRouter(24);
//            dd($res->getSchema(
//           tables: [],
//           options: [
//               'count_rows',
//               'relations' => [
//                   'column' => [
//                       'type',
//                   ],
//                   'relation' => [
//                       'table',
//                   ],
//               ],
//           ]
//       ));

//
//
//
//

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
