<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CustomerActionMail extends Mailable
{
    use Queueable, SerializesModels;

    protected array $templateData;

    public function __construct(
        public string $subjectLine,
        public string $viewName,
        array $viewData = []
    ) {
        $this->templateData = $viewData;
    }

    public function build()
    {
        return $this->subject($this->subjectLine)
            ->view($this->viewName, $this->templateData);
    }

    public function attachments(): array
    {
        return [];
    }
}
