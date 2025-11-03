# Actualización de URLs en .env

## URLs de Cloudflare

- **Frontend**: https://funky-jonathan-implemented-simon.trycloudflare.com
- **Backend**: https://pie-toll-eligibility-mls.trycloudflare.com

## Backend (.env)

Edita `payto-back/.env` y actualiza estas líneas:

```env
APP_URL=https://pie-toll-eligibility-mls.trycloudflare.com
FRONTEND_URL=https://funky-jonathan-implemented-simon.trycloudflare.com
```

## Frontend (.env.local)

Edita `payto-front/.env.local` y actualiza:

```env
NEXT_PUBLIC_API_URL=https://pie-toll-eligibility-mls.trycloudflare.com/api/v1
```

## Después de Actualizar

```bash
# Backend
cd payto-back
php artisan config:clear
php artisan cache:clear

# Frontend
cd payto-front
# Reinicia el servidor de desarrollo
```

