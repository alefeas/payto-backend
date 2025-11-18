<?php

namespace App\Repositories;

use App\Models\CompanyMember;
use Illuminate\Database\Eloquent\Collection;

class CompanyMemberRepository extends BaseRepository
{
    public function __construct(CompanyMember $model)
    {
        parent::__construct($model);
    }

    public function getByCompanyId($companyId): Collection
    {
        return $this->model->where('company_id', $companyId)
            ->with('user')
            ->get();
    }

    public function getByCompanyIdAndUserId($companyId, $userId)
    {
        return $this->model->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->first();
    }

    public function getByCompanyIdAndRole($companyId, $role): Collection
    {
        return $this->model->where('company_id', $companyId)
            ->where('role', $role)
            ->with('user')
            ->get();
    }

    public function checkMemberExists($companyId, $userId): bool
    {
        return $this->model->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->exists();
    }

    public function getOwner($companyId)
    {
        return $this->model->where('company_id', $companyId)
            ->where('role', 'owner')
            ->first();
    }

    public function searchByEmail($companyId, $email): Collection
    {
        return $this->model->where('company_id', $companyId)
            ->whereHas('user', function($query) use ($email) {
                $query->where('email', 'like', "%{$email}%");
            })
            ->with('user')
            ->get();
    }
}
