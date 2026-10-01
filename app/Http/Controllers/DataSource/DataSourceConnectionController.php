<?php

namespace App\Http\Controllers\DataSource;

use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\DataSource\ReadOnlyQueryRunner;
use App\Http\Controllers\Controller;
use App\Models\DataSource;
use Illuminate\Http\Request;

class DataSourceConnectionController extends Controller
{

    public function query(Request $request, $id)
    {
        $request->validate([
            'query' => 'required|string',
        ]);

        $dataSource = DataSource::query()
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($id);

        $runner = new ReadOnlyQueryRunner(
            new ConnectionProviderRouter($dataSource->id)
        );

        $result = $runner->run($request->input('query'));

        if (!$result['ok']) {
            return response()->json([
                'message' => $result['error'],
            ], 422);
        }

        return response()->json([
            'rows' => $result['rows'],
            'row_count' => $result['row_count'],
            'truncated' => $result['truncated'],
        ]);
    }
}
