<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCompanyRequest;
use App\Http\Requests\JoinCompanyRequest;
use App\Interfaces\CompanyServiceInterface;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function show(string $id): JsonResponse
    {
        $company = $this->companyService->getCompanyById($id, auth()->id());
        return $this->success($company, 'Empresa obtenida exitosamente');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $company = $this->companyService->updateCompany(
            $id,
            $request->all(),
            auth()->id()
        );

        return $this->success($company, 'Empresa actualizada exitosamente');
    }

    public function regenerateInvite(string $id): JsonResponse
    {
        $result = $this->companyService->regenerateInviteCode($id, auth()->id());
        return $this->success($result, 'Código de invitación regenerado exitosamente');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->companyService->deleteCompany(
            $id,
            $request->input('deletion_code'),
            auth()->id()
        );

        return $this->success(null, 'Empresa eliminada exitosamente');
    }

    public function updatePerceptionConfig(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'is_perception_agent' => 'required|boolean',
            'auto_perceptions' => 'nullable|array',
            'auto_perceptions.*.type' => 'required|string',
            'auto_perceptions.*.name' => 'required|string',
            'auto_perceptions.*.rate' => 'required|numeric|min:0|max:100',
            'auto_perceptions.*.base_type' => 'required|in:net,total,vat',
            'auto_perceptions.*.jurisdiction' => 'nullable|string',
        ]);

        $company = $this->companyService->updateCompany($id, $validated, auth()->id());

        return $this->success($company, 'Configuración de percepciones actualizada exitosamente');
    }

    public function updateRetentionConfig(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'is_retention_agent' => 'required|boolean',
            'auto_retentions' => 'nullable|array',
            'auto_retentions.*.type' => 'required|string',
            'auto_retentions.*.name' => 'required|string',
            'auto_retentions.*.rate' => 'required|numeric|min:0|max:100',
            'auto_retentions.*.base_type' => 'required|in:net,total,vat',
            'auto_retentions.*.jurisdiction' => 'nullable|string',
        ]);

        $company = $this->companyService->updateCompany($id, $validated, auth()->id());

        return $this->success($company, 'Configuración de retenciones actualizada exitosamente');
    }
}
