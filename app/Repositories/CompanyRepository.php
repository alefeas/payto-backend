<?php

namespace App\Repositories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Collection;

class CompanyRepository extends BaseRepository
{
    public function __construct(Company $model)
    {
        parent::__construct($model);
    }

    public function getByUser($userId): Collection
    {
        return $this->model->whereHas('members', function($query) use ($userId) {
            $query->where('user_id', $userId);
        })->get();
    }

    public function findByIdWithRelations($id, array $relations = [])
    {
        $query = $this->model->newQuery();
        
        if (!empty($relations)) {
            $query->with($relations);
        }
        
        return $query->find($id);
    }

    public function getWithMembersAndCertificates($id)
    {
        return $this->model->with(['members', 'afipCertificates', 'salesPoints'])->find($id);
    }

    public function checkDuplicateCuit($cuit, $excludeId = null): bool
    {
        $query = $this->model->where('cuit', $cuit);
        
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }
        
        return $query->exists();
    }
}
