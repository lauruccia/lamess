<?php

declare(strict_types=1);

namespace App\Domain\Content;

use setasign\Fpdi\Fpdi;

/**
 * Filigrana nominativa: «Nome · codice · data» ripetuta in diagonale su immagini (GD) e PDF (FPDI).
 * Per i PDF con struttura compressa (PDF 1.5+) la libreria gratuita può non riuscire a leggerli:
 * in quel caso lancia ContentException e chi chiama decide cosa fare (vedi DownloadService).
 */
final class Watermarker
{
    public static function supports(string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true);
    }

    /** @return array{0: string, 1: string} [contenuto, mime] */
    public function apply(string $path, string $mime, string $label): array
    {
        return match ($mime) {
            'application/pdf' => [$this->pdf($path, $label), $mime],
            'image/jpeg', 'image/png' => [$this->image($path, $mime, $label), $mime],
            default => throw new ContentException('Formato non supportato per la filigrana.'),
        };
    }

    private function latin(string $s): string
    {
        return (string) (iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s) ?: $s);
    }

    private function image(string $path, string $mime, string $label): string
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new ContentException('Estensione GD non disponibile.');
        }
        $raw = @file_get_contents($path);
        $im = $raw === false ? false : @imagecreatefromstring($raw);
        if ($im === false) {
            throw new ContentException('Immagine non leggibile.');
        }
        imagealphablending($im, true);
        $w = imagesx($im);
        $h = imagesy($im);
        $font = 5;
        $text = $this->latin($label);
        $tw = imagefontwidth($font) * strlen($text);
        $th = imagefontheight($font);
        // un riquadro con il testo, ripetuto a griglia sull'intera immagine
        $tile = imagecreatetruecolor($tw + 40, $th + 30);
        imagesavealpha($tile, true);
        imagealphablending($tile, false);
        imagefill($tile, 0, 0, imagecolorallocatealpha($tile, 0, 0, 0, 127));
        imagealphablending($tile, true);
        imagestring($tile, $font, 20, 15, $text, imagecolorallocatealpha($tile, 90, 90, 90, 80));
        $angled = imagerotate($tile, 25, imagecolorallocatealpha($tile, 0, 0, 0, 127));
        imagesavealpha($angled, true);
        $aw = imagesx($angled);
        $ah = imagesy($angled);
        for ($y = 0; $y < $h; $y += $ah + 60) {
            for ($x = (($y / ($ah + 60)) % 2) * (int) ($aw / 2); $x < $w; $x += $aw + 60) {
                imagecopy($im, $angled, (int) $x, $y, 0, 0, $aw, $ah);
            }
        }
        ob_start();
        $mime === 'image/png' ? imagepng($im) : imagejpeg($im, null, 90);
        $out = (string) ob_get_clean();
        unset($im, $tile, $angled);

        return $out;
    }

    private function pdf(string $path, string $label): string
    {
        $pdf = new class extends Fpdi
        {
            public function diagonalText(float $x, float $y, string $txt, float $angle): void
            {
                $c = cos(deg2rad($angle));
                $s = sin(deg2rad($angle));
                $k = $this->k;
                $cx = $x * $k;
                $cy = ($this->h - $y) * $k;
                $this->_out(sprintf('q %.5F %.5F %.5F %.5F %.2F %.2F cm', $c, $s, -$s, $c, $cx, $cy));
                $this->_out('0.75 g');
                $this->_out(sprintf('BT 0 0 Td (%s) Tj ET', $this->_escape($txt)));
                $this->_out('Q');
            }
        };
        $text = $this->latin($label);
        $pdf->SetAutoPageBreak(false);
        try {
            $pages = $pdf->setSourceFile($path);
            for ($i = 1; $i <= $pages; $i++) {
                $tpl = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
                $pdf->SetFont('Helvetica', '', 22);
                $pdf->SetTextColor(190, 190, 190);
                foreach ([10.0, $size['width'] / 2] as $x) {
                    for ($y = 60.0; $y < $size['height']; $y += 80.0) {
                        $pdf->diagonalText($x, $y, $text, 35.0);
                    }
                }
                $pdf->SetFont('Helvetica', '', 8);
                $pdf->SetXY(8, $size['height'] - 8);
                $pdf->Cell(0, 4, $text, 0, 0, 'L');
            }

            return $pdf->Output('S');
        } catch (\Throwable $e) {
            throw new ContentException('PDF non elaborabile: '.$e->getMessage(), 0, $e);
        }
    }
}
