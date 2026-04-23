<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Project;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\Label;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LabelController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        return response()->json(
            $project->labels ?? Label::where('project_id', $project->id)->orderBy('name')->get(),
        );
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $label = Label::create([
            'project_id' => $project->id,
            'name' => $data['name'],
            'color' => $data['color'],
        ]);

        return response()->json($label, 201);
    }

    public function update(Request $request, Label $label): JsonResponse
    {
        $this->ensureMember($request, $label->project);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $label->update($data);

        return response()->json($label);
    }

    public function destroy(Request $request, Label $label): JsonResponse
    {
        $this->ensureMember($request, $label->project);
        $label->delete();

        return response()->json(null, 204);
    }

    public function attachToCard(Request $request, Card $card): JsonResponse
    {
        $this->ensureMember($request, $card->project);

        $data = $request->validate([
            'label_id' => ['required', 'uuid', 'exists:labels,id'],
        ]);

        $label = Label::findOrFail($data['label_id']);
        abort_if($label->project_id !== $card->project_id, 422, 'Label from different project');

        $card->labels()->syncWithoutDetaching([$label->id]);

        return response()->json($card->labels()->get());
    }

    public function detachFromCard(Request $request, Card $card, Label $label): JsonResponse
    {
        $this->ensureMember($request, $card->project);
        $card->labels()->detach($label->id);

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
