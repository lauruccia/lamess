<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('sku')->unique();
            $t->string('name');
            $t->text('description')->nullable();
            $t->decimal('net_price', 15, 2);
            $t->decimal('vat_rate', 5, 2)->default(22);
            $t->decimal('pv', 15, 2)->nullable();   // null = calcolato (imponibile / euro-per-PV)
            $t->decimal('cv', 15, 2)->nullable();   // null = PV (x2 se promo)
            $t->boolean('promo_rank_double_cv')->default(false);
            $t->boolean('is_kit')->default(false);
            $t->boolean('active')->default(true);
            $t->json('countries')->nullable();      // null = tutti i paesi abilitati
            $t->timestamps();
        });
        Schema::create('kit_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('kit_product_id')->constrained('products')->cascadeOnDelete();
            $t->foreignId('component_product_id')->constrained('products');
            $t->unsignedInteger('qty')->default(1);
        });
        Schema::create('bundle_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trigger_product_id')->constrained('products')->cascadeOnDelete();
            $t->unsignedInteger('trigger_qty');
            $t->foreignId('gift_product_id')->constrained('products');
            $t->unsignedInteger('gift_qty')->default(1);
            $t->boolean('repeatable')->default(true);
            $t->date('active_from')->nullable();
            $t->date('active_to')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('shipping_rates', function (Blueprint $t) {
            $t->id();
            $t->string('country_code', 2)->unique();
            $t->decimal('amount_net', 15, 2);
            $t->decimal('vat_rate', 5, 2)->default(22);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('buyer_user_id')->constrained('users');
            $t->string('buyer_type');                // customer | consultant
            $t->foreignId('beneficiary_consultant_id')->nullable()->constrained('consultants');
            $t->string('status')->default('pending_payment'); // pending_payment | paid | shipped | cancelled | partially_refunded | refunded
            $t->string('country_code', 2)->default('IT');
            $t->string('payment_method');            // card | transfer
            $t->decimal('subtotal_net', 15, 2);
            $t->decimal('shipping_net', 15, 2)->default(0);
            $t->decimal('shipping_vat_rate', 5, 2)->default(22);
            $t->decimal('vat_total', 15, 2);
            $t->decimal('total', 15, 2);
            $t->decimal('pv_total', 15, 2)->default(0);
            $t->decimal('cv_total', 15, 2)->default(0);
            $t->dateTime('paid_at')->nullable();
            $t->dateTime('shipped_at')->nullable();
            $t->string('tracking_code')->nullable();
            $t->json('billing');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['buyer_user_id', 'status']);
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products');
            $t->string('sku');
            $t->string('name');
            $t->unsignedInteger('qty');
            $t->unsignedInteger('qty_refunded')->default(0);
            $t->decimal('unit_net', 15, 2);
            $t->decimal('vat_rate', 5, 2);
            $t->decimal('pv_unit_frozen', 15, 2)->default(0);
            $t->decimal('cv_unit_frozen', 15, 2)->default(0);
            $t->boolean('is_gift')->default(false);
            $t->foreignId('parent_item_id')->nullable()->constrained('order_items')->nullOnDelete();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('provider');
            $t->string('provider_ref')->nullable();
            $t->string('method');
            $t->decimal('amount', 15, 2);
            $t->string('status')->default('pending'); // pending | paid | failed
            $t->dateTime('paid_at')->nullable();
            $t->dateTime('reconciled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('document_counters', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->unsignedBigInteger('value')->default(0);
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained();
            $t->string('number')->unique();
            $t->unsignedSmallInteger('year');
            $t->string('type')->default('invoice');  // invoice | receipt
            $t->dateTime('issued_at');
            $t->json('buyer');
            $t->decimal('net', 15, 2);
            $t->decimal('vat', 15, 2);
            $t->decimal('total', 15, 2);
            $t->string('sink_status')->default('pending'); // pending | sent | error
            $t->string('sink_ref')->nullable();
            $t->text('sink_error')->nullable();
            $t->timestamps();
        });
        Schema::create('invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $t->string('description');
            $t->unsignedInteger('qty');
            $t->decimal('unit_net', 15, 2);
            $t->decimal('vat_rate', 5, 2);
            $t->decimal('net', 15, 2);
            $t->decimal('vat', 15, 2);
            $t->decimal('total', 15, 2);
        });
        Schema::create('credit_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained();
            $t->foreignId('order_id')->constrained();
            $t->string('number')->unique();
            $t->unsignedSmallInteger('year');
            $t->dateTime('issued_at');
            $t->string('reason');
            $t->decimal('net', 15, 2);
            $t->decimal('vat', 15, 2);
            $t->decimal('total', 15, 2);
            $t->string('sink_status')->default('pending');
            $t->string('sink_ref')->nullable();
            $t->text('sink_error')->nullable();
            $t->timestamps();
        });
        Schema::create('credit_note_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('credit_note_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $t->string('description');
            $t->unsignedInteger('qty');
            $t->decimal('unit_net', 15, 2);
            $t->decimal('vat_rate', 5, 2);
            $t->decimal('net', 15, 2);
            $t->decimal('vat', 15, 2);
            $t->decimal('total', 15, 2);
        });
        Schema::table('pv_movements', function (Blueprint $t) {
            $t->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $t->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $t->foreignId('credit_note_id')->nullable()->constrained('credit_notes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pv_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('credit_note_id');
            $t->dropConstrainedForeignId('order_item_id');
            $t->dropConstrainedForeignId('order_id');
        });
        foreach (['credit_note_lines', 'credit_notes', 'invoice_lines', 'invoices', 'document_counters', 'payments', 'order_items', 'orders', 'shipping_rates', 'bundle_rules', 'kit_components', 'products'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
