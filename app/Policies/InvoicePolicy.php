<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        $member = $company->members()->where('user_id', $user->id)->first();
        
        return $member && in_array($member->role, [
            'owner',
            'administrator',
            'financial_director',
            'accountant',
            'approver',
            'operator',
        ]);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        $member = $invoice->issuerCompany->members()->where('user_id', $user->id)->first();
        
        return $member && in_array($member->role, [
            'owner',
            'administrator',
            'financial_director',
            'accountant',
            'approver',
            'operator',
        ]);
    }

    public function create(User $user, Company $company): bool
    {
        $member = $company->members()->where('user_id', $user->id)->first();
        
        return $member && in_array($member->role, [
            'owner',
            'administrator',
            'financial_director',
            'accountant',
        ]);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        $member = $invoice->issuerCompany->members()->where('user_id', $user->id)->first();
        
        return $member && in_array($member->role, [
            'owner',
            'administrator',
            'financial_director',
        ]);
    }
}
