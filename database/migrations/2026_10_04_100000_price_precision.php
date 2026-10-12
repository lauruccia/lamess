<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prezzi unitari con 8 decimali: un listino IVA inclusa (es. 159,90 €) non è un numero tondo al netto.
 * Come nel modello di fattura di riferimento (PrezzoUnitario a 8 decimali), il netto resta esatto e si
 * arrotonda una volta sola sul totale per aliquota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->decimal('net_price', 15, 8)->change());
        foreach (['order_items', 'invoice_lines', 'credit_note_lines'] as $tbl) {
            Schema::table($tbl, fn (Blueprint $t) => $t->decimal('unit_net', 15, 8)->change());
        }
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->decimal('net_price', 15, 2)->change());
        foreach (['order_items', 'invoice_lines', 'credit_note_lines'] as $tbl) {
            Schema::table($tbl, fn (Blueprint $t) => $t->decimal('unit_net', 15, 2)->change());
        }
    }
};
