<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $actionLink,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Réinitialisation de ton mot de passe Arche',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.password-reset',
            with: [
                'name' => $this->name,
                'actionLink' => $this->actionLink,
            ],
        );
    }
}
