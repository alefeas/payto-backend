<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyConnectionPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        $member = $company->members()->where('user_id', $user->id)->first();
        return $member && in_array($member->role, ['owner', 'admin', 'accountant', 'operator']);
    }

    public function create(User $user, Company $company): bool
    {
        $member = $company->members()->where('user_id', $user->id)->first();
        return $member && in_array($member->role, ['owner', 'admin']);
    }

    public function manage(User $user, Company $company): bool
    {
        $member = $company->members()->where('user_id', $user->id)->first();
        return $member && in_array($member->role, ['owner', 'admin']);
    }
}
