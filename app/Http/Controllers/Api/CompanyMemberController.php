<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Interfaces\CompanyMemberServiceInterface;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyMemberController extends Controller
{
    use ApiResponse;

    private CompanyMemberServiceInterface $memberService;

    public function __construct(CompanyMemberServiceInterface $memberService)
    {
        $this->memberService = $memberService;
    }

    public function index(string $companyId): JsonResponse
    {
        $members = $this->memberService->getCompanyMembers($companyId, auth()->id());
        return $this->success($members, 'Miembros obtenidos exitosamente');
    }

    public function updateRole(Request $request, string $companyId, string $memberId): JsonResponse
    {
        $request->validate([
            'role' => 'required|string|in:owner,administrator,financial_director,accountant,approver,operator',
            'confirmation_code' => 'nullable|string'
        ]);

        $member = $this->memberService->updateMemberRole(
            $companyId,
            $memberId,
            $request->input('role'),
            auth()->id(),
            $request->input('confirmation_code')
        );

        return $this->success($member, 'Rol actualizado exitosamente');
    }

    public function destroy(string $companyId, string $memberId): JsonResponse
    {
        $this->memberService->removeMember($companyId, $memberId, auth()->id());
        return $this->success(null, 'Miembro removido exitosamente');
    }
}
