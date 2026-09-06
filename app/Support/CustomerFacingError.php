<?php

namespace App\Support;

class CustomerFacingError
{
    /** Only known catalog messages may cross the customer error boundary. */
    public static function message(?string $message): string
    {
        $message = trim((string) $message);
        $catalog = AreaJsonTranslations::all('app');
        $translated = $catalog[$message] ?? null;

        if (! is_string($translated) && in_array($message, $catalog, true)) {
            $translated = $message;
        }

        if (is_string($translated) && $translated !== '' && stripos($translated, 'runpod') === false) {
            return $translated;
        }

        return AreaJsonTranslations::get('Processing could not be completed. Please try again.', 'app')
            ?? 'Processing could not be completed. Please try again.';
    }
}
