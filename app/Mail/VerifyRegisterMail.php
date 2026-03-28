<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VerifyRegisterMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public string $otpCode,
        public int $expiresMinutes = 5
    )
    {
    }

    public function build()
    {
        return $this->subject('Your MET KURD verification code')
            ->view('app.otp.verify-register', [
                'otpCode' => $this->otpCode,
                'expiresMinutes' => $this->expiresMinutes,
                'supportEmail' => 'support@metkurd.com',
            ]);
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
