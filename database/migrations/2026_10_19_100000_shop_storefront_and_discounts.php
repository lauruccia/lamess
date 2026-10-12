<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Negozio completo: categorie, immagini, prezzo di listino barrato, giacenza, sconti (codici, automatici, scaglioni).
 * Solo aggiunte: gli ordini e i prodotti esistenti continuano a funzionare identici.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->text('description')->nullable();
            $t->string('image_path')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::table('products', function (Blueprint $t) {
            $t->string('slug')->nullable();
            $t->text('details')->nullable();                       // descrizione estesa (Markdown)
            $t->decimal('compare_at_net', 15, 8)->nullable();      // prezzo di listino barrato (netto), solo se > prezzo
            $t->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $t->json('images')->nullable();                        // percorsi relativi al disco locale; il primo è la copertina
            $t->boolean('featured')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->string('badge', 30)->nullable();                   // etichetta libera: «Novità», «Bestseller»…
            $t->integer('stock_qty')->nullable();                  // null = giacenza non gestita
            $t->boolean('allow_backorder')->default(false);        // vendi anche a giacenza esaurita
            $t->string('seo_title')->nullable();
            $t->string('seo_description', 300)->nullable();
        });

        // Slug per i prodotti già presenti, poi unicità.
        $used = [];
        foreach (DB::table('products')->orderBy('id')->get(['id', 'name', 'sku']) as $p) {
            $base = Str::slug((string) $p->name) ?: Str::slug((string) $p->sku) ?: 'prodotto';
            $slug = $base;
            for ($i = 2; isset($used[$slug]); $i++) {
                $slug = $base.'-'.$i;
            }
            $used[$slug] = true;
            DB::table('products')->where('id', $p->id)->update(['slug' => $slug]);
        }
        Schema::table('products', fn (Blueprint $t) => $t->unique('slug'));

        Schema::create('discounts', function (Blueprint $t) {
            $t->id();
            $t->string('name');                                    // titolo interno
            $t->string('code', 40)->nullable()->unique();          // null = sconto automatico
            $t->string('type', 16);                                // percent | fixed | tiered | free_shipping
            $t->decimal('value', 15, 2)->default(0);               // % oppure € IVA inclusa
            $t->json('tiers')->nullable();                         // [{min_qty, percent}] per type=tiered
            $t->string('applies_to', 12)->default('all');          // all | products | categories
            $t->json('product_ids')->nullable();
            $t->json('category_ids')->nullable();
            $t->decimal('min_subtotal', 15, 2)->nullable();        // importo minimo (IVA inclusa) dei prodotti interessati
            $t->unsignedInteger('min_qty')->nullable();            // quantità minima dei prodotti interessati
            $t->string('audience', 12)->default('all');            // all | customers | consultants | first_order
            $t->boolean('combinable')->default(false);
            $t->unsignedInteger('usage_limit')->nullable();
            $t->unsignedInteger('usage_limit_per_user')->nullable();
            $t->dateTime('starts_at')->nullable();
            $t->dateTime('ends_at')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('discount_net', 15, 2)->default(0)->after('shipping_waived');         // sconto sulle righe, al netto IVA
            $t->decimal('shipping_discount_net', 15, 2)->default(0)->after('discount_net');   // spedizione abbuonata da uno sconto
            $t->json('discounts')->nullable()->after('shipping_discount_net');                // istantanea degli sconti applicati
        });

        Schema::table('order_items', function (Blueprint $t) {
            $t->decimal('list_unit_net', 15, 8)->nullable();       // prezzo di listino prima degli sconti
        });

        Schema::create('discount_redemptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('discount_id')->constrained('discounts')->cascadeOnDelete();
            $t->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->decimal('amount_net', 15, 2);
            $t->timestamps();
            $t->unique(['discount_id', 'order_id']);
            $t->index(['discount_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_redemptions');
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('list_unit_net'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['discount_net', 'shipping_discount_net', 'discounts']));
        Schema::dropIfExists('discounts');
        Schema::table('products', function (Blueprint $t) {
            $t->dropUnique(['slug']);
            $t->dropConstrainedForeignId('category_id');
            $t->dropColumn(['slug', 'details', 'compare_at_net', 'images', 'featured', 'sort_order', 'badge', 'stock_qty', 'allow_backorder', 'seo_title', 'seo_description']);
        });
        Schema::dropIfExists('product_categories');
    }
};
