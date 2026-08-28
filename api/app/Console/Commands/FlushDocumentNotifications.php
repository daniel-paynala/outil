<?php

namespace App\Console\Commands;

use App\Mail\ProjectDocumentUploaded;
use App\Models\PendingDocumentNotification;
use App\Modules\Files\Models\ProjectFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Envoie les emails de notification de documents en file d'attente, après
 * un délai de 5 minutes sans nouvel upload (debounce).
 *
 * Conçu pour être appelé toutes les minutes via le scheduler Laravel.
 */
class FlushDocumentNotifications extends Command
{
    protected $signature = 'documents:flush-notifications {--debounce=5 : minutes sans upload avant envoi}';

    protected $description = 'Envoie les emails groupés de notifications de documents (digest 5 min)';

    public function handle(): int
    {
        $debounceMinutes = (int) $this->option('debounce');
        $threshold = now()->subMinutes($debounceMinutes);

        $pendings = PendingDocumentNotification::where('last_upload_at', '<=', $threshold)
            ->with('project:id,name,color', 'user:id,email,name')
            ->get();

        if ($pendings->isEmpty()) {
            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($pendings as $pending) {
            $user = $pending->user;
            $project = $pending->project;

            if (! $user || ! $project || ! $user->email) {
                $pending->delete();

                continue;
            }

            $files = ProjectFile::whereIn('id', $pending->file_ids ?? [])
                ->orderBy('created_at')
                ->get(['id', 'name', 'size_bytes', 'mime_type']);

            if ($files->isEmpty()) {
                // Tous les fichiers ont été supprimés depuis : on ne notifie pas
                $pending->delete();

                continue;
            }

            $documents = $files->map(fn (ProjectFile $f) => [
                'name' => $f->name,
                'size_bytes' => $f->size_bytes,
                'mime_type' => $f->mime_type,
            ])->all();

            $documentsUrl = rtrim((string) config('app.url'), '/')
                ."/projects/{$project->id}/documents";

            try {
                Mail::to($user->email)->send(new ProjectDocumentUploaded(
                    recipientName: $user->name ?? $user->email,
                    documents: $documents,
                    project: [
                        'project_name' => $project->name,
                        'project_color' => $project->color ?? '#dc2626',
                    ],
                    documentsUrl: $documentsUrl,
                ));
                $pending->delete();
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('[doc-mail] flush failed', [
                    'pending_id' => $pending->id,
                    'recipient' => $user->email,
                    'count' => $files->count(),
                    'error' => $e->getMessage(),
                ]);
                // On garde l'entry pour réessayer plus tard
            }
        }

        $this->info("Sent: $sent · Failed: $failed");
        Log::info('[doc-mail] flush', ['sent' => $sent, 'failed' => $failed]);

        return self::SUCCESS;
    }
}
