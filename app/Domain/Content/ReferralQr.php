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
        return url('/r/'.$c->ensureReferralCode());
    }

    /** Come lo si legge a video: dominio con gli accenti (lamèss.it). */
    public static function pretty(Consultant $c): string
    {
        return \App\Support\Idn::pretty(self::url($c));
    }

    public static function svg(Consultant $c, int $size = 320): string
    {
        $c->ensureReferralCode();

        return (new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd)))->writeString(self::url($c));
    }
}
