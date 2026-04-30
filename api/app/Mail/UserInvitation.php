<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $actionLink,
        public ?string $inviterName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invitation à rejoindre Arche',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.user-invitation',
            with: [
                'name' => $this->name,
                'actionLink' => $this->actionLink,
                'inviterName' => $this->inviterName,
            ],
        );
    }
}
