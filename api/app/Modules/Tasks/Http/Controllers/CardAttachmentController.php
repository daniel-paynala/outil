<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Services\ActivityLogger;
use App\Modules\Files\Services\SupabaseStorage;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\CardAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CardAttachmentController extends Controller
{
    public function __construct(
        private readonly SupabaseStorage $storage,
        private readonly ActivityLogger $activity,
    ) {}

    public function index(Request $request, Card $card): JsonResponse
    {
        $this->ensureMember($request, $card);

        $attachments = CardAttachment::where('card_id', $card->id)
            ->with('uploader:id,email,name,avatar_path')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($attachments);
    }

    public function store(Request $request, Card $card): JsonResponse
    {
        $this->ensureMember($request, $card);

        $request->validate([
            'file' => ['required', 'file', 'max:51200'], // 50 MB
        ]);

        $userId = $this->userId($request);
        $file = $request->file('file');
        $original = $file->getClientOriginalName();
        $sanitized = Str::slug(pathinfo($original, PATHINFO_FILENAME))
            .'.'.$file->getClientOriginalExtension();

        $path = sprintf('cards/%s/%s-%s', $card->id, Str::uuid(), $sanitized);
        $mime = $file->getMimeType() ?: 'application/octet-stream';

        $this->storage->upload($path, file_get_contents($file->getRealPath()), $mime);

        $record = CardAttachment::create([
            'card_id' => $card->id,
            'path' => $path,
            'name' => $original,
            'size_bytes' => $file->getSize(),
            'mime_type' => $mime,
            'uploaded_by' => $userId,
            'created_at' => now(),
        ]);

        $record->load('uploader:id,email,name,avatar_path');

        $this->activity->log(
            $card->project_id,
            $userId,
            'card.attachment.added',
            $card,
            $record->name,
            ['card_title' => $card->title],
        );

        return response()->json($record, 201);
    }

    /**
     * Returns a short-lived signed URL for download.
     */
    public function show(Request $request, CardAttachment $attachment): JsonResponse
    {
        $this->ensureMember($request, $attachment->card);

        return response()->json([
            'url' => $this->storage->signedUrl($attachment->path, 3600),
            'expires_in' => 3600,
            'name' => $attachment->name,
            'mime_type' => $attachment->mime_type,
        ]);
    }

    public function destroy(Request $request, CardAttachment $attachment): JsonResponse
    {
        $this->ensureMember($request, $attachment->card);

        $userId = $this->userId($request);
        $name = $attachment->name;
        $card = $attachment->card;

        try {
            $this->storage->delete($attachment->path);
        } catch (\Throwable $e) {
            // Le fichier peut déjà avoir été supprimé du storage : on continue
            // de supprimer la row pour éviter de laisser des orphelins en DB.
        }
        $attachment->delete();

        $this->activity->log(
            $card->project_id,
            $userId,
            'card.attachment.removed',
            $card,
            $name,
            ['card_title' => $card->title],
        );

        return response()->json(null, 204);
    }

    private function userId(Request $request): string
    {
        return $request->attributes->get('supabase_user_id')
            ?? abort(401, 'Missing user id');
    }

    private function ensureMember(Request $request, Card $card): void
    {
        if (! $card->project->hasMember($this->userId($request))) {
            abort(403, 'Not a member of this project');
        }
    }
}
