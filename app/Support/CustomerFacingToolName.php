<?php

namespace App\Support;

use Illuminate\Support\Str;

class CustomerFacingToolName
{
    public static function canonical(?string $toolCode): string
    {
        $toolCode = strtolower(trim((string) $toolCode));

        return match ($toolCode) {
            'xtts', 'tts' => 'tts',
            'xomni', 'omni', 'omnivoice' => 'xomni',
            'xomni-v2' => 'xomni-v2',
            'f5tts', 'ftts' => 'ftts',
            'clone_tts', 'clone-tts', 'clone_xtts', 'clone-xtts', 'ctts' => 'clone_tts',
            'clone_xomni', 'clone-xomni' => 'clone_xomni',
            'asr', 'wasr' => 'asr',
            'qasr' => 'qasr',
            'caption' => 'caption',
            'ocr' => 'ocr',
            'stem' => 'stem',
            'tran', 'translation' => 'tran',
            'youtube' => 'youtube_download',
            default => $toolCode,
        };
    }

    public static function labelKey(?string $toolCode): string
    {
        return match (self::canonical($toolCode)) {
            'tts' => 'Apollo 1.0v',
            'xomni' => 'Apollo 1.5v',
            'xomni-v2' => 'Apollo 2.0v',
            'ftts' => 'Delta',
            'clone_tts' => 'Vector 1.0v',
            'clone_xomni' => 'Vector 1.5v',
            'asr' => 'WASR NEO',
            'qasr' => 'QASR LEO',
            'caption' => 'Caption',
            'ocr' => 'OCR Scanner',
            'stem' => 'Stem Separation',
            'tran' => 'MET Translation',
            'youtube_audio' => 'YouTube Audio',
            'youtube_video' => 'YouTube Video',
            'youtube_download' => 'YouTube Downloader',
            default => Str::headline(str_replace(['_', '-'], ' ', (string) $toolCode)),
        };
    }

    public static function translated(?string $toolCode): string
    {
        return __(self::labelKey($toolCode));
    }

    /**
     * @return array<int, string>
     */
    public static function filterCodes(?string $toolCode): array
    {
        return match (self::canonical($toolCode)) {
            'tts' => ['tts', 'xtts'],
            'xomni' => ['xomni', 'omni', 'omnivoice'],
            'xomni-v2' => ['xomni-v2'],
            'ftts' => ['ftts', 'f5tts'],
            'clone_tts' => ['clone_tts', 'clone-tts', 'clone_xtts', 'clone-xtts', 'ctts'],
            'clone_xomni' => ['clone_xomni', 'clone-xomni'],
            'asr' => ['asr', 'wasr'],
            'qasr' => ['qasr'],
            'caption' => ['caption'],
            'ocr' => ['ocr'],
            'stem' => ['stem'],
            'tran' => ['tran', 'translation'],
            'youtube_audio' => ['youtube_audio'],
            'youtube_video' => ['youtube_video'],
            'youtube_download' => ['youtube_download', 'youtube'],
            default => [],
        };
    }
}
