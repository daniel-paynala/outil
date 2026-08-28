<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProjectDocumentUploaded extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{name: string, size_bytes: ?int, mime_type: ?string}>  $documents  Liste de 1 ou plusieurs documents (digest)
     * @param  array{project_name: string, project_color: string}  $project
     */
    public function __construct(
        public string $recipientName,
        public array $documents,
        public array $project,
        public string $documentsUrl,
    ) {}

    public function envelope(): Envelope
    {
        $count = count($this->documents);
        $project = $this->project['project_name'] ?? 'projet';
        $subject = $count === 1
            ? "Nouveau document sur {$project}"
            : "{$count} nouveaux documents sur {$project}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.project-document-uploaded',
            with: [
                'recipientName' => $this->recipientName,
                'documents' => $this->documents,
                'project' => $this->project,
                'documentsUrl' => $this->documentsUrl,
                'count' => count($this->documents),
            ],
        );
    }
}
