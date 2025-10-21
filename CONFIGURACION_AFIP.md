# Configuración de Reglas AFIP

Este documento explica cómo modificar las reglas de validación de AFIP sin tocar el código.

## 📁 Ubicación del Archivo

```
payto-back/config/afip_rules.php
```

## 🎯 ¿Qué Puedes Cambiar?

### 1. Mensajes de Error de AFIP

Cuando AFIP devuelve un error (ej: código 10013), el sistema muestra un mensaje amigable.

**Ejemplo:**
```php
'error_messages' => [
    '10013' => [
        'title' => 'Tipo de documento incorrecto',
        'message' => 'El tipo de documento del receptor no coincide...',
        'solution' => 'Verifique que el tipo de documento...',
    ],
],
```

**Para agregar un nuevo error:**
1. Abrí `config/afip_rules.php`
2. Agregá el código de error en `error_messages`:
```php
'10025' => [
    'title' => 'Nuevo error',
    'message' => 'Descripción del error',
    'solution' => 'Cómo solucionarlo',
],
```
3. Guardá el archivo
4. Hacé deploy
5. Ejecutá: `php artisan config:cache`

### 2. Mensajes de Validación

Mensajes que se muestran cuando el usuario completa mal el formulario.

**Ejemplo:**
```php
'validation_messages' => [
    'concept.required' => 'El concepto es obligatorio',
    'service_date_from.required_if' => 'La fecha de inicio del servicio es obligatoria',
],
```

**Para cambiar un mensaje:**
1. Abrí `config/afip_rules.php`
2. Modificá el mensaje en `validation_messages`
3. Guardá y hacé deploy
4. Ejecutá: `php artisan config:cache`

### 3. Tipos de Comprobante Permitidos

Define qué tipos de comprobante puede emitir cada condición fiscal.

**Ejemplo:**
```php
'voucher_compatibility' => [
    'responsable_inscripto' => [
        'allowed_vouchers' => ['1', '2', '3', '6', '7', '8', '11', '12', '13'],
        'description' => 'Responsable Inscripto puede emitir: Factura A, B, C y sus NC/ND',
    ],
    'monotributista' => [
        'allowed_vouchers' => ['6', '7', '8', '11', '12', '13', '51', '52', '53'],
        'description' => 'Monotributista puede emitir: Factura B, C, M y sus NC/ND',
    ],
],
```

**Si AFIP cambia las reglas (ej: Monotributista ahora puede emitir Factura E):**
1. Abrí `config/afip_rules.php`
2. Agregá el código '19' a `allowed_vouchers` de monotributista:
```php
'monotributista' => [
    'allowed_vouchers' => ['6', '7', '8', '11', '12', '13', '19', '51', '52', '53'],
    'description' => 'Monotributista puede emitir: Factura B, C, E, M y sus NC/ND',
],
```
3. Guardá y hacé deploy
4. Ejecutá: `php artisan config:cache`

### 4. Compatibilidad Emisor-Receptor

Define qué receptores pueden recibir cada tipo de comprobante.

**Ejemplo:**
```php
'issuer_receiver_compatibility' => [
    '1' => [ // Factura A
        'allowed_receivers' => ['responsable_inscripto'],
        'description' => 'Factura A solo puede emitirse a Responsable Inscripto',
    ],
    '51' => [ // Factura M
        'allowed_receivers' => ['responsable_inscripto', 'monotributista', 'exento'],
        'description' => 'Factura M puede emitirse a RI, Monotributista o Exento (NO a Consumidor Final)',
    ],
],
```

### 5. Campos Obligatorios por Concepto

Define qué campos son obligatorios según el concepto (productos, servicios, productos y servicios).

**Ejemplo:**
```php
'required_fields_by_concept' => [
    'services' => [
        'fields' => ['amount', 'voucher_type', 'issue_date', 'service_date_from', 'service_date_to'],
        'description' => 'Concepto Servicios: requiere fechas de servicio',
    ],
],
```

## 🔄 Cómo Aplicar Cambios

### Paso 1: Editar el Archivo
```bash
# Abrí el archivo con tu editor favorito
nano config/afip_rules.php
# o
code config/afip_rules.php
```

### Paso 2: Hacer Deploy
```bash
git add config/afip_rules.php
git commit -m "Update AFIP rules"
git push
```

### Paso 3: Limpiar Cache en Servidor
```bash
ssh tu-servidor
cd /path/to/payto-back
php artisan config:cache
```

**Tiempo total: 5 minutos**

## ⚠️ Importante

### ✅ Lo que SÍ podés cambiar:
- Textos de mensajes
- Códigos de comprobante permitidos
- Condiciones fiscales permitidas
- Descripciones

### ❌ Lo que NO podés cambiar (requiere programador):
- Lógica de validación
- Algoritmos de cálculo
- Estructura de datos
- Nuevos campos en la base de datos

## 📋 Códigos de Comprobante AFIP

| Código | Tipo |
|--------|------|
| 1 | Factura A |
| 2 | Nota de Débito A |
| 3 | Nota de Crédito A |
| 6 | Factura B |
| 7 | Nota de Débito B |
| 8 | Nota de Crédito B |
| 11 | Factura C |
| 12 | Nota de Débito C |
| 13 | Nota de Crédito C |
| 19 | Factura E (Exportación) |
| 51 | Factura M |
| 52 | Nota de Débito M |
| 53 | Nota de Crédito M |

## 📋 Condiciones Fiscales

| Código | Descripción |
|--------|-------------|
| registered_taxpayer | Responsable Inscripto |
| monotax | Monotributista |
| exempt | Exento |
| final_consumer | Consumidor Final |

## 🔍 Ejemplo Completo de Cambio

**Escenario:** AFIP anuncia que Monotributistas ahora pueden emitir Factura E (código 19).

**Antes:**
```php
'monotributista' => [
    'allowed_vouchers' => ['6', '7', '8', '11', '12', '13', '51', '52', '53'],
],
```

**Después:**
```php
'monotributista' => [
    'allowed_vouchers' => ['6', '7', '8', '11', '12', '13', '19', '51', '52', '53'],
    'description' => 'Monotributista puede emitir: Factura B, C, E, M y sus NC/ND',
],
```

**También agregar compatibilidad:**
```php
'19' => [ // Factura E
    'allowed_receivers' => ['responsable_inscripto', 'monotributista', 'exento', 'final_consumer'],
    'description' => 'Factura E puede emitirse a cualquier receptor',
],
```

**Comandos:**
```bash
# 1. Editar archivo
nano config/afip_rules.php

# 2. Guardar cambios
git add config/afip_rules.php
git commit -m "Add Factura E support for Monotributista"
git push

# 3. Deploy y limpiar cache
ssh servidor
cd /path/to/payto-back
git pull
php artisan config:cache
```

**Tiempo: 5 minutos**

## 🆘 Soporte

Si necesitás agregar algo que no está en este archivo, contactá al equipo de desarrollo.

**Cambios simples (5 min):**
- Mensajes de error
- Tipos de comprobante permitidos
- Condiciones fiscales

**Cambios complejos (requiere dev):**
- Nueva lógica de validación
- Nuevos campos en formularios
- Cambios en base de datos
- Nuevos cálculos
