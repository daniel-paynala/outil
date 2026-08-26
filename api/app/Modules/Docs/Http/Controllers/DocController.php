<?php

namespace App\Modules\Docs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Services\ActivityLogger;
use App\Modules\Core\Models\Project;
use App\Modules\Docs\Models\DocPage;
use App\Modules\Docs\Models\DocRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $pages = DocPage::where('project_id', $project->id)
            ->select(['id', 'project_id', 'parent_id', 'title', 'slug', 'position', 'updated_at'])
            ->orderBy('position')
            ->orderBy('title')
            ->get();

        return response()->json($pages);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'parent_id' => ['nullable', 'uuid', 'exists:doc_pages,id'],
        ]);

        $userId = $this->userId($request);

        // Validate parent belongs to same project
        if (! empty($data['parent_id'])) {
            $parent = DocPage::findOrFail($data['parent_id']);
            abort_if($parent->project_id !== $project->id, 422, 'Parent from different project');
        }

        $position = (int) (DocPage::where('project_id', $project->id)
            ->where('parent_id', $data['parent_id'] ?? null)
            ->max('position') ?? -1) + 1;

        $page = DocPage::create([
            'project_id' => $project->id,
            'parent_id' => $data['parent_id'] ?? null,
            'title' => $data['title'],
            'slug' => $this->uniqueSlug($project->id, $data['title']),
            'content' => null,
            'position' => $position,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $this->activity->log($project->id, $userId, 'doc.created', $page, $page->title);

        return response()->json($page, 201);
    }

    public function show(Request $request, DocPage $page): JsonResponse
    {
        $this->ensureMember($request, $page->project);

        $page->load([
            'creator:id,email,name,avatar_path',
            'updater:id,email,name,avatar_path',
        ]);

        return response()->json($page);
    }

    public function update(Request $request, DocPage $page): JsonResponse
    {
        $this->ensureMember($request, $page->project);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'content' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'uuid', 'exists:doc_pages,id'],
        ]);

        $userId = $this->userId($request);

        // Prevent setting a page's parent to itself or a descendant
        if (array_key_exists('parent_id', $data) && $data['parent_id']) {
            if ($data['parent_id'] === $page->id) {
                abort(422, 'Cannot set a page as its own parent');
            }
            $ancestor = DocPage::find($data['parent_id']);
            while ($ancestor) {
                if ($ancestor->id === $page->id) {
                    abort(422, 'Cannot set a descendant as parent');
                }
                $ancestor = $ancestor->parent_id ? DocPage::find($ancestor->parent_id) : null;
            }
        }

        DB::transaction(function () use ($page, $data, $userId) {
            $contentChanged = array_key_exists('content', $data)
                && $data['content'] !== $page->content;
            $titleChanged = array_key_exists('title', $data)
                && $data['title'] !== $page->title;

            // Snapshot the previous state before applying changes
            if ($contentChanged || $titleChanged) {
                DocRevision::create([
                    'page_id' => $page->id,
                    'title' => $page->title,
                    'content' => $page->content,
                    'created_by' => $userId,
                    'created_at' => now(),
                ]);
            }

            $page->update([
                ...$data,
                'updated_by' => $userId,
            ]);
        });

        $this->activity->log($page->project_id, $userId, 'doc.updated', $page, $page->title);

        $page->load(['updater:id,email,name,avatar_path']);

        return response()->json($page);
    }

    public function destroy(Request $request, DocPage $page): JsonResponse
    {
        $this->ensureMember($request, $page->project);
        $title = $page->title;
        $projectId = $page->project_id;
        $page->delete();

        $this->activity->log($projectId, $this->userId($request), 'doc.deleted', null, $title);

        return response()->json(null, 204);
    }

    public function revisions(Request $request, DocPage $page): JsonResponse
    {
        $this->ensureMember($request, $page->project);

        $revs = $page->revisions()
            ->with('creator:id,email,name,avatar_path')
            ->select(['id', 'page_id', 'title', 'created_by', 'created_at'])
            ->get();

        return response()->json($revs);
    }

    public function showRevision(Request $request, DocRevision $revision): JsonResponse
    {
        $page = DocPage::findOrFail($revision->page_id);
        $this->ensureMember($request, $page->project);

        $revision->load('creator:id,email,name,avatar_path');

        return response()->json($revision);
    }

    public function restoreRevision(Request $request, DocPage $page, DocRevision $revision): JsonResponse
    {
        $this->ensureMember($request, $page->project);
        abort_if($revision->page_id !== $page->id, 422, 'Revision from different page');

        $userId = $this->userId($request);

        DB::transaction(function () use ($page, $revision, $userId) {
            // Snapshot current state first
            DocRevision::create([
                'page_id' => $page->id,
                'title' => $page->title,
                'content' => $page->content,
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            $page->update([
                'title' => $revision->title,
                'content' => $revision->content,
                'updated_by' => $userId,
            ]);
        });

        $this->activity->log($page->project_id, $userId, 'doc.restored', $page, $page->title, [
            'revision_id' => $revision->id,
        ]);

        return response()->json($page->fresh(['updater:id,email,name,avatar_path']));
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

    private function uniqueSlug(string $projectId, string $title): string
    {
        $base = Str::slug($title) ?: 'page';
        $slug = $base;
        $i = 1;
        while (DocPage::withTrashed()
            ->where('project_id', $projectId)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }
        return $slug;
    }
}
