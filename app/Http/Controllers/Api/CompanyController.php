<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCompanyRequest;
use App\Http\Requests\JoinCompanyRequest;
use App\Interfaces\CompanyServiceInterface;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class CompanyController extends Controller
{
    use ApiResponse;

    private CompanyServiceInterface $companyService;

    public function __construct(CompanyServiceInterface $companyService)
    {
        $this->companyService = $companyService;
    }

    public function index(): JsonResponse
    {
        $companies = $this->companyService->getUserCompanies(auth()->id());
        return $this->success($companies, 'Empresas obtenidas exitosamente');
    }

    public function store(CreateCompanyRequest $request): JsonResponse
    {
        $company = $this->companyService->createCompany(
            $request->validated(),
            auth()->id()
        );

        return $this->created($company, 'Empresa creada exitosamente');
    }

    public function join(JoinCompanyRequest $request): JsonResponse
    {
        $company = $this->companyService->joinCompany(
            $request->validated()['invite_code'],
            auth()->id()
        );

        return $this->success($company, 'Te has unido a la empresa exitosamente');
    }
}
