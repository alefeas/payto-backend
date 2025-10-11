# PayTo Backend API

Backend API para el sistema de facturación PayTo, construido con Laravel 11.

## 📁 Estructura del Proyecto

```
app/
├── DTOs/              # Data Transfer Objects
├── Enums/             # Enumeraciones
├── Events/            # Eventos del sistema
├── Exceptions/        # Excepciones personalizadas
├── Http/
│   ├── Controllers/   # Controladores API
│   ├── Middleware/    # Middleware personalizado
│   └── Requests/      # Form Requests para validación
├── Interfaces/        # Interfaces (contratos)
├── Jobs/              # Jobs para colas
├── Listeners/         # Event Listeners
├── Models/            # Modelos Eloquent
├── Observers/         # Model Observers
├── Policies/          # Políticas de autorización
├── Repositories/      # Repositorios (patrón Repository)
├── Rules/             # Reglas de validación personalizadas
├── Services/          # Lógica de negocio
└── Traits/            # Traits reutilizables

routes/
├── api.php           # Rutas API principales
└── api/              # Rutas modulares por recurso
    ├── auth.php
    ├── companies.php
    ├── invoices.php
    ├── payments.php
    ├── clients.php
    └── network.php
```

## 🏗️ Arquitectura

El proyecto sigue los principios SOLID y utiliza:

- **Repository Pattern**: Abstracción de la capa de datos
- **Service Layer**: Lógica de negocio separada de controladores
- **DTOs**: Transferencia de datos tipada
- **Traits**: Reutilización de código (ej: ApiResponse)
- **Policies**: Autorización basada en roles
- **Events & Listeners**: Desacoplamiento de acciones

## 🚀 Instalación

1. Clonar el repositorio
2. Copiar `.env.example` a `.env`
3. Configurar base de datos en `.env`
4. Instalar dependencias:
```bash
composer install
```

5. Generar key:
```bash
php artisan key:generate
```

6. Ejecutar migraciones:
```bash
php artisan migrate
```

7. Iniciar servidor:
```bash
php artisan serve
```

## 📝 Convenciones

### Controladores
- Usar `ApiResponse` trait para respuestas consistentes
- Delegar lógica de negocio a Services
- Validar con Form Requests

### Servicios
- Un servicio por entidad principal
- Métodos descriptivos y específicos
- Usar repositorios para acceso a datos

### Repositorios
- Extender `BaseRepository`
- Implementar métodos específicos del modelo
- No incluir lógica de negocio

### Modelos
- Usar enums para estados
- Definir relaciones claramente
- Usar observers para eventos del modelo

## 🔐 Autenticación

Laravel Sanctum para autenticación de API con tokens.

## 📚 Documentación API

Endpoint de salud: `GET /api/health`

Todas las rutas API están bajo el prefijo `/api/v1`

## 🧪 Testing

```bash
php artisan test
```

## 📄 Licencia

Proyecto privado - PayTo
