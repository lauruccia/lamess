<?php

declare(strict_types=1);

namespace Tests\Feature\I18n;

use App\Domain\Access\Roles;
use App\Domain\I18n\Drivers\FakeTranslator;
use App\Domain\I18n\Locales;
use App\Domain\I18n\StringScanner;
use App\Domain\I18n\TranslationException;
use App\Domain\I18n\TranslationExchange;
use App\Domain\I18n\TranslationService;
use App\Domain\I18n\TranslationSync;
use App\Domain\Settings\SystemSettings;
use App\Filament\Resources\Languages\LanguageResource;
use App\Filament\Resources\Languages\Pages\CreateLanguage;
use App\Filament\Resources\Languages\Pages\ListLanguages;
use App\Filament\Resources\TranslationStrings\Pages\ListTranslationStrings;
use App\Models\Language;
use App\Models\TranslationString;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class TranslationAdminTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return $this->actingAs(User::factory()->role('super_admin')->create())->app['auth']->user();
    }

    private function fakeScan(array $sources): void
    {
        $this->app->instance(StringScanner::class, new class($sources) extends StringScanner
        {
            public function __construct(private array $sources) {}

            public function scan(): array
            {
                return array_map(fn () => ['resources/views/x.blade.php'], array_flip($this->sources));
            }
        });
    }

    public function test_only_the_superadmin_opens_languages_and_translations(): void
    {
        foreach (['/admin/lingue', '/admin/traduzioni'] as $url) {
            $this->actingAs(User::factory()->role('customer')->create())->get($url)->assertForbidden();
            foreach (['admin_operations', 'admin_support'] as $profile) {
                if (array_key_exists($profile, Roles::MATRIX)) {
                    $this->actingAs(User::factory()->role($profile)->create())->get($url)->assertForbidden();
                }
            }
        }
        $this->superadmin();
        $this->get('/admin/lingue')->assertOk()->assertSee('Nederlands')->assertSee('Español');
        $this->get('/admin/traduzioni')->assertOk();
    }

    public function test_the_scanner_reads_double_and_single_quoted_texts_and_skips_group_keys(): void
    {
        $code = <<<'BLADE'
            <p>{{ __('Ciao :nome, benvenuto', ['nome' => $n]) }}</p>
            <a title="{{ __("Vai all'ordine") }}">@lang('Apri')</a>
            {{ __('L\'ordine è partito') }} {{ __('auth.failed') }} {{ $x->__('altro') }}
            BLADE;
        $found = (new StringScanner)->stringsIn($code);

        $this->assertEqualsCanonicalizing(['Ciao :nome, benvenuto', "Vai all'ordine", 'Apri', "L'ordine è partito"], $found);
    }

    public function test_the_scan_creates_a_row_per_language_imports_the_files_and_marks_orphans(): void
    {
        $this->fakeScan(['Ciao', 'Spedizione gratuita']);
        @mkdir(lang_path(), 0777, true);
        // il file inglese vero (consegnato con il codice) va rimesso a posto a fine prova
        $real = is_file(lang_path('en.json')) ? file_get_contents(lang_path('en.json')) : null;
        file_put_contents(lang_path('en.json'), json_encode(['Ciao' => 'Hello']));

        try {
            $r = app(TranslationSync::class)->run();
        } finally {
            $real === null ? @unlink(lang_path('en.json')) : file_put_contents(lang_path('en.json'), $real);
        }

        $this->assertSame(2, $r['found']);
        $this->assertSame(12, TranslationString::count()); // 2 testi × 6 lingue
        $en = TranslationString::where('locale', 'en')->where('source', 'Ciao')->first();
        $this->assertSame('Hello', $en->text);
        $this->assertSame(TranslationString::AUTO, $en->status);
        $this->assertSame(TranslationString::MISSING, TranslationString::where('locale', 'de')->where('source', 'Ciao')->value('status'));

        // un testo sparito dal sito diventa orfano, uno già modificato a mano non si tocca
        $en->forceFill(['text' => 'Hi there', 'status' => TranslationString::APPROVED])->save();
        $this->fakeScan(['Ciao']);
        $r = app(TranslationSync::class)->run();
        $this->assertSame(6, $r['orphaned']);
        $this->assertSame('Hi there', $en->fresh()->text);
        $this->assertTrue(TranslationString::where('source', 'Spedizione gratuita')->first()->orphan);
    }

    public function test_a_new_language_is_created_from_the_admin_with_all_the_texts_ready_to_translate(): void
    {
        $this->fakeScan(['Ciao', 'Grazie']);
        app(TranslationSync::class)->run();
        $this->superadmin();

        Livewire::test(CreateLanguage::class)
            ->fillForm(['code' => 'pt', 'name' => 'Portoghese', 'native_name' => 'Português', 'flag' => 'ro', 'status' => 'draft', 'sort' => 7])
            ->call('create')->assertHasNoFormErrors();

        $this->assertDatabaseHas('languages', ['code' => 'pt', 'is_default' => false, 'status' => 'draft']);
        $this->assertSame(2, TranslationString::where('locale', 'pt')->count());
        $this->assertContains('pt', Locales::allCodes());

        Livewire::test(CreateLanguage::class)->fillForm(['code' => 'it', 'name' => 'x', 'native_name' => 'x'])->call('create')->assertHasFormErrors(['code']);
        Livewire::test(CreateLanguage::class)->fillForm(['code' => 'admin', 'name' => 'x', 'native_name' => 'x'])->call('create')->assertHasFormErrors(['code']);
    }

    public function test_the_default_language_cannot_be_deleted_or_switched_off(): void
    {
        $this->superadmin();
        $it = Language::where('code', 'it')->first();
        $this->assertFalse(LanguageResource::canDelete($it));
        $this->assertTrue(LanguageResource::canDelete(Language::where('code', 'nl')->first()));

        Livewire::test(ListLanguages::class)->assertCanSeeTableRecords(Language::all());
    }

    public function test_the_superadmin_edits_an_italian_and_a_translated_text_from_the_table(): void
    {
        $this->fakeScan(['Spedizione gratuita']);
        app(TranslationSync::class)->run();
        $this->superadmin();
        $en = TranslationString::where('locale', 'en')->first();
        $it = TranslationString::where('locale', 'it')->first();

        Livewire::test(ListTranslationStrings::class)
            ->filterTable('locale', 'en')
            ->callTableAction('edit', $en, data: ['text' => 'Free shipping', 'approve' => true])->assertHasNoTableActionErrors();
        $this->assertSame('Free shipping', $en->fresh()->text);
        $this->assertSame(TranslationString::APPROVED, $en->fresh()->status);

        Livewire::test(ListTranslationStrings::class)->filterTable('locale', 'it')
            ->callTableAction('edit', $it, data: ['text' => 'Spedizione sempre gratis'])->assertHasNoTableActionErrors();
        app()->setLocale('it');
        app('translator')->setLoaded([]);
        $this->assertSame('Spedizione sempre gratis', __('Spedizione gratuita'));
    }

    public function test_a_translation_that_loses_a_placeholder_is_refused(): void
    {
        $this->fakeScan(['Ciao :nome, il tuo ordine <b>:numero</b> è partito']);
        app(TranslationSync::class)->run();
        $this->superadmin();
        $en = TranslationString::where('locale', 'en')->first();

        Livewire::test(ListTranslationStrings::class)->filterTable('locale', 'en')
            ->callTableAction('edit', $en, data: ['text' => 'Hello, your order has shipped'])->assertHasTableActionErrors(['text']);
        $this->assertNull($en->fresh()->text);

        Livewire::test(ListTranslationStrings::class)->filterTable('locale', 'en')
            ->callTableAction('edit', $en, data: ['text' => 'Hello :nome, your order <b>:numero</b> has shipped'])->assertHasNoTableActionErrors();
        $this->assertSame(TranslationString::APPROVED, $en->fresh()->status);
    }

    public function test_bulk_approve_only_touches_translated_rows(): void
    {
        $this->fakeScan(['Uno', 'Due']);
        app(TranslationSync::class)->run();
        $this->superadmin();
        $uno = TranslationString::where('locale', 'en')->where('source', 'Uno')->first();
        $due = TranslationString::where('locale', 'en')->where('source', 'Due')->first();
        $uno->forceFill(['text' => 'One', 'status' => TranslationString::AUTO])->save();

        Livewire::test(ListTranslationStrings::class)->filterTable('locale', 'en')
            ->callTableBulkAction('approve', [$uno, $due]);

        $this->assertSame(TranslationString::APPROVED, $uno->fresh()->status);
        $this->assertSame(TranslationString::MISSING, $due->fresh()->status);
    }

    public function test_the_scan_button_and_the_first_visit_fill_the_table(): void
    {
        $this->fakeScan(['Ciao']);
        $this->superadmin();

        Livewire::test(ListTranslationStrings::class);
        $this->assertSame(6, TranslationString::count()); // prima visita: allineamento automatico

        $this->fakeScan(['Ciao', 'Nuovo']);
        Livewire::test(ListTranslationStrings::class)->callAction('scan');
        $this->assertSame(12, TranslationString::count());
    }

    public function test_machine_translation_protects_placeholders_tags_and_brand_names(): void
    {
        $svc = new TranslationService(new FakeTranslator);
        [$masked, $items] = $svc->mask('Ciao :nome, scopri <b>Lamèss</b> &egrave; 100% %s');

        $this->assertStringNotContainsString(':nome', $masked);
        $this->assertStringNotContainsString('Lamèss', $masked);
        $this->assertSame(':nome', $items[0]);
        $this->assertSame('Ciao :nome, scopri <b>Lamèss</b> &egrave; 100% %s', $svc->unmask($masked, $items));
        // una traduzione che perde un'etichetta non vale
        $this->assertNull($svc->unmask('Hello, discover', $items));
    }

    public function test_the_automatic_translation_fills_only_the_missing_rows_and_leaves_them_to_review(): void
    {
        config(['i18n.driver' => 'fake']);
        $this->fakeScan(['Ciao :nome', 'Grazie']);
        app(TranslationSync::class)->run();
        TranslationString::where('locale', 'en')->where('source', 'Grazie')->update(['text' => 'Thanks', 'status' => TranslationString::APPROVED]);

        $r = (new TranslationService)->translate('en');

        $this->assertSame(1, $r['translated']);
        $row = TranslationString::where('locale', 'en')->where('source', 'Ciao :nome')->first();
        $this->assertSame('[en] Ciao :nome', $row->text);
        $this->assertSame(TranslationString::AUTO, $row->status);
        $this->assertSame('Thanks', TranslationString::where('locale', 'en')->where('source', 'Grazie')->value('text'));
        $this->expectException(TranslationException::class);
        (new TranslationService)->translate('it');
    }

    public function test_deepl_driver_sends_the_protected_texts_and_reads_the_answer(): void
    {
        config(['i18n.driver' => 'deepl', 'i18n.deepl.key' => 'abc:fx', 'i18n.deepl.url' => 'https://api-free.deepl.com/v2/translate']);
        $this->fakeScan(['Ciao :nome']);
        app(TranslationSync::class)->run();
        Http::fake(['api-free.deepl.com/*' => Http::response(['translations' => [['text' => 'Hello <x id="1"/>']]])]);

        $r = (new TranslationService)->translate('en');

        $this->assertSame(1, $r['translated']);
        $this->assertSame('Hello :nome', TranslationString::where('locale', 'en')->value('text'));
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'DeepL-Auth-Key abc:fx')
            && $req['target_lang'] === 'EN-GB' && $req['source_lang'] === 'IT' && $req['text'] === ['Ciao <x id="1"/>']);
    }

    public function test_a_failing_service_reports_the_error_and_saves_nothing(): void
    {
        config(['i18n.driver' => 'google', 'i18n.google.key' => 'k']);
        $this->fakeScan(['Ciao']);
        app(TranslationSync::class)->run();
        Http::fake(['translation.googleapis.com/*' => Http::response('no', 500)]);

        $r = (new TranslationService)->translate('de');

        $this->assertSame(0, $r['translated']);
        $this->assertNotNull($r['error']);
        $this->assertNull(TranslationString::where('locale', 'de')->value('text'));
    }

    public function test_without_a_configured_service_the_fake_driver_is_refused_in_production(): void
    {
        config(['i18n.driver' => 'fake']);
        $this->app['env'] = 'production';
        $this->expectException(TranslationException::class);

        (new TranslationService)->translate('en');
    }

    public function test_the_translator_exports_and_reimports_a_csv_per_language(): void
    {
        $this->fakeScan(['Ciao :nome', 'Grazie, a presto']);
        app(TranslationSync::class)->run();
        $ex = new TranslationExchange;

        $csv = $ex->export('fr');
        $this->assertStringStartsWith("\xEF\xBB\xBF\"Testo italiano\";Traduzione;Stato", $csv);
        $this->assertStringContainsString('"Grazie, a presto";;missing', $csv);

        $file = tempnam(sys_get_temp_dir(), 'tr');
        file_put_contents($file, "\xEF\xBB\xBFTesto italiano;Traduzione;Stato\nCiao :nome;Bonjour :nome;\nGrazie, a presto;;\nSconosciuto;Inconnu;\n");
        $r = $ex->import('fr', $file);
        @unlink($file);

        $this->assertSame(['updated' => 1, 'unknown' => 1, 'empty' => 1], $r);
        $row = TranslationString::where('locale', 'fr')->where('source', 'Ciao :nome')->first();
        $this->assertSame('Bonjour :nome', $row->text);
        $this->assertSame(TranslationString::APPROVED, $row->status);
    }

    public function test_the_unreviewed_switch_lives_in_the_system_settings(): void
    {
        $this->assertArrayHasKey('i18n_show_unreviewed', SystemSettings::DEFINITIONS);
        $this->superadmin();
        SystemSettings::update(['i18n_show_unreviewed' => false], 'prova');

        $this->assertFalse(config('i18n.show_unreviewed'));
    }
}
