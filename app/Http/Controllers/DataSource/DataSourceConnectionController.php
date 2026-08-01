<?php

namespace App\Http\Controllers\DataSource;

use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Http\Controllers\Controller;
use App\Models\DataSource;
use Illuminate\Http\Request;

class DataSourceConnectionController extends Controller
{
    public function query(Request $request,$id)
    {
        $dataSource = DataSource::find($id);
        if(!$dataSource){
            return response()->json([]);
        }
        $connection =new ConnectionProviderRouter($dataSource->id);

        return $connection->query($request->input('query'));
    }
}
