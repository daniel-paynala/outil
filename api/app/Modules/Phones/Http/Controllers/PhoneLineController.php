<?php

namespace App\Modules\Phones\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Phones\Models\PhoneLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Le registre des lignes de l'entreprise.
 *
 * Lisible et modifiable par toute l'équipe. Le registre n'a de valeur que tenu
 * à jour, et le tenir à jour est le travail de celui qui achète la puce — pas
 * d'un administrateur qu'il faudrait aller chercher. Chaque ligne garde donc
 * la trace de qui l'a écrite et de qui l'a touchée en dernier : c'est ce qui
 * remplace le contrôle a priori.
 */
class PhoneLineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $lignes = PhoneLine::with([
            'creator:id,email,name,avatar_path',
            'updater:id,email,name,avatar_path',
        ])
            ->orderBy('operator')
            ->orderBy('number')
            ->get();

        return response()->json($lignes);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->valider($request);
        $userId = $this->userId($request);

        $ligne = PhoneLine::create([
            ...$data,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        return response()->json($this->rendre($ligne), 201);
    }

    public function update(Request $request, PhoneLine $line): JsonResponse
    {
        $data = $this->valider($request, $line);

        $ligne = tap($line)->update([
            ...$data,
            'updated_by' => $this->userId($request),
        ]);

        return response()->json($this->rendre($ligne));
    }

    public function destroy(Request $request, PhoneLine $line): JsonResponse
    {
        $line->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function valider(Request $request, ?PhoneLine $existante = null): array
    {
        // Le numéro est saisi comme il se dit : avec des espaces, parfois un
        // indicatif. On le range sans séparateurs, sinon « 77 05 09 46 » et
        // « 77050946 » cohabiteraient dans le registre comme deux lignes.
        if ($request->has('number')) {
            $request->merge([
                'number' => preg_replace('/[\s.\-()]/', '', (string) $request->input('number')),
            ]);
        }

        $unicite = Rule::unique('phone_lines', 'number')
            ->where(fn ($q) => $q->where('operator', $request->input('operator')));

        if ($existante !== null) {
            $unicite = $unicite->ignore($existante->id);
        }

        $data = $request->validate([
            'operator' => ['required', 'string', 'max:40'],
            'number' => ['required', 'string', 'max:32', 'regex:/^\+?\d{6,20}$/', $unicite],
            'registered_name' => ['required', 'string', 'max:120'],
            'purpose' => ['nullable', 'string', 'max:200'],
            'mobile_money' => ['sometimes', 'boolean'],
            'whatsapp' => ['sometimes', 'boolean'],
            'whatsapp_note' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'number.unique' => 'Ce numéro est déjà au registre pour cet opérateur.',
            'number.regex' => 'Un numéro ne contient que des chiffres.',
        ]);

        // Une note WhatsApp sans compte WhatsApp décrit un compte qui n'existe
        // pas : elle survivrait au décochage et se lirait comme un fait.
        if (! ($data['whatsapp'] ?? $existante?->whatsapp ?? false)) {
            $data['whatsapp_note'] = null;
        }

        return $data;
    }

    private function rendre(PhoneLine $ligne): PhoneLine
    {
        return $ligne->fresh([
            'creator:id,email,name,avatar_path',
            'updater:id,email,name,avatar_path',
        ]);
    }

    private function userId(Request $request): string
    {
        return $request->attributes->get('supabase_user_id')
            ?? abort(401, 'Missing user id');
    }
}
