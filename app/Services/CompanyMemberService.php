<?php

namespace App\Services;

use App\Interfaces\CompanyMemberServiceInterface;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Exceptions\NotFoundException;
use App\Exceptions\BadRequestException;
use App\Exceptions\ForbiddenException;

class CompanyMemberService implements CompanyMemberServiceInterface
{
    private const ROLE_OWNER = 'owner';
    private const ROLE_ADMINISTRATOR = 'administrator';
    private const MIN_ADMINISTRATORS = 1;

    private AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    public function getCompanyMembers(string $companyId, string $userId): array
    {
        $this->validateUserAccess($companyId, $userId);

        $members = CompanyMember::where('company_id', $companyId)
            ->where('is_active', true)
            ->with('user')
            ->get();

        return $members->map(function ($member) {
            return $this->formatMemberData($member);
        })->toArray();
    }

    public function updateMemberRole(string $companyId, string $memberId, string $newRole, string $userId, ?string $confirmationCode = null): array
    {
        $this->validateAdministratorAccess($companyId, $userId);
        $this->validateRole($newRole);

        $member = CompanyMember::where('company_id', $companyId)
            ->where('id', $memberId)
            ->where('is_active', true)
            ->firstOrFail();

        if ($member->user_id === $userId) {
            throw new BadRequestException('No puedes cambiar tu propio rol');
        }

        $currentUserMember = CompanyMember::where('company_id', $companyId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->firstOrFail();

        // Transferencia de ownership requiere confirmación
        if ($newRole === self::ROLE_OWNER) {
            if ($currentUserMember->role !== self::ROLE_OWNER) {
                throw new ForbiddenException('Solo el propietario puede transferir el rol de propietario');
            }
            
            $this->validateOwnershipTransfer($companyId, $confirmationCode);
            
            // Convertir owner actual a administrator
            $currentUserMember->update(['role' => self::ROLE_ADMINISTRATOR]);
            
            $this->auditService->log(
                $companyId,
                $userId,
                'ownership.transferred',
                "Propiedad transferida a {$member->user->name}",
                'CompanyMember',
                $memberId,
                ['previous_owner' => $userId, 'new_owner' => $member->user_id]
            );
        }

        // Solo el owner puede cambiar roles de owner
        if ($member->role === self::ROLE_OWNER) {
            throw new ForbiddenException('No se puede cambiar el rol del propietario. Debe transferir la propiedad primero');
        }

        // Solo el owner puede cambiar el rol de administradores o asignar administrador
        if ($member->role === self::ROLE_ADMINISTRATOR || $newRole === self::ROLE_ADMINISTRATOR) {
            if ($currentUserMember->role !== self::ROLE_OWNER) {
                throw new ForbiddenException('Solo el propietario puede modificar o asignar el rol de administrador');
            }
        }

        $oldRole = $member->role;
        $member->update(['role' => $newRole]);
        $member->refresh();

        $this->auditService->log(
            $companyId,
            $userId,
            'member.role_updated',
            "Rol de {$member->user->name} cambiado de {$oldRole} a {$newRole}",
            'CompanyMember',
            $memberId,
            ['old_role' => $oldRole, 'new_role' => $newRole]
        );

        $userName = trim("{$member->user->first_name} {$member->user->last_name}") ?: $member->user->email;
        app(NotificationService::class)->createForCompanyMembers(
            $companyId,
            'member_role_updated',
            'Rol actualizado',
            "El rol de {$userName} fue cambiado a {$newRole}",
            ['user_id' => $member->user_id, 'user_name' => $userName, 'old_role' => $oldRole, 'new_role' => $newRole],
            $userId
        );

        return $this->formatMemberData($member->load('user'));
    }

    public function removeMember(string $companyId, string $memberId, string $userId): bool
    {
        $this->validateAdministratorAccess($companyId, $userId);

        $member = CompanyMember::where('company_id', $companyId)
            ->where('id', $memberId)
            ->where('is_active', true)
            ->with('user')
            ->firstOrFail();

        if ($member->user_id === $userId) {
            throw new BadRequestException('No puedes removerte a ti mismo');
        }

        if ($member->role === self::ROLE_OWNER) {
            throw new ForbiddenException('No se puede remover al propietario de la empresa');
        }

        $currentUserMember = CompanyMember::where('company_id', $companyId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->firstOrFail();

        // Solo el owner puede remover administradores
        if ($member->role === self::ROLE_ADMINISTRATOR && $currentUserMember->role !== self::ROLE_OWNER) {
            throw new ForbiddenException('Solo el propietario puede remover administradores');
        }

        if ($member->role === self::ROLE_ADMINISTRATOR) {
            $this->validateMinimumAdministrators($companyId);
        }

        // Guardar datos ANTES de eliminar
        $userName = trim("{$member->user->first_name} {$member->user->last_name}") ?: $member->user->email;
        $memberUserId = $member->user_id;
        $memberRole = $member->role;
        
        $member->delete();

        $this->auditService->log(
            $companyId,
            $userId,
            'member.removed',
            "Miembro {$userName} removido de la empresa",
            'CompanyMember',
            $memberId,
            ['member_role' => $memberRole]
        );

        app(NotificationService::class)->createForCompanyMembers(
            $companyId,
            'member_removed',
            'Miembro removido',
            "{$userName} fue removido de la empresa",
            ['user_id' => $memberUserId, 'user_name' => $userName, 'role' => $memberRole],
            $userId
        );

        return true;
    }

    private function validateUserAccess(string $companyId, string $userId): void
    {
        $isMember = CompanyMember::where('company_id', $companyId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->exists();

        if (!$isMember) {
            throw new ForbiddenException('No tienes acceso a esta empresa');
        }
    }

    private function validateAdministratorAccess(string $companyId, string $userId): void
    {
        $hasAccess = CompanyMember::where('company_id', $companyId)
            ->where('user_id', $userId)
            ->whereIn('role', [self::ROLE_OWNER, self::ROLE_ADMINISTRATOR])
            ->where('is_active', true)
            ->exists();

        if (!$hasAccess) {
            throw new ForbiddenException('Solo los propietarios y administradores pueden realizar esta acción');
        }
    }

    private function validateRole(string $role): void
    {
        $validRoles = ['owner', 'administrator', 'financial_director', 'accountant', 'approver', 'operator'];
        
        if (!in_array($role, $validRoles)) {
            throw new BadRequestException('Rol inválido');
        }
    }

    private function validateMinimumAdministrators(string $companyId): void
    {
        $adminCount = CompanyMember::where('company_id', $companyId)
            ->where('role', self::ROLE_ADMINISTRATOR)
            ->where('is_active', true)
            ->count();

        if ($adminCount <= self::MIN_ADMINISTRATORS) {
            throw new BadRequestException('Debe haber al menos un administrador en la empresa');
        }
    }

    private function validateOwnershipTransfer(string $companyId, ?string $confirmationCode): void
    {
        if (!$confirmationCode) {
            throw new BadRequestException('Se requiere código de confirmación para transferir la propiedad');
        }

        $company = Company::findOrFail($companyId);
        
        if (!\Illuminate\Support\Facades\Hash::check($confirmationCode, $company->deletion_code)) {
            throw new BadRequestException('Código de confirmación incorrecto');
        }
    }

    private function formatMemberData(CompanyMember $member): array
    {
        $userName = trim("{$member->user->first_name} {$member->user->last_name}");
        if (empty($userName)) {
            $userName = $member->user->email;
        }

        return [
            'id' => $member->id,
            'userId' => $member->user_id,
            'name' => $userName,
            'email' => $member->user->email,
            'role' => $member->role,
            'isActive' => $member->is_active,
            'joinedAt' => $member->created_at->toIso8601String(),
            'lastActive' => $member->user->updated_at->toIso8601String(),
        ];
    }
}
