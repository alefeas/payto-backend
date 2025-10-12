<?php

namespace App\Interfaces;

interface CompanyServiceInterface
{
    public function createCompany(array $data, string $userId): array;
    public function joinCompany(string $inviteCode, string $userId): array;
    public function getUserCompanies(string $userId): array;
}
