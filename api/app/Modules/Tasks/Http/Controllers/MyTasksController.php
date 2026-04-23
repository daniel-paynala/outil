<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Models\Card;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyTasksController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('supabase_user_id')
            ?? abort(401, 'Missing user id');

        $cards = Card::query()
            ->where('assigned_to', $userId)
            ->with([
                'project:id,name,slug,color',
                'column:id,name,position',
                'labels:id,name,color',
            ])
            ->withCount(['dependencies', 'children'])
            ->orderByRaw('due_date IS NULL, due_date ASC')
            ->orderBy('priority')
            ->get();

        return response()->json($cards);
    }
}
