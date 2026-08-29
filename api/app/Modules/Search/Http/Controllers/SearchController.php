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
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

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

        try {
            return response()->json($this->results($q, $userId));
        } catch (HttpExceptionInterface $e) {
            // 401 / 403 : refus délibéré, il doit remonter tel quel.
            throw $e;
        } catch (Throwable $e) {
            // Garde-fou externe. `safe()` ne protège que les appels au moteur ;
            // tout ce qui est autour (requêtes SQL préalables, sérialisation,
            // et surtout l'écriture du log dans le catch lui-même) pouvait
            // encore renvoyer un 500. Or la recherche est un confort : quand
            // elle casse, le client doit recevoir une réponse exploitable qui
            // dit pourquoi, pas une erreur serveur opaque.
            $this->report($e);

            return response()->json([
                ...$this->empty(),
                'warning' => 'Recherche indisponible : '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Exécute la recherche sur les projets de l'utilisateur.
     *
     * @return array<string, mixed>
     */
    private function results(string $q, string $userId): array
    {
        $projectIds = ProjectMember::where('user_id', $userId)
            ->pluck('project_id')
            ->all();

        if (empty($projectIds)) {
            return $this->empty();
        }

        // Lookup table project_id => project summary
        $projects = Project::whereIn('id', $projectIds)
            ->get(['id', 'name', 'slug', 'color'])
            ->keyBy('id');

        $warning = null;

        return [
            'cards' => $this->safe(
                fn () => $this->mapCards(
                    Card::search($q)->whereIn('project_id', $projectIds)->take(10)->get(),
                    $projects,
                ),
                $warning,
            ),
            'docs' => $this->safe(
                fn () => $this->mapDocs(
                    DocPage::search($q)->whereIn('project_id', $projectIds)->take(10)->get(),
                    $projects,
                ),
                $warning,
            ),
            'decisions' => $this->safe(
                fn () => $this->mapDecisions(
                    Decision::search($q)->whereIn('project_id', $projectIds)->take(10)->get(),
                    $projects,
                ),
                $warning,
            ),
            'vault' => $this->safe(
                fn () => $this->mapVault(
                    VaultEntry::search($q)->whereIn('project_id', $projectIds)->take(10)->get(),
                    $projects,
                ),
                $warning,
            ),
            'files' => $this->safe(
                fn () => $this->mapFiles(
                    ProjectFile::search($q)->whereIn('project_id', $projectIds)->take(10)->get(),
                    $projects,
                ),
                $warning,
            ),
            'warning' => $warning,
        ];
    }

    /**
     * @param  array<string, mixed>|callable  $fn
     * @return array<int, mixed>
     */
    private function safe(callable $fn, ?string &$warning): array
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $this->report($e);
            if ($warning === null) {
                $warning = str_contains($e->getMessage(), 'Failed to connect')
                    ? 'Moteur de recherche indisponible sur le serveur.'
                    : 'Erreur moteur de recherche : '.$e->getMessage();
            }

            return [];
        }
    }

    /**
     * Journalise sans jamais lever.
     *
     * `Log::warning()` écrit dans storage/logs : si le dossier n'appartient pas
     * à www-data — panne déjà rencontrée sur ce déploiement — l'appel lance à
     * son tour, depuis l'intérieur d'un bloc catch. L'exception échappait alors
     * au filet et l'endpoint renvoyait 500 au lieu de dégrader proprement.
     */
    private function report(Throwable $e): void
    {
        try {
            Log::warning('Search failed: '.$e->getMessage(), [
                'exception' => $e::class,
            ]);
        } catch (Throwable) {
            // On ne casse pas une requête parce qu'un log n'a pas pu s'écrire.
        }
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
            'title' => 'ADR-'.str_pad((string) $d->number, 3, '0', STR_PAD_LEFT)." · {$d->title}",
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
        if (! $p) {
            return null;
        }

        return [
            'id' => $p->id,
            'name' => $p->name,
            'color' => $p->color,
        ];
    }

    private function snippet(?string $text): ?string
    {
        if (! $text) {
            return null;
        }
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
            'warning' => null,
        ];
    }
}
