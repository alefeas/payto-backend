# Ejemplo de Rutas con Control de Acceso por Workspace

## Uso de Middleware

```php
// routes/api.php

// Rutas que requieren membresía en la empresa
Route::middleware(['auth:sanctum', 'company.member'])->group(function () {
    
    // Ver datos de la empresa (cualquier miembro)
    Route::get('/companies/{company}', [CompanyController::class, 'show']);
    Route::get('/companies/{company}/invoices', [InvoiceController::class, 'index']);
    
});

// Rutas que requieren rol específico
Route::middleware(['auth:sanctum', 'company.role:administrator,financial_director'])->group(function () {
    
    // Solo administradores y directores financieros
    Route::post('/companies/{company}/members', [CompanyMemberController::class, 'store']);
    Route::put('/companies/{company}', [CompanyController::class, 'update']);
    Route::delete('/companies/{company}/members/{member}', [CompanyMemberController::class, 'destroy']);
    
});

Route::middleware(['auth:sanctum', 'company.role:administrator'])->group(function () {
    
    // Solo administradores
    Route::delete('/companies/{company}', [CompanyController::class, 'destroy']);
    Route::post('/companies/{company}/settings', [CompanyController::class, 'updateSettings']);
    
});

Route::middleware(['auth:sanctum', 'company.role:administrator,financial_director,accountant'])->group(function () {
    
    // Crear/editar facturas
    Route::post('/companies/{company}/invoices', [InvoiceController::class, 'store']);
    Route::put('/companies/{company}/invoices/{invoice}', [InvoiceController::class, 'update']);
    
});

Route::middleware(['auth:sanctum', 'company.role:approver'])->group(function () {
    
    // Aprobar facturas
    Route::post('/companies/{company}/invoices/{invoice}/approve', [InvoiceController::class, 'approve']);
    Route::post('/companies/{company}/invoices/{invoice}/reject', [InvoiceController::class, 'reject']);
    
});
```

## Roles Disponibles

Según tu schema:
- `administrator` - Control total
- `financial_director` - Gestión financiera y usuarios
- `accountant` - Crear/editar facturas y pagos
- `approver` - Aprobar/rechazar facturas
- `operator` - Solo lectura y operaciones básicas

## Ejemplo en Controller

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function store(Request $request, $companyId)
    {
        // El middleware ya validó que el usuario pertenece a la empresa
        // y tiene el rol correcto
        
        $userRole = $request->input('user_role'); // Inyectado por middleware
        
        $invoice = Invoice::create([
            'company_id' => $companyId,
            'created_by' => $request->user()->id,
            // ... otros campos
        ]);
        
        return response()->json($invoice, 201);
    }
}
```

## Verificación Manual en Código

```php
// Si necesitas verificar permisos manualmente
$user = auth()->user();
$companyId = $request->input('company_id');

$member = $user->companyMembers()
    ->where('company_id', $companyId)
    ->where('is_active', true)
    ->first();

if (!$member) {
    throw new UnauthorizedException('No perteneces a esta empresa');
}

if (!in_array($member->role, ['administrator', 'financial_director'])) {
    throw new UnauthorizedException('No tienes permisos suficientes');
}
```

## Frontend - Verificar Permisos

```typescript
// types/roles.ts
export enum CompanyRole {
  ADMINISTRATOR = 'administrator',
  FINANCIAL_DIRECTOR = 'financial_director',
  ACCOUNTANT = 'accountant',
  APPROVER = 'approver',
  OPERATOR = 'operator'
}

// hooks/useCompanyPermissions.ts
export function useCompanyPermissions(companyId: string) {
  const { user } = useAuth();
  
  const membership = user?.companies?.find(c => c.id === companyId);
  const role = membership?.pivot?.role;
  
  return {
    canManageUsers: ['administrator', 'financial_director'].includes(role),
    canCreateInvoices: ['administrator', 'financial_director', 'accountant'].includes(role),
    canApproveInvoices: ['approver'].includes(role),
    canDeleteCompany: role === 'administrator',
    isActive: membership?.pivot?.is_active
  };
}

// Uso en componente
function InvoiceActions({ companyId }) {
  const { canCreateInvoices, canApproveInvoices } = useCompanyPermissions(companyId);
  
  return (
    <>
      {canCreateInvoices && <Button>Crear Factura</Button>}
      {canApproveInvoices && <Button>Aprobar</Button>}
    </>
  );
}
```
