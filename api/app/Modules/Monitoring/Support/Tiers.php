<?php

namespace App\Modules\Monitoring\Support;

/**
 * Le franchissement des paliers.
 *
 * Extrait du reste pour être vérifiable sans base ni réseau : c'est la seule
 * partie de la supervision dont une erreur se paie en notifications — trop, ou
 * pas du tout.
 */
class Tiers
{
    /**
     * Le palier le plus grave atteint par [$valeur], ou 0 si aucun.
     *
     * « Le plus grave » dépend du sens. En croissant c'est le plus haut
     * franchi vers le haut ; en décroissant le plus bas franchi vers le bas —
     * tomber sous 20 est pire que tomber sous 100.
     *
     * Zéro veut dire « aucun » dans les deux cas, ce qu'aucun palier réel ne
     * peut valoir : la validation les exige strictement positifs.
     *
     * @param  array<int, int>  $paliers  croissants
     */
    public static function reached(
        int $valeur,
        array $paliers,
        Direction $sens = Direction::Croissant,
    ): int {
        $atteint = 0;

        foreach ($paliers as $palier) {
            if ($sens->isFloor()) {
                if ($valeur <= $palier) {
                    $atteint = $atteint === 0 ? $palier : min($atteint, $palier);
                }

                continue;
            }

            if ($valeur >= $palier) {
                $atteint = max($atteint, $palier);
            }
        }

        return $atteint;
    }

    /**
     * Faut-il signaler, et à quel palier ?
     *
     * Rend le palier à signaler, ou `null` s'il n'y a rien à dire.
     *
     * ## La règle, et ce qu'elle évite
     *
     * On ne signale qu'un palier **strictement plus grave** que le plus grave
     * déjà signalé. C'est ce qui rend une fenêtre glissante utilisable : le
     * compte y redescend quand de vieux événements en sortent, puis repasse le
     * même palier. Sans cette règle, 9 → 10 → 9 → 10 produirait deux
     * notifications pour un seul incident, et une nuit entière en produirait
     * des centaines.
     *
     * On saute aussi les paliers intermédiaires : passer de 3 à 45 d'un coup
     * signale 40, pas 10 puis 20 puis 40. Trois notifications simultanées
     * disent moins qu'une seule qui annonce le bon chiffre.
     *
     * En décroissant, « plus grave » veut dire plus bas — mais la règle est la
     * même mot pour mot : une production qui passe de 90 à 15 signale le
     * plancher 20 sans repasser par 50.
     *
     * @param  array<int, int>  $paliers
     */
    public static function toRaise(
        int $valeur,
        array $paliers,
        int $dejaSignale,
        Direction $sens = Direction::Croissant,
    ): ?int {
        $atteint = self::reached($valeur, $paliers, $sens);

        if ($atteint === 0) {
            return null;
        }

        if ($dejaSignale === 0) {
            return $atteint;
        }

        $plusGrave = $sens->isFloor()
            ? $atteint < $dejaSignale
            : $atteint > $dejaSignale;

        return $plusGrave ? $atteint : null;
    }

    /**
     * Le palier le moins grave de la liste — le premier qu'on franchit.
     *
     * Sert à l'affichage : c'est lui qui distingue l'orange du rouge. En
     * croissant c'est le plus petit, en décroissant le plus grand.
     *
     * @param  array<int, int>  $paliers  croissants
     */
    public static function first(array $paliers, Direction $sens): ?int
    {
        if ($paliers === []) {
            return null;
        }

        return $sens->isFloor() ? max($paliers) : min($paliers);
    }

    /**
     * Le prochain palier à franchir depuis [$valeur], ou null.
     *
     * Dire ce qui reste avant le prochain seuil vaut mieux que de n'annoncer
     * que le franchissement : on voit venir. En décroissant, « le prochain »
     * est le plus haut palier strictement sous la valeur courante.
     *
     * @param  array<int, int>  $paliers  croissants
     */
    public static function next(int $valeur, array $paliers, Direction $sens): ?int
    {
        if ($sens->isFloor()) {
            $candidats = array_filter($paliers, fn (int $p) => $p < $valeur);

            return $candidats === [] ? null : max($candidats);
        }

        foreach ($paliers as $palier) {
            if ($palier > $valeur) {
                return $palier;
            }
        }

        return null;
    }
}
