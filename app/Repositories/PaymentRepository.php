<?php

namespace App\Repositories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Paginator;

class PaymentRepository extends BaseRepository
{
    public function __construct(Payment $model)
    {
        parent::__construct($model);
    }

    public function getByCompanyId($companyId, array $filters = [])
    {
        $query = $this->model->with(['invoice.supplier', 'invoice.issuerCompany', 'registeredBy', 'confirmedBy'])
            ->where('company_id', $companyId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['from_date'])) {
            $query->where('payment_date', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('payment_date', '<=', $filters['to_date']);
        }

        return $query->orderBy('payment_date', 'desc')->get();
    }

    public function getTotalPaidForInvoice($invoiceId): float
    {
        return $this->model->where('invoice_id', $invoiceId)
            ->where('status', 'confirmed')
            ->sum('amount');
    }

    public function getByInvoiceIds(array $invoiceIds, $companyId)
    {
        return $this->model->with(['invoice.supplier'])
            ->where('company_id', $companyId)
            ->whereIn('id', $invoiceIds)
            ->get();
    }

    public function getSupplierPaymentsByCompany($companyId, array $filters = [])
    {
        $query = $this->model->where('company_id', $companyId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['from_date'])) {
            $query->where('payment_date', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('payment_date', '<=', $filters['to_date']);
        }

        return $query->orderByDesc('payment_date')->get();
    }
}
