<?php

namespace App\Services;

use App\Interfaces\CompanyServiceInterface;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Address;
use App\Models\CompanyBillingSetting;
use App\Models\CompanyPreference;
use App\Exceptions\NotFoundException;
use App\Exceptions\BadRequestException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class CompanyService implements CompanyServiceInterface
{
    private AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }
    public function createCompany(array $data, string $userId): array
    {
        $company = Company::create([
            'name' => $data['name'],
            'business_name' => $data['business_name'] ?? null,
            'national_id' => $data['national_id'],
            'phone' => $data['phone'] ?? null,
            'tax_condition' => null,
            'default_sales_point' => $data['default_sales_point'] ?? 1,
            'last_invoice_number' => $data['last_invoice_number'] ?? 0,
            'deletion_code' => bcrypt($data['deletion_code']),
            'unique_id' => strtoupper(Str::random(8)),
            'invite_code' => strtoupper(Str::random(10)),
            'is_active' => true,
        ]);

        Address::create([
            'company_id' => $company->id,
            'street' => $data['street'] ?? '',
            'street_number' => $data['street_number'] ?? '',
            'floor' => $data['floor'] ?? null,
            'apartment' => $data['apartment'] ?? null,
            'postal_code' => $data['postal_code'] ?? '',
            'province' => $data['province'] ?? '',
        ]);

        CompanyBillingSetting::create([
            'company_id' => $company->id,
        ]);

        CompanyPreference::create([
            'company_id' => $company->id,
        ]);

        CompanyMember::create([
            'company_id' => $company->id,
            'user_id' => $userId,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->auditService->log(
            $company->id,
            $userId,
            'company.created',
            "Empresa {$company->name} creada",
            'Company',
            $company->id
        );

        return $this->formatCompanyData($company);
    }

    public function joinCompany(string $inviteCode, string $userId): array
    {
        $company = Company::where('invite_code', $inviteCode)
            ->where('is_active', true)
            ->first();

        if (!$company) {
            throw new NotFoundException('Código de invitación inválido');
        }

        $existingMember = CompanyMember::where('company_id', $company->id)
            ->where('user_id', $userId)
            ->first();

        if ($existingMember) {
            throw new BadRequestException('Ya eres miembro de esta empresa');
        }

        CompanyMember::create([
            'company_id' => $company->id,
            'user_id' => $userId,
            'role' => $company->default_role ?? 'operator',
            'is_active' => true,
        ]);

        $this->auditService->log(
            $company->id,
            $userId,
            'member.joined',
            "Nuevo miembro se unió a la empresa",
            'CompanyMember',
            null
        );

        return $this->formatCompanyData($company);
    }

    public function getUserCompanies(string $userId): array
    {
        $companies = Company::whereHas('members', function ($query) use ($userId) {
            $query->where('user_id', $userId)
                  ->where('is_active', true);
        })->with(['members' => function ($query) use ($userId) {
            $query->where('user_id', $userId);
        }, 'address', 'billingSettings'])->get();

        return $companies->map(function ($company) {
            return $this->formatCompanyData($company);
        })->toArray();
    }





    public function getCompanyById(string $companyId, string $userId): array
    {
        $company = Company::whereHas('members', function ($query) use ($userId) {
            $query->where('user_id', $userId)->where('is_active', true);
        })->with(['members' => function ($query) use ($userId) {
            $query->where('user_id', $userId);
        }, 'address', 'billingSettings'])->findOrFail($companyId);

        return $this->formatCompanyData($company);
    }

    public function updateCompany(string $companyId, array $data, string $userId): array
    {
        $company = Company::whereHas('members', function ($query) use ($userId) {
            $query->where('user_id', $userId)
                  ->whereIn('role', ['owner', 'administrator'])
                  ->where('is_active', true);
        })->findOrFail($companyId);

        // Validate required_approvals
        if (isset($data['required_approvals'])) {
            if ($data['required_approvals'] < 0) {
                throw new BadRequestException('El número de aprobaciones requeridas no puede ser negativo');
            }
            
            if ($data['required_approvals'] > 0) {
                $approversCount = CompanyMember::where('company_id', $companyId)
                    ->where('is_active', true)
                    ->whereIn('role', ['owner', 'administrator', 'financial_director', 'accountant', 'approver'])
                    ->count();
                
                if ($data['required_approvals'] > $approversCount) {
                    throw new BadRequestException("No puedes requerir más aprobaciones ({$data['required_approvals']}) que miembros con permiso para aprobar ({$approversCount})");
                }
            }
        }

        $updateData = [
            'name' => $data['name'] ?? $company->name,
            'business_name' => $data['business_name'] ?? $company->business_name,
            'national_id' => $data['national_id'] ?? $company->national_id,
            'phone' => $data['phone'] ?? $company->phone,
            'tax_condition' => $data['tax_condition'] ?? $company->tax_condition,
            'default_sales_point' => $data['default_sales_point'] ?? $company->default_sales_point,
            'last_invoice_number' => $data['last_invoice_number'] ?? $company->last_invoice_number,
            'required_approvals' => isset($data['required_approvals']) ? (int)$data['required_approvals'] : $company->required_approvals,
            'is_perception_agent' => $data['is_perception_agent'] ?? $company->is_perception_agent,
            'auto_perceptions' => $data['auto_perceptions'] ?? $company->auto_perceptions,
            'is_retention_agent' => $data['is_retention_agent'] ?? $company->is_retention_agent,
            'auto_retentions' => $data['auto_retentions'] ?? $company->auto_retentions,
        ];

        if (isset($data['street']) || isset($data['street_number']) || isset($data['postal_code']) || isset($data['province'])) {
            $company->address()->updateOrCreate(
                ['company_id' => $company->id],
                [
                    'street' => $data['street'] ?? '',
                    'street_number' => $data['street_number'] ?? '',
                    'floor' => $data['floor'] ?? null,
                    'apartment' => $data['apartment'] ?? null,
                    'postal_code' => $data['postal_code'] ?? '',
                    'province' => $data['province'] ?? '',
                ]
            );
        }

        if (isset($data['default_vat']) || isset($data['vat_perception']) || isset($data['gross_income_perception']) || 
            isset($data['social_security_perception']) || isset($data['vat_retention']) || isset($data['income_tax_retention']) || 
            isset($data['gross_income_retention']) || isset($data['social_security_retention'])) {
            $company->billingSettings()->updateOrCreate(
                ['company_id' => $company->id],
                [
                    'default_vat' => $data['default_vat'] ?? $company->billingSettings->default_vat ?? 21,
                    'vat_perception' => $data['vat_perception'] ?? $company->billingSettings->vat_perception ?? 0,
                    'gross_income_perception' => $data['gross_income_perception'] ?? $company->billingSettings->gross_income_perception ?? 2.5,
                    'social_security_perception' => $data['social_security_perception'] ?? $company->billingSettings->social_security_perception ?? 1,
                    'vat_retention' => $data['vat_retention'] ?? $company->billingSettings->vat_retention ?? 0,
                    'income_tax_retention' => $data['income_tax_retention'] ?? $company->billingSettings->income_tax_retention ?? 2,
                    'gross_income_retention' => $data['gross_income_retention'] ?? $company->billingSettings->gross_income_retention ?? 0.42,
                    'social_security_retention' => $data['social_security_retention'] ?? $company->billingSettings->social_security_retention ?? 0,
                ]
            );
        }

        $company->update($updateData);
        $company->refresh();

        // Auto-approve pending invoices based on new required_approvals
        if (isset($data['required_approvals'])) {
            $newRequiredApprovals = (int)$data['required_approvals'];
            
            // Update all pending invoices with new requirement
            \App\Models\Invoice::where('receiver_company_id', $companyId)
                ->where('status', 'pending_approval')
                ->update(['approvals_required' => $newRequiredApprovals]);
            
            // If 0, approve all pending invoices
            if ($newRequiredApprovals === 0) {
                \App\Models\Invoice::where('receiver_company_id', $companyId)
                    ->where('status', 'pending_approval')
                    ->update([
                        'status' => 'approved',
                        'approval_date' => now(),
                    ]);
            } else {
                // If > 0, approve those that already have enough approvals
                \App\Models\Invoice::where('receiver_company_id', $companyId)
                    ->where('status', 'pending_approval')
                    ->where('approvals_received', '>=', $newRequiredApprovals)
                    ->update([
                        'status' => 'approved',
                        'approval_date' => now(),
                    ]);
            }
        }

        $this->auditService->log(
            $companyId,
            $userId,
            'company.updated',
            "Configuración de la empresa actualizada",
            'Company',
            $companyId,
            ['updated_fields' => array_keys($data)]
        );

        return $this->formatCompanyData($company->load(['members' => function ($query) use ($userId) {
            $query->where('user_id', $userId);
        }, 'address', 'billingSettings']));
    }

    public function regenerateInviteCode(string $companyId, string $userId): array
    {
        $company = Company::whereHas('members', function ($query) use ($userId) {
            $query->where('user_id', $userId)
                  ->whereIn('role', ['owner', 'administrator'])
                  ->where('is_active', true);
        })->findOrFail($companyId);

        $company->update([
            'invite_code' => strtoupper(Str::random(10)),
        ]);

        $this->auditService->log(
            $companyId,
            $userId,
            'company.invite_code_regenerated',
            "Código de invitación regenerado",
            'Company',
            $companyId
        );

        return [
            'inviteCode' => $company->invite_code,
        ];
    }

    public function deleteCompany(string $companyId, string $deletionCode, string $userId): bool
    {
        $company = Company::whereHas('members', function ($query) use ($userId) {
            $query->where('user_id', $userId)
                  ->where('role', 'owner')
                  ->where('is_active', true);
        })->findOrFail($companyId);

        if (!Hash::check($deletionCode, $company->deletion_code)) {
            throw new BadRequestException('Código de eliminación incorrecto');
        }

        // Verificar si la empresa tiene facturas asociadas
        $hasInvoices = $company->issuedInvoices()->exists() || $company->receivedInvoices()->exists();
        
        $auditMessage = $hasInvoices 
            ? "Perfil fiscal {$company->name} eliminado - facturas y datos contables preservados para mantener integridad del sistema"
            : "Perfil fiscal {$company->name} eliminado - no tenía facturas asociadas";

        $this->auditService->log(
            $companyId,
            $userId,
            'company.deleted',
            $auditMessage,
            'Company',
            $companyId
        );

        $company->delete();
        return true;
    }

    private function formatCompanyData(Company $company): array
    {
        $member = $company->members->first();
        $address = $company->address;
        $billing = $company->billingSettings;
        
        return [
            'id' => $company->id,
            'name' => $company->name,
            'businessName' => $company->business_name,
            'nationalId' => $company->national_id,
            'phone' => $company->phone,
            'addressData' => $address ? [
                'street' => $address->street,
                'streetNumber' => $address->street_number,
                'floor' => $address->floor,
                'apartment' => $address->apartment,
                'postalCode' => $address->postal_code,
                'province' => $address->province,
                'city' => $address->city,
            ] : null,
            'taxCondition' => $company->tax_condition,
            'defaultSalesPoint' => $company->default_sales_point,
            'lastInvoiceNumber' => $company->last_invoice_number,
            'defaultVat' => $billing?->default_vat ?? 21,
            'vatPerception' => $billing?->vat_perception ?? 0,
            'grossIncomePerception' => $billing?->gross_income_perception ?? 2.5,
            'socialSecurityPerception' => $billing?->social_security_perception ?? 1,
            'vatRetention' => $billing?->vat_retention ?? 0,
            'incomeTaxRetention' => $billing?->income_tax_retention ?? 2,
            'grossIncomeRetention' => $billing?->gross_income_retention ?? 0.42,
            'socialSecurityRetention' => $billing?->social_security_retention ?? 0,
            'requiredApprovals' => $company->required_approvals ?? 0,
            'isPerceptionAgent' => $company->is_perception_agent ?? false,
            'autoPerceptions' => $company->auto_perceptions ?? [],
            'isRetentionAgent' => $company->is_retention_agent ?? false,
            'autoRetentions' => $company->auto_retentions ?? [],
            'isActive' => $company->is_active,
            'uniqueId' => $company->unique_id,
            'inviteCode' => $company->invite_code,
            'role' => $member?->role,
            'createdAt' => $company->created_at->toIso8601String(),
            'updatedAt' => $company->updated_at->toIso8601String(),
        ];
    }
}
