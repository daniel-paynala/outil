<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Core\Models\Project;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\Column;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le tableau des tâches.
 *
 * ## Ce que ces tests visent
 *
 * `moveCard` est la méthode la plus longue de l'API et la seule qui réécrive
 * en masse : déplacer une carte renumérote toute la colonne de départ et toute
 * celle d'arrivée. C'est le genre de code où une erreur ne casse rien
 * bruyamment — elle laisse deux cartes à la même position, et le tableau se
 * met à changer d'ordre tout seul d'un rafraîchissement à l'autre.
 *
 * L'invariant vérifié partout ci-dessous est donc le même : après chaque
 * déplacement, les positions d'une colonne forment exactement 0, 1, 2… sans
 * trou ni doublon.
 */
class BoardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var array<string, string> */
    private array $entetes;

    private Project $projet;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->user, $this->entetes] = $this->authenticate();
        $this->projet = $this->projet($this->user);
    }

    private function projet(User $proprietaire): Project
    {
        $projet = Project::create([
            'id' => (string) Str::uuid(),
            'name' => 'Assuerpay',
            'slug' => 'assuerpay-'.Str::random(8),
            'created_by' => $proprietaire->id,
        ]);

        DB::table('project_members')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $projet->id,
            'user_id' => $proprietaire->id,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $projet;
    }

    private function colonne(string $nom, int $position, bool $termine = false, ?Project $projet = null): Column
    {
        return Column::create([
            'id' => (string) Str::uuid(),
            'project_id' => ($projet ?? $this->projet)->id,
            'name' => $nom,
            'position' => $position,
            'is_done' => $termine,
        ]);
    }

    /** @return array<int, Card> */
    private function cartes(Column $colonne, string ...$titres): array
    {
        $cartes = [];
        foreach ($titres as $i => $titre) {
            $cartes[] = Card::create([
                'id' => (string) Str::uuid(),
                'project_id' => $colonne->project_id,
                'column_id' => $colonne->id,
                'title' => $titre,
                'position' => $i,
                'created_by' => $this->user->id,
            ]);
        }

        return $cartes;
    }

    private function deplacer(Card $carte, Column $vers, int $position, ?array $entetes = null)
    {
        return $this->postJson("/api/projects/{$this->projet->id}/board/move", [
            'card_id' => $carte->id,
            'to_column_id' => $vers->id,
            'to_position' => $position,
        ], $entetes ?? $this->entetes);
    }

    /** @return array<int, string> titres dans l'ordre des positions */
    private function ordre(Column $colonne): array
    {
        return Card::where('column_id', $colonne->id)
            ->orderBy('position')
            ->pluck('title')
            ->all();
    }

    /**
     * Les positions doivent former 0, 1, 2… sans trou ni doublon.
     *
     * Un doublon ne se voit pas : les deux cartes s'affichent, et c'est le tri
     * de la base qui départage — donc un ordre différent à chaque requête.
     */
    private function assertPositionsContigues(Column $colonne): void
    {
        $positions = Card::where('column_id', $colonne->id)
            ->orderBy('position')
            ->pluck('position')
            ->all();

        $this->assertSame(
            range(0, count($positions) - 1),
            $positions,
            "Les positions de « {$colonne->name} » ne se suivent plus.",
        );
    }

    // ── Réordonner dans une même colonne ────────────────────────────────

    public function test_une_carte_remonte_en_tete(): void
    {
        $colonne = $this->colonne('À faire', 0);
        [$a, $b, $c] = $this->cartes($colonne, 'A', 'B', 'C');

        $this->deplacer($c, $colonne, 0)->assertOk();

        $this->assertSame(['C', 'A', 'B'], $this->ordre($colonne));
        $this->assertPositionsContigues($colonne);
    }

    public function test_une_carte_descend_en_fin(): void
    {
        $colonne = $this->colonne('À faire', 0);
        [$a, $b, $c] = $this->cartes($colonne, 'A', 'B', 'C');

        $this->deplacer($a, $colonne, 2)->assertOk();

        $this->assertSame(['B', 'C', 'A'], $this->ordre($colonne));
        $this->assertPositionsContigues($colonne);
    }

    public function test_une_position_au_dela_de_la_colonne_ne_casse_rien(): void
    {
        // L'interface peut envoyer une position trop grande sur un glissé
        // rapide. La carte doit finir en dernier, pas creuser un trou dans la
        // numérotation.
        $colonne = $this->colonne('À faire', 0);
        [$a, $b, $c] = $this->cartes($colonne, 'A', 'B', 'C');

        $this->deplacer($a, $colonne, 99)->assertOk();

        $this->assertPositionsContigues($colonne);
        $this->assertSame(['B', 'C', 'A'], $this->ordre($colonne));
    }

    // ── Passer d'une colonne à l'autre ──────────────────────────────────

    public function test_la_colonne_de_depart_se_resserre(): void
    {
        $depart = $this->colonne('À faire', 0);
        $arrivee = $this->colonne('En cours', 1);
        [$a, $b, $c] = $this->cartes($depart, 'A', 'B', 'C');

        $this->deplacer($b, $arrivee, 0)->assertOk();

        // Sans renumérotation, il resterait un trou en position 1 — invisible
        // jusqu'au déplacement suivant, qui insérerait alors au mauvais rang.
        $this->assertSame(['A', 'C'], $this->ordre($depart));
        $this->assertPositionsContigues($depart);
        $this->assertPositionsContigues($arrivee);
    }

    public function test_la_carte_s_insere_au_rang_demande(): void
    {
        $depart = $this->colonne('À faire', 0);
        $arrivee = $this->colonne('En cours', 1);
        [$a] = $this->cartes($depart, 'A');
        $this->cartes($arrivee, 'X', 'Y', 'Z');

        $this->deplacer($a, $arrivee, 1)->assertOk();

        $this->assertSame(['X', 'A', 'Y', 'Z'], $this->ordre($arrivee));
        $this->assertPositionsContigues($arrivee);
    }

    public function test_vider_une_colonne_la_laisse_coherente(): void
    {
        $depart = $this->colonne('À faire', 0);
        $arrivee = $this->colonne('En cours', 1);
        [$a] = $this->cartes($depart, 'A');

        $this->deplacer($a, $arrivee, 0)->assertOk();

        $this->assertSame([], $this->ordre($depart));
        $this->assertSame(['A'], $this->ordre($arrivee));
    }

    // ── L'achèvement ────────────────────────────────────────────────────

    public function test_entrer_dans_une_colonne_terminee_date_l_achevement(): void
    {
        $encours = $this->colonne('En cours', 0);
        $termine = $this->colonne('Terminé', 1, termine: true);
        [$a] = $this->cartes($encours, 'A');

        $this->deplacer($a, $termine, 0)->assertOk();

        $this->assertNotNull($a->fresh()->completed_at);
    }

    public function test_en_ressortir_efface_la_date(): void
    {
        $encours = $this->colonne('En cours', 0);
        $termine = $this->colonne('Terminé', 1, termine: true);
        [$a] = $this->cartes($encours, 'A');

        $this->deplacer($a, $termine, 0)->assertOk();
        $this->deplacer($a, $encours, 0)->assertOk();

        // Une tâche rouverte n'est plus terminée. Garder la date la ferait
        // compter dans les statistiques d'un travail qui reste à faire.
        $this->assertNull($a->fresh()->completed_at);
    }

    public function test_reordonner_dans_la_colonne_terminee_ne_redate_pas(): void
    {
        $termine = $this->colonne('Terminé', 0, termine: true);
        [$a, $b] = $this->cartes($termine, 'A', 'B');

        $this->deplacer($a, $termine, 1)->assertOk();
        $premiere = $a->fresh()->completed_at;

        $this->deplacer($a, $termine, 0)->assertOk();

        // Ranger une tâche déjà terminée n'est pas la terminer à nouveau.
        $this->assertEquals($premiere, $a->fresh()->completed_at);
    }

    // ── Les frontières ──────────────────────────────────────────────────

    public function test_un_non_membre_ne_deplace_rien(): void
    {
        $colonne = $this->colonne('À faire', 0);
        [$a] = $this->cartes($colonne, 'A');

        [, $etranger] = $this->authenticate();

        $this->deplacer($a, $colonne, 0, $etranger)->assertForbidden();
    }

    public function test_le_deplacement_exige_une_authentification(): void
    {
        $colonne = $this->colonne('À faire', 0);
        [$a] = $this->cartes($colonne, 'A');

        $this->postJson("/api/projects/{$this->projet->id}/board/move", [
            'card_id' => $a->id,
            'to_column_id' => $colonne->id,
            'to_position' => 0,
        ])->assertUnauthorized();
    }

    public function test_on_ne_tire_pas_une_carte_d_un_autre_projet(): void
    {
        // L'identifiant existe et la validation `exists:cards,id` passe. C'est
        // le filtre par projet, plus loin, qui doit refuser — sans lui, un
        // membre d'un projet déplacerait les cartes de tous les autres.
        [$autreUser] = $this->authenticate();
        $autreProjet = $this->projet($autreUser);
        $colonneEtrangere = $this->colonne('Ailleurs', 0, projet: $autreProjet);

        $etrangere = Card::create([
            'id' => (string) Str::uuid(),
            'project_id' => $autreProjet->id,
            'column_id' => $colonneEtrangere->id,
            'title' => 'Secret',
            'position' => 0,
            'created_by' => $autreUser->id,
        ]);

        $mienne = $this->colonne('À faire', 0);

        $this->deplacer($etrangere, $mienne, 0)->assertNotFound();

        $this->assertSame($colonneEtrangere->id, $etrangere->fresh()->column_id);
    }

    public function test_on_ne_pousse_pas_une_carte_vers_un_autre_projet(): void
    {
        [$autreUser] = $this->authenticate();
        $autreProjet = $this->projet($autreUser);
        $colonneEtrangere = $this->colonne('Ailleurs', 0, projet: $autreProjet);

        $mienne = $this->colonne('À faire', 0);
        [$a] = $this->cartes($mienne, 'A');

        $this->deplacer($a, $colonneEtrangere, 0)->assertNotFound();

        $this->assertSame($mienne->id, $a->fresh()->column_id);
    }

    // ── Suite de déplacements ───────────────────────────────────────────

    public function test_une_douzaine_de_deplacements_laisse_le_tableau_sain(): void
    {
        // Les défauts de renumérotation ne se voient pas au premier
        // déplacement : ils s'accumulent. On en enchaîne assez pour qu'un trou
        // ou un doublon finisse par déplacer la mauvaise carte.
        $a = $this->colonne('À faire', 0);
        $b = $this->colonne('En cours', 1);
        $c = $this->colonne('Terminé', 2, termine: true);

        $cartes = $this->cartes($a, 'A', 'B', 'C', 'D');
        $colonnes = [$a, $b, $c];

        foreach (range(0, 11) as $tour) {
            $carte = $cartes[$tour % 4];
            $cible = $colonnes[$tour % 3];
            $this->deplacer($carte, $cible, $tour % 3)->assertOk();
        }

        foreach ($colonnes as $colonne) {
            $this->assertPositionsContigues($colonne);
        }

        // Aucune carte perdue en route.
        $this->assertSame(4, Card::where('project_id', $this->projet->id)->count());
    }
}
