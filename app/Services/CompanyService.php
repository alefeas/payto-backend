<?php

namespace App\Services;

use App\Interfaces\CompanyServiceInterface;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Exceptions\NotFoundException;
use App\Exceptions\BadRequestException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class CompanyService implements CompanyServiceInterface
{
    public function createCompany(array $data, string $userId): array
    {
        $company = Company::create([
            'name' => $data['name'],
            'business_name' => $data['business_name'] ?? null,
            'national_id' => $data['national_id'],
            'phone' => $data['phone'] ?? null,
            'address' => $this->buildAddress($data),
            'tax_condition' => $this->mapTaxCondition($data['tax_condition']),
            'default_sales_point' => $data['default_sales_point'] ?? 1,
            'deletion_code' => bcrypt($data['deletion_code']),
            'unique_id' => strtoupper(Str::random(8)),
            'is_active' => true,
        ]);

        CompanyMember::create([
            'company_id' => $company->id,
            'user_id' => $userId,
            'role' => 'administrator',
            'is_active' => true,
        ]);

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

        return $this->formatCompanyData($company);
    }

    public function getUserCompanies(string $userId): array
    {
        $companies = Company::whereHas('members', function ($query) use ($userId) {
            $query->where('user_id', $userId)
                  ->where('is_active', true);
        })->with(['members' => function ($query) use ($userId) {
            $query->where('user_id', $userId);
        }])->get();

        return $companies->map(function ($company) {
            return $this->formatCompanyData($company);
        })->toArray();
    }

    private function mapTaxCondition(string $condition): string
    {
        $map = [
            'RI' => 'registered_taxpayer',
            'Monotributo' => 'monotax',
            'Exento' => 'exempt',
            'CF' => 'final_consumer',
        ];

        return $map[$condition] ?? 'final_consumer';
    }

    private function buildAddress(array $data): string
    {
        $parts = [
            $data['street'] ?? '',
            $data['street_number'] ?? '',
            $data['floor'] ? 'Piso ' . $data['floor'] : '',
            $data['apartment'] ? 'Depto ' . $data['apartment'] : '',
            $data['postal_code'] ?? '',
            $data['province'] ?? '',
        ];

        return implode(', ', array_filter($parts));
    }

    private function formatCompanyData(Company $company): array
    {
        $member = $company->members->first();
        
        return [
            'id' => $company->id,
            'name' => $company->name,
            'businessName' => $company->business_name,
            'nationalId' => $company->national_id,
            'phone' => $company->phone,
            'address' => $company->address,
            'taxCondition' => $company->tax_condition,
            'defaultSalesPoint' => $company->default_sales_point,
            'isActive' => $company->is_active,
            'role' => $member?->role,
            'createdAt' => $company->created_at->toIso8601String(),
            'updatedAt' => $company->updated_at->toIso8601String(),
        ];
    }
}
