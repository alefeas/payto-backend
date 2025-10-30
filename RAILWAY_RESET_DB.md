# Reset Database en Railway

Para aplicar las migraciones corregidas, necesitas resetear la base de datos:

## Opción 1: Desde Railway Dashboard
1. Ve a tu proyecto en Railway
2. Click en el servicio MySQL/PostgreSQL
3. Variables → Encuentra DATABASE_URL
4. Conecta con un cliente SQL y ejecuta: `DROP DATABASE nombre_db; CREATE DATABASE nombre_db;`
5. O elimina y recrea el servicio de base de datos

## Opción 2: Comando Artisan (si tienes acceso SSH)
```bash
php artisan migrate:fresh --force
```

## Opción 3: Variable de entorno temporal
Agrega esta variable en Railway:
- `RUN_MIGRATIONS_FRESH=true`

Luego modifica el comando de inicio en Railway a:
```bash
if [ "$RUN_MIGRATIONS_FRESH" = "true" ]; then php artisan migrate:fresh --force; else php artisan migrate --force; fi && php artisan serve --host=0.0.0.0 --port=$PORT
```

Después del deploy, elimina la variable `RUN_MIGRATIONS_FRESH`.
