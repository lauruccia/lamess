<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

/**
 * Dichiarazioni e accettazioni che accompagnano la firma della Lettera di Incarico IVD (blocco finale della lettera
 * «Definitiva» Kadeo Group + liberatoria foto/video). Le mostrano la schermata di firma, il PDF firmato e il certificato.
 */
final class IvdAcceptances
{
    /** Articoli della Lettera da approvare espressamente (artt. 1341-1342 c.c.). */
    public const LETTER_1341 = '2. (Durata); 2.1. (Rinuncia e recesso); 3.2. (Utilizzo del sito Web di www.kadeogroup.it); 3.3. (Manutenzione sistemi); 3.4. (Nome e marchi); 9. (Termine decadenziale per comunicare anomalie nei documenti contabili); 14.1. (Divieto di cessione); 14.2. (Modifiche); 15. (Comunicazioni) e 16. (Foro competente).';

    /** Articoli dei «Principi generali e Regolamento» da approvare espressamente (artt. 1341-1342 c.c.). */
    public const REGOLAMENTO_1341 = '2.4. (Rinnovo e quota annuale); 2.7. (Accettazione o rifiuto della Lettera di Incarico IVD); 2.8. (Effetti della cessazione del rapporto); 2.9. (Obblighi degli Incaricati); 5.1. (Fatture e ricevute Incaricato); 5.2. (Estratto conto); 6.1. (Pagamento commissioni di vendita); 7. (Violazione. Procedure e sanzioni); 8. (Provvedimenti).';

    /** Versione dei Principi generali e Regolamento (file resources/legal/regolamento-v2.0.md). */
    public const REGOLAMENTO_VERSION = '2.0';

    /** Versione del testo della liberatoria foto/video (vista site/legal/liberatoria-foto). */
    public const PHOTO_RELEASE_VERSION = '2026-1';

    /**
     * Caselle da spuntare prima della firma. «required»: senza non si firma. La liberatoria foto è facoltativa: un consenso
     * non può essere condizione per diventare Incaricato (GDPR, consenso libero e specifico).
     *
     * @return array<string,array{label:string,required:bool,link:?string,link_text:?string,detail:?string}>
     */
    public static function items(): array
    {
        return [
            'letter' => ['label' => 'Dichiaro di aver letto ed accettato la LETTERA DI INCARICO IVD.', 'required' => true, 'link' => '/lettera-di-incarico', 'link_text' => 'Leggi la lettera', 'detail' => null],
            'letter_1341' => ['label' => 'Dichiaro di approvare espressamente, ai sensi e per gli effetti degli Artt. 1341 e 1342 del codice civile, i seguenti articoli della LETTERA DI INCARICO KADEO:', 'required' => true, 'link' => null, 'link_text' => null, 'detail' => self::LETTER_1341],
            'regolamento' => ['label' => 'Dichiaro di aver letto ed accettato i «PRINCIPI GENERALI E REGOLAMENTO» (Allegato 1).', 'required' => true, 'link' => '/regolamento', 'link_text' => 'Leggi il Regolamento', 'detail' => null],
            'regolamento_1341' => ['label' => 'Dichiaro di approvare espressamente, ai sensi e per gli effetti degli Artt. 1341 e 1342 del codice civile, i seguenti articoli dei «PRINCIPI GENERALI E REGOLAMENTO»:', 'required' => true, 'link' => null, 'link_text' => null, 'detail' => self::REGOLAMENTO_1341],
            'piano' => ['label' => 'Dichiaro di aver letto ed accettato il PIANO COMPENSI (Allegato 2).', 'required' => true, 'link' => '/piano-compensi', 'link_text' => 'Leggi il Piano compensi', 'detail' => null],
            'privacy' => ['label' => 'Con riferimento ai dati personali conferiti al Consorzio Kadeo Group, dichiaro di aver letto, compreso ed accettato l\'Informativa ai sensi dell\'Art. 13 GDPR (Allegato 3).', 'required' => true, 'link' => '/privacy', 'link_text' => 'Leggi l\'informativa', 'detail' => null],
            'photo' => ['label' => 'Facoltativo — Autorizzo, a titolo gratuito, l\'uso di fotografie e/o registrazioni audio/video che mi ritraggono, secondo la liberatoria «Photo Release».', 'required' => false, 'link' => '/liberatoria-foto', 'link_text' => 'Leggi la liberatoria', 'detail' => null],
        ];
    }

    /** @return list<string> */
    public static function requiredKeys(): array
    {
        return array_keys(array_filter(self::items(), fn (array $i): bool => $i['required']));
    }

    /**
     * Prova delle accettazioni da conservare nelle evidenze del contratto.
     *
     * @param  array<string,bool>  $accepted  chiave → spuntata
     * @return array<string,mixed>
     */
    public static function evidence(array $accepted, ?string $planName, \DateTimeInterface $at): array
    {
        return [
            'items' => collect(self::items())->map(fn (array $i, string $k): bool => (bool) ($accepted[$k] ?? false))->all(),
            'accepted_at' => $at->format(DATE_ATOM),
            'documents' => [
                'letter' => app(IvdLetterTemplate::class)->versionLabel(),
                'regolamento' => self::REGOLAMENTO_VERSION,
                'piano_compensi' => $planName,
                'photo_release' => self::PHOTO_RELEASE_VERSION,
            ],
        ];
    }
}
