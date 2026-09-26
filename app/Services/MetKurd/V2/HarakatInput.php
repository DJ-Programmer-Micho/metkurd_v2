<?php

namespace App\Services\MetKurd\V2;

use Illuminate\Validation\ValidationException;

class HarakatInput
{
    public function limit(): int
    {
        return max(1, (int) config('metkurd_v2.harakat.max_chars', 5000));
    }

    public function trimmed(string $text): string
    {
        return preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $text) ?? '';
    }

    public function prepare(mixed $value): array
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || ($text = $this->trimmed($value)) === '') {
            throw ValidationException::withMessages(['text' => __('Enter text to diacritize.')]);
        }
        $chars = mb_strlen($text, 'UTF-8');
        if ($chars > $this->limit()) {
            throw ValidationException::withMessages(['text' => __('Text must not exceed :count characters.', ['count' => $this->limit()])]);
        }

        // Match the worker: mixed/non-Arabic characters are accepted, not removed.
        return ['text' => $text, 'characters' => $chars];
    }
}
