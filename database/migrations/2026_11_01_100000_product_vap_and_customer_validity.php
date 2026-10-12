<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V.A.P. e validità cliente per prodotto.
 *  - products: V.A.P. come % del prezzo IVA inclusa oppure importo fisso per pezzo (vuoto = quota V.A.P. del piano);
 *    mesi di validità del cliente per l'incaricato (vuoto = 1 mese).
 *  - order_items: valori congelati al momento dell'ordine (le modifiche al prodotto non toccano gli ordini esistenti).
 *  - pv_movements: V.A.P. in euro e mesi di validità del movimento (vuoto = movimento storico: vale il piano e 1 mese).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->string('vap_mode', 10)->nullable();
            $t->decimal('vap_value', 15, 4)->nullable();
            $t->unsignedSmallInteger('customer_validity_months')->nullable();
        });
        Schema::table('order_items', function (Blueprint $t): void {
            $t->string('vap_mode', 10)->nullable();
            $t->decimal('vap_value', 15, 4)->nullable();
            $t->unsignedSmallInteger('validity_months')->nullable();
        });
        Schema::table('pv_movements', function (Blueprint $t): void {
            $t->decimal('vap_amount', 15, 2)->nullable();
            $t->unsignedSmallInteger('validity_months')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pv_movements', fn (Blueprint $t) => $t->dropColumn(['vap_amount', 'validity_months']));
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn(['vap_mode', 'vap_value', 'validity_months']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['vap_mode', 'vap_value', 'customer_validity_months']));
    }
};
