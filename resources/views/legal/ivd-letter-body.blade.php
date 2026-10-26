{{-- Testo della Lettera di Incarico IVD: viene dal back-office (Rete → Lettera di incarico) o, se nessuno l'ha modificato, da resources/legal/ivd-letter-default.html. Lo usano la pagina pubblica, la schermata di firma e i PDF firmati. --}}
{!! app(\App\Domain\Onboarding\IvdLetterTemplate::class)->render($decl ?? [], $feeEnabled ?? null, $feeAmount ?? null, $letterVersion ?? null) !!}
