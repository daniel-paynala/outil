<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TaskAssigned extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{title: string, description: ?string, priority: ?string, due_date: ?string, project_name: string, project_color: string, column_name: ?string}  $task
     */
    public function __construct(
        public string $assigneeName,
        public array $task,
        public string $taskUrl,
        public ?string $assignerName = null,
    ) {}

    public function envelope(): Envelope
    {
        $title = $this->task['title'] ?? 'Nouvelle tâche';

        return new Envelope(
            subject: "Tâche attribuée : {$title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.task-assigned',
            with: [
                'assigneeName' => $this->assigneeName,
                'task' => $this->task,
                'taskUrl' => $this->taskUrl,
                'assignerName' => $this->assignerName,
            ],
        );
    }
}
