# Testing Guide - Repository Pattern Implementation

## Overview
Se han creado tests unitarios y de feature para validar que la refactorización del patrón Repository funciona correctamente.

## Estructura de Tests

### Tests Unitarios (Unit Tests)
Ubicados en `tests/Unit/Repositories/`

- **PaymentRepositoryTest.php** - Tests para PaymentRepository
  - `test_get_by_company_id_returns_payments`
  - `test_get_by_company_id_with_status_filter`
  - `test_get_by_company_id_with_date_filters`
  - `test_get_total_paid_for_invoice`
  - `test_get_by_invoice_ids`
  - `test_get_supplier_payments_by_company`
  - `test_get_by_company_id_returns_empty_for_different_company`

- **SupplierRepositoryTest.php** - Tests para SupplierRepository
  - `test_get_by_company_id_returns_suppliers`
  - `test_get_by_company_id_excludes_trashed`
  - `test_get_trashed_by_company_id`
  - `test_check_duplicate_document_*`
  - `test_find_by_document_and_company`
  - `test_restore_supplier`

- **ClientRepositoryTest.php** - Tests para ClientRepository
  - Similar a SupplierRepositoryTest

- **CompanyMemberRepositoryTest.php** - Tests para CompanyMemberRepository
  - `test_get_by_company_id_returns_members`
  - `test_get_by_company_id_and_user_id`
  - `test_get_by_company_id_and_role`
  - `test_check_member_exists_*`
  - `test_get_owner`
  - `test_search_by_email`

- **NotificationRepositoryTest.php** - Tests para NotificationRepository
  - `test_get_by_company_id_returns_notifications`
  - `test_get_by_company_id_with_limit`
  - `test_get_unread_by_company_id`
  - `test_get_unread_count_by_company_id`
  - `test_mark_as_read`
  - `test_mark_all_as_read_by_company_id`
  - `test_delete_old_notifications`

- **CompanyRepositoryTest.php** - Tests para CompanyRepository
  - `test_get_by_user_returns_companies`
  - `test_find_by_id_with_relations`
  - `test_get_with_members_and_certificates`
  - `test_check_duplicate_cuit_*`
  - `test_create_company`
  - `test_update_company`
  - `test_delete_company`

### Tests de Feature (Feature Tests)
Ubicados en `tests/Feature/`

- **PaymentControllerTest.php** - Tests para PaymentController
  - `test_index_returns_payments`
  - `test_index_filters_by_status`
  - `test_store_creates_payment`
  - `test_update_payment`
  - `test_destroy_deletes_payment`
  - `test_destroy_cannot_delete_confirmed_payment`
  - `test_confirm_payment`

- **SupplierControllerTest.php** - Tests para SupplierController
  - `test_index_returns_suppliers`
  - `test_index_excludes_trashed_suppliers`
  - `test_archived_returns_trashed_suppliers`
  - `test_store_creates_supplier`
  - `test_store_prevents_duplicate_document`
  - `test_update_supplier`
  - `test_destroy_soft_deletes_supplier`
  - `test_restore_supplier`

## Cómo Correr los Tests

### Todos los tests
```bash
php artisan test
```

### Solo tests unitarios
```bash
php artisan test tests/Unit
```

### Solo tests de feature
```bash
php artisan test tests/Feature
```

### Tests de un archivo específico
```bash
php artisan test tests/Unit/Repositories/PaymentRepositoryTest.php
```

### Tests de un método específico
```bash
php artisan test tests/Unit/Repositories/PaymentRepositoryTest.php --filter test_get_by_company_id_returns_payments
```

### Con output detallado
```bash
php artisan test --verbose
```

### Con coverage de código
```bash
php artisan test --coverage
```

## Configuración de Base de Datos para Tests

Los tests usan la base de datos configurada en `.env.testing`. Asegúrate de que esté configurada:

```env
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
```

O si prefieres una base de datos SQLite en archivo:

```env
DB_CONNECTION=sqlite
DB_DATABASE=database/testing.sqlite
```

## Factories Utilizadas

Los tests utilizan factories de Laravel para crear datos de prueba:

- `Company::factory()` - Crea empresas
- `User::factory()` - Crea usuarios
- `Supplier::factory()` - Crea proveedores
- `Client::factory()` - Crea clientes
- `Invoice::factory()` - Crea facturas
- `Payment::factory()` - Crea pagos
- `CompanyMember::factory()` - Crea miembros de empresa
- `Notification::factory()` - Crea notificaciones

## Traits Utilizados

- `RefreshDatabase` - Resetea la base de datos antes de cada test
- `Illuminate\Foundation\Testing\RefreshDatabase` - Disponible en tests de feature

## Validación de Refactorización

Estos tests validan que:

1. **Los Repositories funcionan correctamente** - Todos los métodos retornan datos esperados
2. **Los filtros funcionan** - Status, fechas, búsquedas
3. **Las relaciones se cargan** - Members, certificates, etc.
4. **Los soft deletes funcionan** - Archivado y restauración
5. **Los Controllers usan Repositories** - Endpoints retornan datos correctos
6. **La lógica de negocio se preserva** - Validaciones, restricciones

## Próximos Pasos

1. Agregar más tests para otros Controllers
2. Crear tests de integración para flujos complejos
3. Agregar tests de performance
4. Configurar CI/CD para correr tests automáticamente

## Troubleshooting

### Error: "SQLSTATE[HY000]: General error: 1 no such table"
Asegúrate de que las migraciones se ejecuten antes de los tests:
```bash
php artisan migrate --env=testing
```

### Error: "Class not found"
Verifica que los namespaces en los tests sean correctos y que los archivos estén en las carpetas correctas.

### Tests lentos
Usa `:memory:` en lugar de archivo SQLite para tests más rápidos.
