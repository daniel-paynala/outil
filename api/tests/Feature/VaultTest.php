<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Core\Models\Project;
use App\Modules\Vault\Models\VaultEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le coffre.
 *
 * ## Pourquoi ces tests-là plutôt que d'autres
 *
 * C'est le seul module où une erreur ne se rattrape pas. Une tâche mal
 * assignée se réassigne, un document mal rangé se déplace ; un mot de passe
 * montré à quelqu'un qui n'y avait pas droit est montré pour toujours.
 *
 * Les cas couverts sont donc ceux où la réponse serait irréversible : qui peut
 * lire un secret, qui peut le révéler, qui peut modifier qui y a droit, et si
 * le journal d'accès dit la vérité. Le reste — pagination, tri, validation de
 * formulaire — se corrige à la première plainte.
 */
class VaultTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un projet et son créateur, membre d'office.
     *
     * @return array{0: User, 1: array<string, string>, 2: Project}
     */
    private function projetAvecMembre(string $role = 'member'): array
    {
        [$user, $entetes] = $this->authenticate();

        $projet = Project::create([
            'id' => (string) Str::uuid(),
            'name' => 'Assuerpay',
            'slug' => 'assuerpay-'.Str::random(6),
            'created_by' => $user->id,
        ]);

        $this->rattacher($projet, $user, $role);

        return [$user, $entetes, $projet];
    }

    private function rattacher(Project $projet, User $user, string $role = 'member'): void
    {
        DB::table('project_members')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $projet->id,
            'user_id' => $user->id,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function entree(Project $projet, User $auteur, array $attributs = []): VaultEntry
    {
        return VaultEntry::create([
            'id' => (string) Str::uuid(),
            'project_id' => $projet->id,
            'name' => 'Compte Mailgun',
            'category' => 'other',
            'secret' => 'motdepasse-très-secret',
            'created_by' => $auteur->id,
            ...$attributs,
        ]);
    }

    // ── Ce que le secret ne doit jamais faire ────────────────────────────

    public function test_le_secret_est_chiffre_en_base(): void
    {
        [$user, , $projet] = $this->projetAvecMembre();
        $entree = $this->entree($projet, $user);

        // Lu par Eloquent, il est en clair. Lu par une requête brute — ce que
        // verrait quiconque obtient une copie de la base — il ne l'est pas.
        $brut = DB::table('vault_entries')->where('id', $entree->id)->value('secret');

        $this->assertNotSame('motdepasse-très-secret', $brut);
        $this->assertStringNotContainsString('motdepasse', $brut);
        $this->assertSame('motdepasse-très-secret', $entree->fresh()->secret);
    }

    public function test_le_secret_ne_part_pas_avec_la_liste(): void
    {
        [$user, $entetes, $projet] = $this->projetAvecMembre();
        $this->entree($projet, $user);

        $reponse = $this->getJson("/api/projects/{$projet->id}/vault", $entetes)
            ->assertOk();

        // L'écran de liste n'a aucune raison de porter les secrets, et les y
        // mettre les exposerait à chaque affichage plutôt qu'à chaque demande
        // explicite — dont on garde trace, contrairement à un affichage.
        $this->assertArrayNotHasKey('secret', $reponse->json()[0]);
    }

    public function test_le_secret_ne_part_pas_avec_la_fiche(): void
    {
        [$user, $entetes, $projet] = $this->projetAvecMembre();
        $entree = $this->entree($projet, $user);

        $reponse = $this->getJson("/api/vault/{$entree->id}", $entetes)->assertOk();

        $this->assertArrayNotHasKey('secret', $reponse->json());
    }

    public function test_reveler_rend_le_secret_en_clair(): void
    {
        [$user, $entetes, $projet] = $this->projetAvecMembre();
        $entree = $this->entree($projet, $user);

        $this->getJson("/api/vault/{$entree->id}/reveal", $entetes)
            ->assertOk()
            ->assertJson(['secret' => 'motdepasse-très-secret']);
    }

    // ── Qui peut lire quoi ──────────────────────────────────────────────

    public function test_un_non_membre_ne_voit_rien(): void
    {
        [$auteur, , $projet] = $this->projetAvecMembre();
        $entree = $this->entree($projet, $auteur);

        [, $etrangerEntetes] = $this->authenticate();

        $this->getJson("/api/projects/{$projet->id}/vault", $etrangerEntetes)
            ->assertForbidden();
        $this->getJson("/api/vault/{$entree->id}", $etrangerEntetes)
            ->assertForbidden();
        $this->getJson("/api/vault/{$entree->id}/reveal", $etrangerEntetes)
            ->assertForbidden();
    }

    public function test_une_entree_restreinte_est_invisible_aux_autres_membres(): void
    {
        [$auteur, , $projet] = $this->projetAvecMembre();
        $entree = $this->entree($projet, $auteur, ['visibility' => 'restricted']);

        // Membre du projet, mais pas sur la liste blanche. C'est tout l'objet
        // du mode restreint : appartenir à l'équipe ne suffit pas.
        [$collegue, $collegueEntetes] = $this->authenticate();
        $this->rattacher($projet, $collegue);

        $this->getJson("/api/vault/{$entree->id}", $collegueEntetes)
            ->assertForbidden();
        $this->getJson("/api/vault/{$entree->id}/reveal", $collegueEntetes)
            ->assertForbidden();

        // Et elle ne doit pas non plus apparaître dans la liste : une entrée
        // qu'on voit sans pouvoir l'ouvrir renseigne déjà sur son existence.
        $liste = $this->getJson("/api/projects/{$projet->id}/vault", $collegueEntetes)
            ->assertOk()
            ->json();

        $this->assertSame([], $liste);
    }

    public function test_une_entree_restreinte_s_ouvre_a_qui_est_sur_la_liste(): void
    {
        [$auteur, , $projet] = $this->projetAvecMembre();
        $entree = $this->entree($projet, $auteur, ['visibility' => 'restricted']);

        [$invite, $inviteEntetes] = $this->authenticate();
        $this->rattacher($projet, $invite);
        $entree->allowedUsers()->attach($invite->id);

        $this->getJson("/api/vault/{$entree->id}/reveal", $inviteEntetes)
            ->assertOk()
            ->assertJson(['secret' => 'motdepasse-très-secret']);
    }

    public function test_le_createur_garde_acces_a_sa_propre_entree_restreinte(): void
    {
        [$auteur, $entetes, $projet] = $this->projetAvecMembre();

        // Sans cette règle, on peut se verrouiller hors de son propre secret
        // en le restreignant sans se mettre soi-même sur la liste.
        $entree = $this->entree($projet, $auteur, ['visibility' => 'restricted']);

        $this->getJson("/api/vault/{$entree->id}/reveal", $entetes)->assertOk();
    }

    // ── Qui peut changer les droits ─────────────────────────────────────

    public function test_un_membre_ordinaire_ne_change_pas_la_visibilite(): void
    {
        [$auteur, , $projet] = $this->projetAvecMembre();
        $entree = $this->entree($projet, $auteur);

        [$collegue, $collegueEntetes] = $this->authenticate();
        $this->rattacher($projet, $collegue);

        // Il a accès au secret — l'entrée est en mode « tous ». Ça ne lui donne
        // pas le droit de décider qui d'autre y aura accès demain.
        $this->getJson("/api/vault/{$entree->id}/reveal", $collegueEntetes)->assertOk();

        $this->patchJson("/api/vault/{$entree->id}", [
            'visibility' => 'restricted',
        ], $collegueEntetes)->assertForbidden();
    }

    public function test_un_owner_du_projet_change_la_visibilite(): void
    {
        [$auteur, , $projet] = $this->projetAvecMembre();
        $entree = $this->entree($projet, $auteur);

        [$patron, $patronEntetes] = $this->authenticate();
        $this->rattacher($projet, $patron, 'owner');

        $this->patchJson("/api/vault/{$entree->id}", [
            'visibility' => 'restricted',
        ], $patronEntetes)->assertOk();

        $this->assertSame('restricted', $entree->fresh()->visibility);
    }

    // ── Le journal ──────────────────────────────────────────────────────

    public function test_chaque_revelation_laisse_une_trace(): void
    {
        [$user, $entetes, $projet] = $this->projetAvecMembre();
        $entree = $this->entree($projet, $user);

        $this->getJson("/api/vault/{$entree->id}/reveal", $entetes)->assertOk();
        $this->getJson("/api/vault/{$entree->id}/reveal", $entetes)->assertOk();

        // Deux révélations, deux lignes : un journal qui dédoublonne ne
        // répondrait plus à « combien de fois ce mot de passe est-il sorti ? »
        $this->assertSame(2, DB::table('vault_access_logs')
            ->where('entry_id', $entree->id)
            ->where('action', 'revealed')
            ->count());
    }

    public function test_la_consultation_se_distingue_de_la_revelation(): void
    {
        [$user, $entetes, $projet] = $this->projetAvecMembre();
        $entree = $this->entree($projet, $user);

        $this->getJson("/api/vault/{$entree->id}", $entetes)->assertOk();

        // Ouvrir la fiche n'expose pas le secret ; les confondre au journal
        // ferait passer une consultation anodine pour une fuite.
        $actions = DB::table('vault_access_logs')
            ->where('entry_id', $entree->id)
            ->pluck('action')
            ->all();

        $this->assertSame(['viewed'], $actions);
    }

    public function test_un_acces_refuse_ne_laisse_pas_de_trace_d_acces(): void
    {
        [$auteur, , $projet] = $this->projetAvecMembre();
        $entree = $this->entree($projet, $auteur, ['visibility' => 'restricted']);

        [$collegue, $collegueEntetes] = $this->authenticate();
        $this->rattacher($projet, $collegue);

        $this->getJson("/api/vault/{$entree->id}/reveal", $collegueEntetes)
            ->assertForbidden();

        // Une tentative repoussée n'est pas un accès. L'inscrire comme tel
        // ferait lire au journal l'inverse de ce qui s'est passé.
        $this->assertSame(0, DB::table('vault_access_logs')
            ->where('entry_id', $entree->id)
            ->count());
    }

    public function test_le_journal_n_est_lisible_que_par_qui_a_acces(): void
    {
        [$auteur, , $projet] = $this->projetAvecMembre();
        $entree = $this->entree($projet, $auteur, ['visibility' => 'restricted']);

        [$collegue, $collegueEntetes] = $this->authenticate();
        $this->rattacher($projet, $collegue);

        // Qui a consulté quoi et quand est une information sur les personnes
        // autant que sur le secret.
        $this->getJson("/api/vault/{$entree->id}/log", $collegueEntetes)
            ->assertForbidden();
    }

    // ── Sans jeton ──────────────────────────────────────────────────────

    public function test_tout_le_coffre_exige_une_authentification(): void
    {
        [$user, , $projet] = $this->projetAvecMembre();
        $entree = $this->entree($projet, $user);

        $this->getJson("/api/projects/{$projet->id}/vault")->assertUnauthorized();
        $this->getJson("/api/vault/{$entree->id}")->assertUnauthorized();
        $this->getJson("/api/vault/{$entree->id}/reveal")->assertUnauthorized();
        $this->getJson("/api/vault/{$entree->id}/log")->assertUnauthorized();
    }
}
