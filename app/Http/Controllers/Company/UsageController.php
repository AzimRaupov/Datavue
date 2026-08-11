<?php

namespace App\Http\Controllers\Company;

use App\Helpers\Ai\AiUsage;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Расход ИИ компанией.
 *
 * До появления этого раздела расход был не виден вообще: токены писались
 * в сообщения, но нигде не суммировались, и один сотрудник мог за вечер
 * сжечь любой бюджет незаметно.
 */
class UsageController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(
            AiUsage::summary($request->user()->company)
        );
    }

    /**
     * Месячный потолок расхода. Менять может только тот, кто управляет
     * компанией, — это финансовое решение, а не пользовательская настройка.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            // null — снять ограничение.
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
