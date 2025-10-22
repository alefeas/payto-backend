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
        // Verificar si es miembro de la empresa emisora
        $issuerMember = $invoice->issuerCompany->members()->where('user_id', $user->id)->first();
        if ($issuerMember && in_array($issuerMember->role, ['owner', 'administrator', 'financial_director', 'accountant', 'approver', 'operator'])) {
            return true;
        }
        
        // Verificar si es miembro de la empresa receptora
        if ($invoice->receiverCompany) {
            $receiverMember = $invoice->receiverCompany->members()->where('user_id', $user->id)->first();
            if ($receiverMember && in_array($receiverMember->role, ['owner', 'administrator', 'financial_director', 'accountant', 'approver', 'operator'])) {
                return true;
            }
        }
        
        return false;
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
