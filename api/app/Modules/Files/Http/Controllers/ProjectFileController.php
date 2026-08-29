<?php

namespace App\Modules\Files\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PendingDocumentNotification;
use App\Models\User;
use App\Modules\Activity\Services\ActivityLogger;
use App\Modules\Core\Models\Project;
use App\Modules\Files\Models\ProjectFile;
use App\Modules\Files\Models\ProjectFolder;
use App\Modules\Files\Services\SupabaseStorage;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ProjectFileController extends Controller
{
    public function __construct(
        private readonly SupabaseStorage $storage,
        private readonly ActivityLogger $activity,
        private readonly NotificationService $notify,
    ) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $files = ProjectFile::where('project_id', $project->id)
            ->with('uploader:id,email,name,avatar_path')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($files);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $request->validate([
            'file' => ['required', 'file', 'max:51200'], // 50 MB
            'folder_id' => ['nullable', 'uuid', 'exists:project_folders,id'],
        ]);

        // Si un folder_id est fourni, vérifier qu'il appartient bien au projet
        $folderId = $request->input('folder_id');
        if ($folderId) {
            $folder = ProjectFolder::find($folderId);
            if (! $folder || $folder->project_id !== $project->id) {
                abort(422, 'Invalid folder');
            }
        }

        $userId = $this->userId($request);
        $file = $request->file('file');
        $original = $file->getClientOriginalName();
        $sanitized = Str::slug(pathinfo($original, PATHINFO_FILENAME))
            .'.'.$file->getClientOriginalExtension();

        $path = sprintf('%s/%s-%s', $project->id, Str::uuid(), $sanitized);
        $mime = $file->getMimeType() ?: 'application/octet-stream';

        $this->storage->upload($path, file_get_contents($file->getRealPath()), $mime);

        $record = ProjectFile::create([
            'project_id' => $project->id,
            'folder_id' => $folderId ?: null,
            'path' => $path,
            'name' => $original,
            'size_bytes' => $file->getSize(),
            'mime_type' => $mime,
            'uploaded_by' => $userId,
        ]);

        $record->load('uploader:id,email,name,avatar_path');

        $this->activity->log($project->id, $userId, 'file.uploaded', $record, $record->name, [
            'size' => $record->size_bytes,
        ]);

        // Notif in-app pour tous les membres du projet sauf l'uploader
        $documentsUrl = "/projects/{$project->id}/documents";
        $this->notify->forProjectMembers(
            projectId: $project->id,
            type: 'document.uploaded',
            title: 'Nouveau document',
            body: $record->name.' · '.$project->name,
            link: $documentsUrl,
            actorId: $userId,
            exceptUserId: $userId,
        );

        // Email opt-in
        $this->sendDocumentUploadedEmails($project, $record, $request);

        return response()->json($record, 201);
    }

    /**
     * Déplace un fichier dans un autre dossier (ou racine si folder_id=null).
     */
    public function update(Request $request, ProjectFile $file): JsonResponse
    {
        $this->ensureMember($request, $file->project);

        $data = $request->validate([
            'folder_id' => ['sometimes', 'nullable', 'uuid', 'exists:project_folders,id'],
        ]);

        if (array_key_exists('folder_id', $data) && $data['folder_id']) {
            $folder = ProjectFolder::find($data['folder_id']);
            if (! $folder || $folder->project_id !== $file->project_id) {
                abort(422, 'Invalid folder');
            }
        }

        $file->update($data);
        $file->load('uploader:id,email,name,avatar_path');

        return response()->json($file);
    }

    /**
     * Return a short-lived signed URL the frontend can use to download.
     */
    public function show(Request $request, ProjectFile $file): JsonResponse
    {
        $this->ensureMember($request, $file->project);

        return response()->json([
            'url' => $this->storage->signedUrl($file->path, 3600),
            'expires_in' => 3600,
        ]);
    }

    public function destroy(Request $request, ProjectFile $file): JsonResponse
    {
        $this->ensureMember($request, $file->project);

        $name = $file->name;
        $projectId = $file->project_id;

        $this->storage->delete($file->path);
        $file->delete();

        $this->activity->log($projectId, $this->userId($request), 'file.deleted', null, $name);

        return response()->json(null, 204);
    }

    private function userId(Request $request): string
    {
        return $request->attributes->get('supabase_user_id')
            ?? abort(401, 'Missing user id');
    }

    private function ensureMember(Request $request, Project $project): void
    {
        if (! $project->hasMember($this->userId($request))) {
            abort(403, 'Not a member of this project');
        }
    }

    /**
     * Place le fichier dans la file d'attente de notification pour chaque
     * destinataire ayant activé la pref. Le mail réel sera envoyé par la
     * commande `documents:flush-notifications` après 5 min sans nouvel upload
     * (debounce, pour ne pas spammer en cas d'imports en masse).
     */
    private function sendDocumentUploadedEmails(Project $project, ProjectFile $file, Request $request): void
    {
        $recipients = User::query()
            ->whereIn('id', function ($q) use ($project) {
                $q->select('user_id')
                    ->from('project_members')
                    ->where('project_id', $project->id);
            })
            ->whereRaw('notify_project_document_email = true')
            ->whereNotNull('email')
            ->pluck('id');

        foreach ($recipients as $userId) {
            // Upsert : si une entrée existe déjà pour (project, user),
            // on append ce file_id et on reset last_upload_at au now().
            $pending = PendingDocumentNotification::firstOrNew([
                'project_id' => $project->id,
                'user_id' => $userId,
            ]);
            $existing = $pending->file_ids ?? [];
            $existing[] = (string) $file->id;
            $pending->file_ids = array_values(array_unique($existing));
            $pending->last_upload_at = now();
            $pending->save();
        }

        Log::info('[doc-mail] queued', [
            'file_id' => $file->id,
            'project' => $project->id,
            'recipients_count' => $recipients->count(),
        ]);
    }
}
