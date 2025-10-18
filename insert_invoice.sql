-- Insertar factura de prueba para CUIT 27214383794
-- Copiar y pegar en phpMyAdmin o MySQL

INSERT INTO invoices (
    id,
    number,
    type,
    sales_point,
    voucher_number,
    concept,
    issuer_company_id,
    receiver_company_id,
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
    created_at,
    updated_at
) 
SELECT 
    UUID(),
    '0001-99999999',
    'B',
    1,
    99999999,
    'products',
    id,
    id,
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
    NOW(),
    NOW()
FROM companies 
WHERE national_id = '27214383794' 
LIMIT 1;
