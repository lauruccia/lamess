<p class="muted" style="font-size:13px">Testo del modello di Lettera di Incarico (versione {{ app(\App\Domain\Onboarding\IvdLetterTemplate::class)->versionLabel() }}). La lettera personalizzata con i tuoi dati si genera e si firma da <a href="/area/diventa-incaricato">Diventa Incaricato</a>.</p>
<div class="legal-body">
@include('legal.ivd-letter-body')
</div>
<p class="muted" style="font-size:13px">Allegati: <a href="/regolamento">Principi generali e Regolamento</a> · <a href="/piano-compensi">Piano compensi</a> · <a href="/privacy">Informativa privacy</a> · <a href="/liberatoria-foto">Liberatoria foto e video</a> (facoltativa).</p>
