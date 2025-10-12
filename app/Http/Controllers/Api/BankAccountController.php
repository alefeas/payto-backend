<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Company;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankAccountController extends Controller
{
    use ApiResponse;

    public function index(string $companyId): JsonResponse
    {
        $company = Company::whereHas('members', function ($query) {
            $query->where('user_id', auth()->id())->where('is_active', true);
        })->findOrFail($companyId);

        $accounts = $company->bankAccounts()->get()->map(function ($account) {
            return [
                'id' => $account->id,
                'bankName' => $account->bank_name,
                'accountType' => $account->account_type,
                'cbu' => $account->cbu,
                'alias' => $account->alias,
                'isPrimary' => $account->is_primary,
                'createdAt' => $account->created_at->toIso8601String(),
            ];
        });

        return $this->success($accounts);
    }

    public function store(Request $request, string $companyId): JsonResponse
    {
        $company = Company::whereHas('members', function ($query) {
            $query->where('user_id', auth()->id())
                  ->where('role', 'administrator')
                  ->where('is_active', true);
        })->findOrFail($companyId);

        $request->validate([
            'bank_name' => 'required|string',
            'account_type' => 'required|in:corriente,caja_ahorro,cuenta_sueldo',
            'cbu' => 'required|string|size:22',
            'alias' => 'nullable|string',
            'is_primary' => 'boolean',
        ]);

        $isPrimary = $request->input('is_primary', false);
        
        if ($company->bankAccounts()->count() === 0) {
            $isPrimary = true;
        }

        if ($isPrimary) {
            $company->bankAccounts()->update(['is_primary' => false]);
        }

        $account = $company->bankAccounts()->create([
            'bank_name' => $request->input('bank_name'),
            'account_type' => $request->input('account_type'),
            'cbu' => $request->input('cbu'),
            'alias' => $request->input('alias'),
            'is_primary' => $isPrimary,
        ]);

        return $this->created([
            'id' => $account->id,
            'bankName' => $account->bank_name,
            'accountType' => $account->account_type,
            'cbu' => $account->cbu,
            'alias' => $account->alias,
            'isPrimary' => $account->is_primary,
        ]);
    }

    public function update(Request $request, string $companyId, string $accountId): JsonResponse
    {
        $company = Company::whereHas('members', function ($query) {
            $query->where('user_id', auth()->id())
                  ->where('role', 'administrator')
                  ->where('is_active', true);
        })->findOrFail($companyId);

        $account = $company->bankAccounts()->findOrFail($accountId);

        $request->validate([
            'bank_name' => 'required|string',
            'account_type' => 'required|in:corriente,caja_ahorro,cuenta_sueldo',
            'cbu' => 'required|string|size:22',
            'alias' => 'nullable|string',
            'is_primary' => 'boolean',
        ]);

        if ($request->input('is_primary', false)) {
            $company->bankAccounts()->where('id', '!=', $accountId)->update(['is_primary' => false]);
        }

        $account->update([
            'bank_name' => $request->input('bank_name'),
            'account_type' => $request->input('account_type'),
            'cbu' => $request->input('cbu'),
            'alias' => $request->input('alias'),
            'is_primary' => $request->input('is_primary', $account->is_primary),
        ]);

        return $this->success([
            'id' => $account->id,
            'bankName' => $account->bank_name,
            'accountType' => $account->account_type,
            'cbu' => $account->cbu,
            'alias' => $account->alias,
            'isPrimary' => $account->is_primary,
        ]);
    }

    public function destroy(string $companyId, string $accountId): JsonResponse
    {
        $company = Company::whereHas('members', function ($query) {
            $query->where('user_id', auth()->id())
                  ->where('role', 'administrator')
                  ->where('is_active', true);
        })->findOrFail($companyId);

        $account = $company->bankAccounts()->findOrFail($accountId);
        $account->delete();

        return $this->success(null, 'Cuenta eliminada exitosamente');
    }
}
