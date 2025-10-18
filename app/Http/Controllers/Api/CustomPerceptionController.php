<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CustomPerception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomPerceptionController extends Controller
{
    public function index($companyId)
    {
        $company = Company::findOrFail($companyId);
        
        $customPerceptions = CustomPerception::where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $customPerceptions
        ]);
    }

    public function store(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'category' => 'required|in:perception,retention',
            'default_rate' => 'nullable|numeric|min:0|max:100',
            'base_type' => 'required|in:net,vat',
            'jurisdiction' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        // Generate unique code
        $code = 'custom_' . Str::slug($validated['name']) . '_' . time();

        $customPerception = CustomPerception::create([
            'company_id' => $companyId,
            'code' => $code,
            ...$validated
        ]);

        return response()->json([
            'message' => 'Custom perception created successfully',
            'data' => $customPerception
        ], 201);
    }

    public function destroy($companyId, $id)
    {
        $customPerception = CustomPerception::where('company_id', $companyId)
            ->findOrFail($id);

        $customPerception->delete();

        return response()->json([
            'message' => 'Custom perception deleted successfully'
        ]);
    }
}
