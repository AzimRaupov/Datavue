<?php

namespace App\Http\Controllers\Company;

use App\Helpers\Ai\AiUsage;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(
            AiUsage::summary($request->user()->company)
        );
    }

    public function update(Request $request)
    {
        $data = $request->validate([

            'ai_token_limit' => 'present|nullable|integer|min:0',
        ]);

        $company = $request->user()->company;
        $company->ai_token_limit = $data['ai_token_limit'] ?: null;
        $company->save();

        return response()->json([
            'success' => true,
            'usage' => AiUsage::summary($company->fresh()),
        ]);
    }
}
