<?php

namespace App\Repositories;

use App\Interfaces\RepositoryInterface;
use App\Models\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class InvoiceRepository implements RepositoryInterface
{
    protected Invoice $model;

    public function __construct()
    {
        $this->model = new Invoice();
    }

    public function all(): Collection
    {
        return $this->model->newQuery()->get();
    }

    public function find(int $id): ?Model
    {
        return $this->model->find($id);
    }

    public function findByUuid(string $uuid): ?Invoice
    {
        return $this->model->find($uuid);
    }

    public function create(array $data): Model
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): bool
    {
        $record = $this->find($id);
        return $record ? $record->update($data) : false;
    }

    public function updateByUuid(string $uuid, array $data): bool
    {
        $invoice = $this->findByUuid($uuid);
        return $invoice ? $invoice->update($data) : false;
    }

    public function delete(int $id): bool
    {
        $record = $this->find($id);
        return $record ? $record->delete() : false;
    }

    public function deleteByUuid(string $uuid): bool
    {
        $invoice = $this->findByUuid($uuid);
        return $invoice ? $invoice->delete() : false;
    }

    public function findBy(string $field, $value): ?Model
    {
        return $this->model->where($field, $value)->first();
    }

    public function findWhere(array $criteria): Collection
    {
        $query = $this->model->newQuery();
        
        foreach ($criteria as $field => $value) {
            $query->where($field, $value);
        }
        
        return $query->get();
    }

    public function findByCompany(string $companyId, array $filters = [], array $with = []): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->where(function($q) use ($companyId) {
                $q->where('issuer_company_id', $companyId)
                  ->orWhere('receiver_company_id', $companyId);
            });

        if (!empty($with)) {
            $query->with($with);
        }

        // Apply filters
        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['type']) && $filters['type'] !== 'all') {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('number', 'like', '%' . $search . '%')
                  ->orWhere('receiver_name', 'like', '%' . $search . '%')
                  ->orWhereHas('client', function($q) use ($search) {
                      $q->where('business_name', 'like', '%' . $search . '%')
                        ->orWhere('first_name', 'like', '%' . $search . '%')
                        ->orWhere('last_name', 'like', '%' . $search . '%');
                  });
            });
        }

        if (isset($filters['date_from'])) {
            $query->whereDate('issue_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->whereDate('issue_date', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($filters['per_page'] ?? 20);
    }

    public function findByNumberAndCompany(string $companyId, string $invoiceType, int $salesPoint, int $voucherNumber): ?Invoice
    {
        return $this->model->where('issuer_company_id', $companyId)
            ->where('type', $invoiceType)
            ->where('sales_point', $salesPoint)
            ->where('voucher_number', $voucherNumber)
            ->first();
    }

    public function findAssociableInvoices(string $companyId, array $filters = []): Collection
    {
        $query = $this->model->newQuery();

        if (isset($filters['direction']) && $filters['direction'] === 'issued') {
            $query->where('issuer_company_id', $companyId);
            
            if (isset($filters['afip_only']) && $filters['afip_only']) {
                $query->where('afip_status', 'approved')
                      ->whereNotNull('afip_cae');
            }
        } else {
            $query->where('receiver_company_id', $companyId);
        }

        if (isset($filters['types'])) {
            $query->whereIn('type', $filters['types']);
        }

        if (isset($filters['status'])) {
            if (is_array($filters['status'])) {
                $query->whereNotIn('status', $filters['status']);
            } else {
                $query->where('status', '!=', $filters['status']);
            }
        }

        if (isset($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        if (isset($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (isset($filters['receiver_document'])) {
            $query->where(function($q) use ($filters) {
                $q->whereHas('client', function($q) use ($filters) {
                    $q->where('document_number', $filters['receiver_document']);
                })->orWhereHas('receiverCompany', function($q) use ($filters) {
                    $q->where('national_id', $filters['receiver_document']);
                });
            });
        }

        if (isset($filters['issuer_document'])) {
            $query->whereHas('issuerCompany', function($q) use ($filters) {
                $q->where('national_id', $filters['issuer_document']);
            });
        }

        if (isset($filters['is_manual_load'])) {
            $query->where('is_manual_load', $filters['is_manual_load']);
        }

        return $query->with(['client', 'supplier', 'receiverCompany', 'issuerCompany'])
            ->orderBy('issue_date', 'desc')
            ->get();
    }
}

