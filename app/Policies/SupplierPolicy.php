<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class SupplierPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        $member = $company->members()->where('user_id', $user->id)->first();
        return $member && in_array($member->role, ['owner', 'admin', 'financial_director', 'accountant', 'operator']);
    }

    public function create(User $user, Company $company): bool
    {
        $member = $company->members()->where('user_id', $user->id)->first();
        return $member && in_array($member->role, ['owner', 'admin', 'financial_director', 'accountant', 'operator']);
    }

    public function update(User $user, Company $company): bool
    {
        $member = $company->members()->where('user_id', $user->id)->first();
        return $member && in_array($member->role, ['owner', 'admin', 'financial_director', 'accountant']);
    }

    public function delete(User $user, Company $company): bool
    {
        $member = $company->members()->where('user_id', $user->id)->first();
        return $member && in_array($member->role, ['owner', 'admin', 'financial_director', 'accountant']);
    }
}
