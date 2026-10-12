<?php

namespace Database\Seeders;

use App\Domain\Access\Roles;
use App\Domain\Customers\CustomerService;
use App\Domain\Ledger\PvLedger;
use App\Domain\Network\NetworkService;
use App\Domain\Onboarding\IvdService;
use App\Domain\Orders\OrderService;
use App\Domain\Periods\PeriodService;
use App\Models\BundleRule;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\Product;
use App\Models\RankAchievement;
use App\Models\ShippingRate;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Seeder;

/** Dati dimostrativi per vedere il portale al lavoro. NON usare in produzione. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        Roles::sync();
        $this->call(PrelaunchPlanSeeder::class);
        $this->call(CatalogSeeder::class);
        $at = fn (string $d) => new DateTimeImmutable($d, new DateTimeZone('Europe/Rome'));

        User::updateOrCreate(['email' => 'admin@lamess.test'], ['name' => 'Laura (admin)', 'password' => 'password'])->syncRoles(['super_admin']);
        User::updateOrCreate(['email' => 'board@lamess.test'], ['name' => 'Direzione', 'password' => 'password'])->syncRoles(['board']);

        $net = app(NetworkService::class);
        $mk = function (string $code, string $name, ?Consultant $sp, ?string $rank) use ($net, $at) {
            $c = $net->enroll(['code' => $code, 'name' => $name, 'referral_code' => 'REF'.$code], $sp, $at('2026-09-01 10:00'));
            $c->update(['onboarding_complete' => true]);
            if ($rank) {
                RankAchievement::create(['consultant_id' => $c->id, 'rank_code' => $rank, 'achieved_at' => $at('2026-08-01 10:00'), 'effective_from' => '2026-09-01', 'source' => 'manual']);
            }

            return $c;
        };
        $a = $mk('LM00001', 'Anna Greco', null, 'DIAMOND');
        $b = $mk('LM00002', 'Bruno Romeo', $a, 'CRYSTAL');
        $c = $mk('LM00003', 'Carla Neri', $b, null);
        $d = $mk('LM00004', 'Dario Costa', $a, null);

        $period = app(PeriodService::class)->open('2026-10', CompPlanVersion::firstOrFail());
        foreach ([[$b, '8000', '2026-10-05 10:00'], [$c, '1500', '2026-10-08 11:00'], [$d, '600', '2026-10-09 09:30'], [$a, '300', '2026-10-12 16:00']] as [$who, $pv, $on]) {
            PvLedger::record($period, $who, 'pvf', $pv, $at($on));
        }
        app(PeriodService::class)->calculate($period);

        // Negozio demo (prodotti di fantasia: il catalogo reale si importa a parte)
        $box = Product::create(['sku' => 'DEMO-BOX', 'name' => 'Box Cosmetic (demo)', 'description' => 'Prodotto dimostrativo', 'net_price' => '123.00', 'vat_rate' => '22']);
        $mug = Product::create(['sku' => 'DEMO-TAZZA', 'name' => 'Tazza Levante (demo)', 'net_price' => '12.30', 'vat_rate' => '22', 'pv' => '0']);
        Product::create(['sku' => 'DEMO-PROMO', 'name' => 'Box Promo Rank — CV doppio (demo)', 'net_price' => '123.00', 'vat_rate' => '22', 'promo_rank_double_cv' => true]);
        BundleRule::create(['trigger_product_id' => $box->id, 'trigger_qty' => 5, 'gift_product_id' => $mug->id, 'gift_qty' => 1]);
        ShippingRate::create(['country_code' => 'IT', 'amount_net' => '6.90', 'vat_rate' => '22']);

        // Un FAN con domanda firmata (da accettare) e uno solo registrato
        $cust = app(CustomerService::class);
        $fan = $cust->register(['email' => 'mario@example.test', 'password' => 'password', 'type' => 'private', 'first_name' => 'Mario', 'last_name' => 'Rossi'], formCode: $a->code);
        $ivd = app(IvdService::class);
        $contract = $ivd->apply($fan->user, [
            'tax_code' => 'RSSMRA80A01H501U', 'birth_date' => '1980-01-01', 'birth_place' => 'Reggio Calabria',
            'address' => 'Via Roma 1', 'city' => 'Reggio Calabria', 'zip' => '89100', 'province' => 'RC',
        ], $a);
        $req = $ivd->requestSignature($contract);
        preg_match('/(\d{6})/', (string) $req->hint, $m);
        $ivd->sign($contract, $m[1], '127.0.0.1', 'demo');
        $cust->register(['email' => 'lucia@example.test', 'password' => 'password', 'type' => 'private', 'first_name' => 'Lucia', 'last_name' => 'Verdi'], formCode: $b->code);

        // Un ordine pagato con carta e uno in attesa di bonifico, del FAN Lucia (cliente di Bruno)
        $lucia = User::where('email', 'lucia@example.test')->firstOrFail();
        $billing = ['type' => 'private', 'first_name' => 'Lucia', 'last_name' => 'Verdi', 'tax_code' => 'VRDLCU85C41H224K', 'address' => 'Via Marina 10', 'city' => 'Reggio Calabria', 'zip' => '89100', 'province' => 'RC'];
        $orders = app(OrderService::class);
        $o1 = $orders->place($lucia, [$box->id => 5], 'IT', 'card', $billing);
        $orders->payByCard($o1);
        $orders->place($lucia, [$box->id => 1], 'IT', 'transfer', $billing);
    }
}
