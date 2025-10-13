<?php

namespace App\Interfaces;

interface CompanyMemberServiceInterface
{
    public function getCompanyMembers(string $companyId, string $userId): array;
    public function updateMemberRole(string $companyId, string $memberId, string $newRole, string $userId, ?string $confirmationCode = null): array;
    public function removeMember(string $companyId, string $memberId, string $userId): bool;
}
