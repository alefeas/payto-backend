# Comandos Útiles - Sistema de Configuración AFIP

## 🚀 Comandos Rápidos

### Ver Configuración Actual

```bash
# Ver toda la configuración AFIP
php artisan tinker
>>> config('afip_rules')

# Ver solo mensajes de error
>>> config('afip_rules.error_messages')

# Ver mensaje específico
>>> config('afip_rules.error_messages.10013')

# Ver tipos de comprobante permitidos
>>> config('afip_rules.voucher_compatibility')

# Ver mensajes de validación
>>> config('afip_rules.validation_messages')
```

### Limpiar Cache de Configuración

```bash
# Limpiar cache (IMPORTANTE después de cambios)
php artisan config:cache

# Limpiar todo el cache
php artisan cache:clear

# Limpiar cache de configuración específicamente
php artisan config:clear
```

### Verificar Sintaxis

```bash
# Verificar que el archivo PHP no tenga errores
php -l config/afip_rules.php
```

## 📝 Workflow Completo de Cambio

### Escenario: Cambiar mensaje de error 10013

```bash
# 1. Editar archivo
nano config/afip_rules.php
# o
code config/afip_rules.php

# 2. Verificar sintaxis
php -l config/afip_rules.php

# 3. Ver cambio en local
php artisan tinker
>>> config('afip_rules.error_messages.10013')

# 4. Limpiar cache local
php artisan config:cache

# 5. Commit
git add config/afip_rules.php
git commit -m "Update error message 10013"
git push

# 6. Deploy en servidor
ssh tu-servidor
cd /path/to/payto-back
git pull
php artisan config:cache
exit
```

## 🔍 Debugging

### Ver qué mensaje se está usando

```bash
# En local
php artisan tinker
>>> config('afip_rules.error_messages.10013.message')
```

### Verificar que el cache se actualizó

```bash
# Ver archivo de cache
cat bootstrap/cache/config.php | grep "10013"
```

### Forzar recarga de configuración

```bash
# Borrar cache y recargar
php artisan config:clear
php artisan config:cache
```

## 🛠️ Comandos de Desarrollo

### Agregar nuevo código de error

```bash
# 1. Editar config
nano config/afip_rules.php

# 2. Agregar en 'error_messages':
'10025' => [
    'title' => 'Nuevo error',
    'message' => 'Descripción',
    'solution' => 'Solución',
],

# 3. Guardar y verificar
php -l config/afip_rules.php
php artisan config:cache
```

### Agregar tipo de comprobante permitido

```bash
# 1. Editar config
nano config/afip_rules.php

# 2. Agregar código en 'allowed_vouchers':
'monotributista' => [
    'allowed_vouchers' => ['6', '7', '8', '11', '12', '13', '19', '51', '52', '53'],
    #                                                      ↑ nuevo
],

# 3. Guardar y verificar
php artisan config:cache
```

## 📊 Comandos de Monitoreo

### Ver logs de errores AFIP

```bash
# Ver últimos errores
tail -f storage/logs/laravel.log | grep "AFIP"

# Ver errores de hoy
grep "AFIP" storage/logs/laravel-$(date +%Y-%m-%d).log
```

### Contar errores por código

```bash
# Contar cuántas veces apareció cada error
grep "AFIP Error" storage/logs/laravel.log | grep -oP '\[\d+\]' | sort | uniq -c
```

## 🔄 Comandos de Rollback

### Volver a versión anterior

```bash
# Ver historial de cambios
git log --oneline config/afip_rules.php

# Volver a commit específico
git checkout <commit-hash> config/afip_rules.php

# Aplicar cambio
git commit -m "Rollback AFIP config"
git push

# En servidor
ssh servidor
cd /path/to/payto-back
git pull
php artisan config:cache
```

## 🧪 Testing

### Probar mensaje de error en local

```bash
# Crear test rápido
php artisan tinker

# Simular error
>>> $code = '10013';
>>> $config = config('afip_rules.error_messages.' . $code);
>>> echo $config['message'];
>>> echo $config['solution'];
```

### Verificar todos los mensajes

```bash
php artisan tinker

# Ver todos los códigos de error configurados
>>> array_keys(config('afip_rules.error_messages'))

# Verificar que todos tengan title, message, solution
>>> collect(config('afip_rules.error_messages'))->each(function($error, $code) {
...     if (!isset($error['title']) || !isset($error['message']) || !isset($error['solution'])) {
...         echo "Error $code está incompleto\n";
...     }
... });
```

## 📦 Comandos de Backup

### Backup de configuración

```bash
# Crear backup
cp config/afip_rules.php config/afip_rules.backup.php

# O con fecha
cp config/afip_rules.php config/afip_rules.$(date +%Y%m%d).php
```

### Restaurar backup

```bash
# Restaurar desde backup
cp config/afip_rules.backup.php config/afip_rules.php
php artisan config:cache
```

## 🚨 Comandos de Emergencia

### Si algo sale mal

```bash
# 1. Volver a última versión estable
git checkout HEAD~1 config/afip_rules.php

# 2. Limpiar cache
php artisan config:clear
php artisan config:cache

# 3. Verificar
php artisan tinker
>>> config('afip_rules.error_messages.10013')
```

### Si el servidor no responde

```bash
# 1. SSH al servidor
ssh servidor

# 2. Ver logs
tail -100 storage/logs/laravel.log

# 3. Limpiar cache
cd /path/to/payto-back
php artisan config:clear
php artisan cache:clear

# 4. Reiniciar servicios
sudo systemctl restart php8.2-fpm
sudo systemctl restart nginx
```

## 📋 Checklist de Deploy

```bash
# Antes de deploy
[ ] Verificar sintaxis: php -l config/afip_rules.php
[ ] Probar en local: php artisan tinker
[ ] Commit: git commit -m "..."
[ ] Push: git push

# En servidor
[ ] SSH: ssh servidor
[ ] Pull: git pull
[ ] Cache: php artisan config:cache
[ ] Verificar: php artisan tinker
[ ] Monitorear logs: tail -f storage/logs/laravel.log
```

## 🎯 Comandos Más Usados

```bash
# Top 5 comandos que vas a usar:

# 1. Editar config
nano config/afip_rules.php

# 2. Limpiar cache
php artisan config:cache

# 3. Ver config
php artisan tinker
>>> config('afip_rules.error_messages')

# 4. Verificar sintaxis
php -l config/afip_rules.php

# 5. Deploy
git add config/afip_rules.php && git commit -m "Update AFIP config" && git push
```

## 💡 Tips

### Alias útiles

Agregá estos alias a tu `.bashrc` o `.zshrc`:

```bash
# Alias para comandos frecuentes
alias afip-config="nano config/afip_rules.php"
alias afip-cache="php artisan config:cache"
alias afip-check="php -l config/afip_rules.php"
alias afip-view="php artisan tinker --execute='print_r(config(\"afip_rules.error_messages\"));'"
```

### Script de deploy automático

Creá un script `deploy-afip-config.sh`:

```bash
#!/bin/bash
echo "Verificando sintaxis..."
php -l config/afip_rules.php || exit 1

echo "Commit y push..."
git add config/afip_rules.php
git commit -m "Update AFIP config"
git push

echo "Deploy en servidor..."
ssh servidor "cd /path/to/payto-back && git pull && php artisan config:cache"

echo "✅ Deploy completado!"
```

Uso:
```bash
chmod +x deploy-afip-config.sh
./deploy-afip-config.sh
```

## 📞 Soporte

Si tenés problemas:

1. Verificá sintaxis: `php -l config/afip_rules.php`
2. Limpiá cache: `php artisan config:cache`
3. Revisá logs: `tail -f storage/logs/laravel.log`
4. Contactá soporte con el error específico
