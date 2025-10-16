<?php

namespace App\Services;

class VoucherTypeService
{
    // Definición completa de tipos de comprobantes
    public static function getVoucherTypes(): array
    {
        return [
            // FACTURAS
            'A' => ['code' => '001', 'name' => 'Factura A', 'category' => 'invoice', 'requires_association' => false],
            'B' => ['code' => '006', 'name' => 'Factura B', 'category' => 'invoice', 'requires_association' => false],
            'C' => ['code' => '011', 'name' => 'Factura C', 'category' => 'invoice', 'requires_association' => false],
            'M' => ['code' => '051', 'name' => 'Factura M', 'category' => 'invoice', 'requires_association' => false],
            'E' => ['code' => '019', 'name' => 'Factura E (Exportación)', 'category' => 'invoice', 'requires_association' => false],
            
            // NOTAS DE CRÉDITO
            'NCA' => ['code' => '003', 'name' => 'Nota de Crédito A', 'category' => 'credit_note', 'requires_association' => true, 'compatible_with' => ['A']],
            'NCB' => ['code' => '008', 'name' => 'Nota de Crédito B', 'category' => 'credit_note', 'requires_association' => true, 'compatible_with' => ['B']],
            'NCC' => ['code' => '013', 'name' => 'Nota de Crédito C', 'category' => 'credit_note', 'requires_association' => true, 'compatible_with' => ['C']],
            'NCM' => ['code' => '053', 'name' => 'Nota de Crédito M', 'category' => 'credit_note', 'requires_association' => true, 'compatible_with' => ['M']],
            'NCE' => ['code' => '021', 'name' => 'Nota de Crédito E', 'category' => 'credit_note', 'requires_association' => true, 'compatible_with' => ['E']],
            
            // NOTAS DE DÉBITO
            'NDA' => ['code' => '002', 'name' => 'Nota de Débito A', 'category' => 'debit_note', 'requires_association' => true, 'compatible_with' => ['A']],
            'NDB' => ['code' => '007', 'name' => 'Nota de Débito B', 'category' => 'debit_note', 'requires_association' => true, 'compatible_with' => ['B']],
            'NDC' => ['code' => '012', 'name' => 'Nota de Débito C', 'category' => 'debit_note', 'requires_association' => true, 'compatible_with' => ['C']],
            'NDM' => ['code' => '052', 'name' => 'Nota de Débito M', 'category' => 'debit_note', 'requires_association' => true, 'compatible_with' => ['M']],
            'NDE' => ['code' => '020', 'name' => 'Nota de Débito E', 'category' => 'debit_note', 'requires_association' => true, 'compatible_with' => ['E']],
            
            // RECIBOS
            'RA' => ['code' => '004', 'name' => 'Recibo A', 'category' => 'receipt', 'requires_association' => false],
            'RB' => ['code' => '009', 'name' => 'Recibo B', 'category' => 'receipt', 'requires_association' => false],
            'RC' => ['code' => '015', 'name' => 'Recibo C', 'category' => 'receipt', 'requires_association' => false],
            'RM' => ['code' => '049', 'name' => 'Recibo M', 'category' => 'receipt', 'requires_association' => false],
            
            // FACTURA DE CRÉDITO ELECTRÓNICA MiPyME
            'FCEA' => ['code' => '201', 'name' => 'Factura de Crédito Electrónica MiPyME A', 'category' => 'fce_mipyme', 'requires_association' => false, 'requires_mipyme' => true],
            'FCEB' => ['code' => '206', 'name' => 'Factura de Crédito Electrónica MiPyME B', 'category' => 'fce_mipyme', 'requires_association' => false, 'requires_mipyme' => true],
            'FCEC' => ['code' => '211', 'name' => 'Factura de Crédito Electrónica MiPyME C', 'category' => 'fce_mipyme', 'requires_association' => false, 'requires_mipyme' => true],
            
            'NCFCEA' => ['code' => '203', 'name' => 'Nota de Crédito FCE MiPyME A', 'category' => 'credit_note', 'requires_association' => true, 'compatible_with' => ['FCEA'], 'requires_mipyme' => true],
            'NCFCEB' => ['code' => '208', 'name' => 'Nota de Crédito FCE MiPyME B', 'category' => 'credit_note', 'requires_association' => true, 'compatible_with' => ['FCEB'], 'requires_mipyme' => true],
            'NCFCEC' => ['code' => '213', 'name' => 'Nota de Crédito FCE MiPyME C', 'category' => 'credit_note', 'requires_association' => true, 'compatible_with' => ['FCEC'], 'requires_mipyme' => true],
            
            'NDFCEA' => ['code' => '207', 'name' => 'Nota de Débito FCE MiPyME A', 'category' => 'debit_note', 'requires_association' => true, 'compatible_with' => ['FCEA'], 'requires_mipyme' => true],
            'NDFCEB' => ['code' => '212', 'name' => 'Nota de Débito FCE MiPyME B', 'category' => 'debit_note', 'requires_association' => true, 'compatible_with' => ['FCEB'], 'requires_mipyme' => true],
            'NDFCEC' => ['code' => '217', 'name' => 'Nota de Débito FCE MiPyME C', 'category' => 'debit_note', 'requires_association' => true, 'compatible_with' => ['FCEC'], 'requires_mipyme' => true],
            
            // REMITO ELECTRÓNICO
            'R' => ['code' => '995', 'name' => 'Remito Electrónico', 'category' => 'remito', 'requires_association' => false, 'has_amounts' => false],
            
            // BIENES USADOS
            'LBUA' => ['code' => '027', 'name' => 'Liquidación Bienes Usados A', 'category' => 'used_goods', 'requires_association' => false],
            'LBUB' => ['code' => '028', 'name' => 'Liquidación Bienes Usados B', 'category' => 'used_goods', 'requires_association' => false],
            'CBUCF' => ['code' => '030', 'name' => 'Comprobante Compra Bienes Usados', 'category' => 'used_goods_purchase', 'requires_association' => false, 'buyer_issues' => true],
        ];
    }

    public static function getAfipCode(string $type): string
    {
        $types = self::getVoucherTypes();
        return $types[$type]['code'] ?? '001';
    }

    public static function getTypeByCode(string $code): ?string
    {
        foreach (self::getVoucherTypes() as $key => $type) {
            if ($type['code'] === $code) {
                return $key;
            }
        }
        return null;
    }

    public static function requiresAssociation(string $type): bool
    {
        $types = self::getVoucherTypes();
        return $types[$type]['requires_association'] ?? false;
    }

    public static function isCompatibleWith(string $noteType, string $invoiceType): bool
    {
        $types = self::getVoucherTypes();
        $compatibleWith = $types[$noteType]['compatible_with'] ?? [];
        return in_array($invoiceType, $compatibleWith);
    }

    public static function getCategory(string $type): string
    {
        $types = self::getVoucherTypes();
        return $types[$type]['category'] ?? 'invoice';
    }

    public static function hasAmounts(string $type): bool
    {
        $types = self::getVoucherTypes();
        return $types[$type]['has_amounts'] ?? true;
    }

    public static function requiresMipyme(string $type): bool
    {
        $types = self::getVoucherTypes();
        return $types[$type]['requires_mipyme'] ?? false;
    }

    public static function getAvailableTypes(?bool $isMipyme = null): array
    {
        $types = self::getVoucherTypes();
        
        if ($isMipyme === false) {
            // Filtrar tipos que requieren MiPyME
            $types = array_filter($types, function($type) {
                return !($type['requires_mipyme'] ?? false);
            });
        }
        
        return $types;
    }
}
