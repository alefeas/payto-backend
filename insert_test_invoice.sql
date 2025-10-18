-- Script para insertar una factura de prueba
-- Ejecutar en MySQL/MariaDB

-- 1. Obtener el ID de la empresa con CUIT 27214383794
SET @company_id = (SELECT id FROM companies WHERE national_id = '27214383794' LIMIT 1);

-- 2. Crear un proveedor de prueba si no existe
INSERT INTO suppliers (id, company_id, document_type, document_number, business_name, tax_condition, created_at, updated_at)
VALUES (
    UUID(),
    @company_id,
    'CUIT',
    '20123456789',
    'Proveedor de Prueba SA',
    'registered_taxpayer',
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE id=id;

SET @supplier_id = (SELECT id FROM suppliers WHERE company_id = @company_id AND document_number = '20123456789' LIMIT 1);

-- 3. Insertar la factura
INSERT INTO invoices (
    id,
    number,
    type,
    sales_point,
    voucher_number,
    concept,
    issuer_company_id,
    receiver_company_id,
    supplier_id,
    issue_date,
    due_date,
    subtotal,
    total_taxes,
    total_perceptions,
    total,
    currency,
    exchange_rate,
    status,
    afip_status,
    approvals_required,
    approvals_received,
    created_by,
    created_at,
    updated_at
) VALUES (
    UUID(),
    '0001-00000001',
    'B',
    1,
    1,
    'products',
    @company_id,
    @company_id,
    @supplier_id,
    CURDATE(),
    DATE_ADD(CURDATE(), INTERVAL 30 DAY),
    10000.00,
    2100.00,
    0.00,
    12100.00,
    'ARS',
    1.00,
    'pending_approval',
    'approved',
    0,
    0,
    (SELECT user_id FROM company_members WHERE company_id = @company_id LIMIT 1),
    NOW(),
    NOW()
);

SET @invoice_id = LAST_INSERT_ID();

-- 4. Insertar un item de la factura
INSERT INTO invoice_items (
    id,
    invoice_id,
    description,
    quantity,
    unit_price,
    discount_percentage,
    tax_rate,
    tax_amount,
    subtotal,
    order_index,
    created_at,
    updated_at
) VALUES (
    UUID(),
    (SELECT id FROM invoices WHERE number = '0001-00000001' AND receiver_company_id = @company_id LIMIT 1),
    'Producto de Prueba',
    1,
    10000.00,
    0,
    21.00,
    2100.00,
    10000.00,
    0,
    NOW(),
    NOW()
);

SELECT 'Factura insertada correctamente' AS resultado;
