<?php

namespace App\Filament\Support;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Kotak <pre> untuk preview di dalam form — URL target, jadwal berikutnya,
 * perintah curl. Beberapa form memakainya, jadi styling-nya sengaja ditaruh di
 * satu view alih-alih ditulis ulang sebagai string HTML di tiap form class.
 */
final class PreviewBox
{
    public const TONE_INFO = 'info';

    public const TONE_WARNING = 'warning';

    public const TONE_MUTED = 'muted';

    public static function make(string $text, string $tone = self::TONE_MUTED): Htmlable
    {
        return new HtmlString(
            view('filament.components.preview-box', [
                'text' => $text,
                'tone' => $tone,
            ])->render()
        );
    }
}
