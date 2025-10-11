# Arquitectura del Backend PayTo

## Principios SOLID Aplicados

### 1. Single Responsibility Principle (SRP)
- **Controladores**: Solo manejan requests/responses HTTP
- **Servicios**: Contienen lógica de negocio específica
- **Repositorios**: Solo acceso a datos
- **Models**: Solo representación de datos y relaciones

### 2. Open/Closed Principle (OCP)
- Uso de interfaces para extensibilidad
- Traits para funcionalidad compartida
- Eventos para extensión sin modificación

### 3. Liskov Substitution Principle (LSP)
- `BaseRepository` puede ser sustituido por cualquier repositorio específico
- Interfaces garantizan contratos consistentes

### 4. Interface Segregation Principle (ISP)
- Interfaces específicas por funcionalidad
- No forzar implementación de métodos innecesarios

### 5. Dependency Inversion Principle (DIP)
- Controladores dependen de interfaces, no implementaciones
- Inyección de dependencias en constructores
- Service Container de Laravel para resolución

## Flujo de Datos

```
Request → Middleware → Controller → Service → Repository → Model → Database
                                       ↓
                                     Events
                                       ↓
                                   Listeners
```

## Capas de la Aplicación

### 1. Capa de Presentación (HTTP)
- **Controllers**: Reciben requests, validan, llaman servicios
- **Requests**: Validación de datos de entrada
- **Resources**: Transformación de datos de salida
- **Middleware**: Autenticación, autorización, logging

### 2. Capa de Lógica de Negocio
- **Services**: Orquestan operaciones complejas
- **DTOs**: Transferencia de datos entre capas
- **Events**: Notificaciones de cambios de estado
- **Jobs**: Tareas asíncronas

### 3. Capa de Acceso a Datos
- **Repositories**: Abstracción de consultas
- **Models**: Representación de entidades
- **Observers**: Reacción a eventos del modelo

### 4. Capa de Infraestructura
- **Enums**: Constantes tipadas
- **Traits**: Funcionalidad reutilizable
- **Exceptions**: Manejo de errores
- **Policies**: Autorización

## Patrones de Diseño Utilizados

### Repository Pattern
```php
interface UserRepositoryInterface {
    public function find(int $id): ?User;
    public function create(array $data): User;
}

class UserRepository extends BaseRepository implements UserRepositoryInterface {
    // Implementación específica
}
```

### Service Layer Pattern
```php
class InvoiceService {
    public function __construct(
        private InvoiceRepository $invoiceRepository,
        private AfipService $afipService
    ) {}
    
    public function createInvoice(CreateInvoiceDTO $dto): Invoice {
        // Lógica de negocio compleja
    }
}
```

### DTO Pattern
```php
class CreateInvoiceDTO {
    public function __construct(
        public readonly string $type,
        public readonly int $companyId,
        public readonly array $items
    ) {}
}
```

## Convenciones de Código

### Naming
- **Controllers**: `{Resource}Controller` (ej: `InvoiceController`)
- **Services**: `{Resource}Service` (ej: `InvoiceService`)
- **Repositories**: `{Resource}Repository` (ej: `InvoiceRepository`)
- **Interfaces**: `{Resource}RepositoryInterface`
- **DTOs**: `{Action}{Resource}DTO` (ej: `CreateInvoiceDTO`)
- **Events**: `{Resource}{Action}` (ej: `InvoiceCreated`)
- **Listeners**: `{Action}{Resource}Listener` (ej: `SendInvoiceNotification`)

### Métodos de Controlador
- `index()`: Listar recursos
- `store()`: Crear recurso
- `show($id)`: Mostrar recurso
- `update($id)`: Actualizar recurso
- `destroy($id)`: Eliminar recurso

### Respuestas API
Usar `ApiResponse` trait:
```php
return $this->success($data, 'Message', 200);
return $this->error('Error message', 400);
return $this->created($data);
return $this->notFound();
```

## Testing

### Estructura de Tests
```
tests/
├── Feature/          # Tests de integración
│   ├── Auth/
│   ├── Invoice/
│   └── Payment/
└── Unit/             # Tests unitarios
    ├── Services/
    └── Repositories/
```

### Convenciones
- Un test por método público
- Usar factories para datos de prueba
- Mockear dependencias externas (AFIP, AWS)

## Seguridad

### Autenticación
- Laravel Sanctum para tokens API
- Middleware `auth:sanctum` en rutas protegidas

### Autorización
- Policies para cada modelo
- Gates para permisos específicos
- Middleware `can:` para verificación

### Validación
- Form Requests para validación de entrada
- Custom Rules para validaciones complejas
- Sanitización de datos

## Performance

### Optimizaciones
- Eager loading para evitar N+1
- Cache para datos frecuentes
- Queue para tareas pesadas
- Índices en base de datos

### Monitoreo
- Logs estructurados
- Métricas de performance
- Error tracking

## Deployment

### Checklist
1. Configurar variables de entorno
2. Ejecutar migraciones
3. Compilar assets
4. Configurar queue workers
5. Configurar cron jobs
6. Configurar SSL
7. Optimizar autoloader
8. Cache de configuración

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
composer install --optimize-autoloader --no-dev
```
