<?php

namespace App\Modules\Tasks\Support;

/**
 * Les mentions dans un commentaire.
 *
 * ## Le format, et pourquoi pas simplement « @Daniel »
 *
 *     @[Daniel](8c1f…-uuid)
 *
 * Le texte brut serait plus simple à écrire et impossible à tenir. Deux Daniel
 * dans une équipe, et la notification part chez le mauvais. Quelqu'un change
 * son nom d'affichage, et toutes ses mentions passées deviennent orphelines.
 * Un nom n'identifie personne.
 *
 * L'identifiant est donc la vérité, et le nom un instantané pour la lecture —
 * ce qui a un effet secondaire souhaitable : un commentaire de l'an dernier
 * continue d'afficher le nom qu'avait la personne à l'époque, comme une
 * citation.
 *
 * La syntaxe reprend celle d'un lien Markdown. Ce n'est pas décoratif : elle
 * est déjà familière, et un client qui ne saurait pas l'interpréter afficherait
 * quand même quelque chose de lisible.
 */
class Mentions
{
    /**
     * `@[` nom sans crochets `](` identifiant `)`.
     *
     * L'identifiant n'est pas contraint à la forme d'un UUID : la validation
     * de l'appartenance au projet s'en charge, et une regex plus stricte
     * rejetterait silencieusement ce qu'elle ne comprend pas.
     */
    private const MOTIF = '/@\[([^\]]{1,80})\]\(([A-Za-z0-9-]{1,64})\)/';

    /**
     * Les identifiants mentionnés, dédupliqués, dans l'ordre d'apparition.
     *
     * Mentionner deux fois la même personne dans un commentaire est courant —
     * on la nomme puis on la renomme — et ne doit produire qu'une notification.
     *
     * @return array<int, string>
     */
    public static function ids(string $contenu): array
    {
        preg_match_all(self::MOTIF, $contenu, $trouves);

        return array_values(array_unique($trouves[2] ?? []));
    }

    /**
     * Le texte tel qu'on le lirait, sans le balisage.
     *
     * Sert au corps des notifications : « @[Daniel](8c1f…) peux-tu voir ça ? »
     * n'est pas ce qu'on veut lire sur un écran verrouillé.
     */
    public static function enClair(string $contenu): string
    {
        return preg_replace(self::MOTIF, '@$1', $contenu) ?? $contenu;
    }
}
