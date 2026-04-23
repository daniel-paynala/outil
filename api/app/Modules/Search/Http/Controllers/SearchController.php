<?php

namespace App\Modules\Search\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Adr\Models\Decision;
use App\Modules\Core\Models\Project;
use App\Modules\Core\Models\ProjectMember;
use App\Modules\Docs\Models\DocPage;
use App\Modules\Files\Models\ProjectFile;
use App\Modules\Tasks\Models\Card;
use App\Modules\Vault\Models\VaultEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (strlen($q) < 2) {
            return response()->json($this->empty());
        }

        $userId = $request->attributes->get('supabase_user_id')
            ?? abort(401, 'Missing user id');

        $projectIds = ProjectMember::where('user_id', $userId)
            ->pluck('project_id')
            ->all();

        if (empty($projectIds)) {
            return response()->json($this->empty());
        }

        // Lookup table project_id => project summary
        $projects = Project::whereIn('id', $projectIds)
            ->get(['id', 'name', 'slug', 'color'])
            ->keyBy('id');

        return response()->json([
            'cards' => $this->mapCards(
                Card::search($q)->whereIn('project_id', $projectIds)->take(10)->get(),
                $projects,
            ),
            'docs' => $this->mapDocs(
                DocPage::search($q)->whereIn('project_id', $projectIds)->take(10)->get(),
                $projects,
            ),
            'decisions' => $this->mapDecisions(
                Decision::search($q)->whereIn('project_id', $projectIds)->take(10)->get(),
                $projects,
            ),
            'vault' => $this->mapVault(
                VaultEntry::search($q)->whereIn('project_id', $projectIds)->take(10)->get(),
                $projects,
            ),
            'files' => $this->mapFiles(
                ProjectFile::search($q)->whereIn('project_id', $projectIds)->take(10)->get(),
                $projects,
            ),
        ]);
    }

    private function mapCards(Collection $cards, Collection $projects): array
    {
        return $cards->map(fn (Card $c) => [
            'id' => $c->id,
            'title' => $c->title,
            'snippet' => $this->snippet($c->description),
            'project' => $this->projectOf($projects, $c->project_id),
            'href' => "/projects/{$c->project_id}/tasks?card={$c->id}",
        ])->values()->all();
    }

    private function mapDocs(Collection $docs, Collection $projects): array
    {
        return $docs->map(fn (DocPage $d) => [
            'id' => $d->id,
            'title' => $d->title,
            'snippet' => $this->snippet($d->content),
            'project' => $this->projectOf($projects, $d->project_id),
            'href' => "/projects/{$d->project_id}/docs/{$d->id}",
        ])->values()->all();
    }

    private function mapDecisions(Collection $decisions, Collection $projects): array
    {
        return $decisions->map(fn (Decision $d) => [
            'id' => $d->id,
            'title' => "ADR-".str_pad((string) $d->number, 3, '0', STR_PAD_LEFT)." · {$d->title}",
            'snippet' => $this->snippet($d->context ?: $d->decision),
            'status' => $d->status,
            'project' => $this->projectOf($projects, $d->project_id),
            'href' => "/projects/{$d->project_id}/adr/{$d->id}",
        ])->values()->all();
    }

    private function mapVault(Collection $entries, Collection $projects): array
    {
        return $entries->map(fn (VaultEntry $e) => [
            'id' => $e->id,
            'title' => $e->name,
            'snippet' => $this->snippet($e->notes),
            'category' => $e->category,
            'username' => $e->username,
            'project' => $this->projectOf($projects, $e->project_id),
            'href' => "/projects/{$e->project_id}/vault",
        ])->values()->all();
    }

    private function mapFiles(Collection $files, Collection $projects): array
    {
        return $files->map(fn (ProjectFile $f) => [
            'id' => $f->id,
            'title' => $f->name,
            'mime_type' => $f->mime_type,
            'project' => $this->projectOf($projects, $f->project_id),
            'href' => "/projects/{$f->project_id}/documents",
        ])->values()->all();
    }

    private function projectOf(Collection $projects, string $projectId): ?array
    {
        $p = $projects->get($projectId);
        if (! $p) return null;
        return [
            'id' => $p->id,
            'name' => $p->name,
            'color' => $p->color,
        ];
    }

    private function snippet(?string $text): ?string
    {
        if (! $text) return null;
        $clean = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        return mb_strlen($clean) > 160 ? mb_substr($clean, 0, 160).'…' : $clean;
    }

    private function empty(): array
    {
        return [
            'cards' => [],
            'docs' => [],
            'decisions' => [],
            'vault' => [],
            'files' => [],
        ];
    }
}
