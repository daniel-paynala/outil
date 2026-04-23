<?php

namespace App\Modules\Files\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Project;
use App\Modules\Files\Models\ProjectFile;
use App\Modules\Files\Services\SupabaseStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectFileController extends Controller
{
    public function __construct(private readonly SupabaseStorage $storage) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $files = ProjectFile::where('project_id', $project->id)
            ->with('uploader:id,email,name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($files);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $request->validate([
            'file' => ['required', 'file', 'max:51200'], // 50 MB
        ]);

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
            'path' => $path,
            'name' => $original,
            'size_bytes' => $file->getSize(),
            'mime_type' => $mime,
            'uploaded_by' => $userId,
        ]);

        $record->load('uploader:id,email,name');

        return response()->json($record, 201);
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

        $this->storage->delete($file->path);
        $file->delete();

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
}
