<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // En MySQL no se puede modificar ENUM directamente, hay que recrear la columna
        DB::statement("ALTER TABLE invoices MODIFY COLUMN type ENUM(
            'A', 'B', 'C', 'M', 'E',
            'NCA', 'NCB', 'NCC', 'NCM', 'NCE',
            'NDA', 'NDB', 'NDC', 'NDM', 'NDE',
            'RA', 'RB', 'RC', 'RM',
            'FCEA', 'FCEB', 'FCEC',
            'NCFCEA', 'NCFCEB', 'NCFCEC',
            'NDFCEA', 'NDFCEB', 'NDFCEC',
            'R',
            'LBUA', 'LBUB', 'CBUCF'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE invoices MODIFY COLUMN type ENUM('A', 'B', 'C', 'E', 'otro') NOT NULL");
    }
};
