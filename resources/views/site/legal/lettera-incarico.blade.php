<p class="muted" style="font-size:13px">{!! __('Testo del modello di Lettera di Incarico (versione :p). La lettera personalizzata con i tuoi dati si genera e si firma da <a href="/area/diventa-incaricato">Diventa Incaricato</a>.', ['p' => e(app(\App\Domain\Onboarding\IvdLetterTemplate::class)->versionLabel())]) !!}</p>
<div class="legal-body">
@include('legal.ivd-letter-body')
</div>
<p class="muted" style="font-size:13px">{{ __('Allegati:') }} <a href="/regolamento">{{ __('Principi generali e Regolamento') }}</a> · <a href="/piano-compensi">{{ __('Piano compensi') }}</a> · <a href="/privacy">{{ __('Informativa privacy') }}</a> · <a href="/liberatoria-foto">{{ __('Liberatoria foto e video') }}</a> {{ __('(facoltativa).') }}</p>
