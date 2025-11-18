<?php

namespace App\Repositories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Collection;

class ClientRepository extends BaseRepository
{
    public function __construct(Client $model)
    {
        parent::__construct($model);
    }

    public function getByCompanyId($companyId): Collection
    {
        return $this->model->where('company_id', $companyId)->get();
    }

    public function getTrashedByCompanyId($companyId): Collection
    {
        return $this->model->where('company_id', $companyId)
            ->onlyTrashed()
            ->orderBy('deleted_at', 'desc')
            ->get();
    }

    public function checkDuplicateDocument($companyId, $documentNumber, $excludeId = null): bool
    {
        $query = $this->model->where('company_id', $companyId)
            ->where('document_number', $documentNumber)
            ->withTrashed();

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function findByDocumentAndCompany($companyId, $documentNumber)
    {
        return $this->model->where('company_id', $companyId)
            ->where('document_number', $documentNumber)
            ->withTrashed()
            ->first();
    }

    public function restore($id): bool
    {
        $client = $this->model->withTrashed()->find($id);
        return $client ? $client->restore() : false;
    }
}
