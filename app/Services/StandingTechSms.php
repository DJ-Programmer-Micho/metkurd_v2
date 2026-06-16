<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class StandingTechSms
{
    protected string $base;

    protected string $token;

    protected string $sender;

    public function __construct()
    {
        $this->base = config('services.standingtech.base', env('STANDINGTECH_BASE_URL'));
        $this->token = config('services.standingtech.token', env('STANDINGTECH_TOKEN'));
        $this->sender = config('services.standingtech.sender', env('STANDINGTECH_SENDER_ID'));
    }

    protected function client()
    {
        return Http::baseUrl($this->base)
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->retry(2, 200)
            ->withToken($this->token);
    }

    /** ✅ Send provided message via WhatsApp/Telegram (+ optional fallback) */
    public function sendText(string $type, string $recipient, string $message, ?string $fallback = null, string $lang = 'en'): array
    {
        $payload = [
            'recipient' => $recipient, // no +
            'sender_id' => $this->sender,
            'type' => $type,      // whatsapp | telegram
            'message' => (string) $message,
            'lang' => $lang,
        ];

        if ($fallback) {
            $payload['fallback'] = $fallback;
        }

        $response = $this->client()->post('/api/v4/sms/send', $payload);
        $response->throw();

        return $response->json();
    }

    /** ✅ Send provided message via SMS */
    public function sendSmsText(string $recipient, string $message, string $lang = 'en'): array
    {
        $payload = [
            'recipient' => $recipient, // no +
            'sender_id' => $this->sender,
            'type' => 'sms',
            'message' => (string) $message,
            'lang' => $lang,
        ];

        $response = $this->client()->post('/api/v4/sms/send', $payload);
        $response->throw();

        return $response->json();
    }

    // (Optional) keep your old auto methods if used elsewhere:
    public function sendAuto(string $type, string $recipient, ?string $fallback = null, string $lang = 'en'): array
    {
        $otp = random_int(100000, 999999);

        return $this->sendText($type, $recipient, (string) $otp, $fallback, $lang);
    }

    public function sendSmsAuto(string $recipient, string $lang = 'en'): array
    {
        $otp = random_int(100000, 999999);

        return $this->sendSmsText($recipient, (string) $otp, $lang);
    }
}
