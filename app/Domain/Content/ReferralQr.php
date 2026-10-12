<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Models\Consultant;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** Link e QR code (SVG) del referral di un incaricato. */
final class ReferralQr
{
    public static function url(Consultant $c): string
    {
        return url('/r/'.$c->referral_code);
    }

    public static function svg(Consultant $c, int $size = 320): string
    {
        if (! $c->referral_code) {
            throw new ContentException('L’incaricato non ha un codice referral.');
        }

        return (new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd)))->writeString(self::url($c));
    }
}
