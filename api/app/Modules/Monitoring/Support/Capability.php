<?php

namespace App\Modules\Monitoring\Support;

/**
 * Les droits accordés au cas par cas.
 *
 * ## Pourquoi une notion nouvelle
 *
 * Arche n'avait jusqu'ici que deux niveaux : membre et administrateur. Cela
 * suffisait tant que tout le monde voyait tout — un projet se protège par
 * l'appartenance, un secret du coffre par sa liste blanche, et le reste est
 * ouvert à l'équipe.
 *
 * La supervision rompt cette règle. Elle donne à voir des bases de production
 * entières : montants, numéros de clients, volumes d'activité. Ce n'est ni du
 * « pour tout le monde » ni du « réservé aux administrateurs » — c'est un droit
 * qui s'accorde à qui en a l'usage.
 *
 * ## Pourquoi une table et non une colonne
 *
 * `users.peut_superviser` aurait suffi aujourd'hui, et il aurait fallu une
 * colonne de plus au droit suivant. Surtout, une colonne ne dit pas **qui** a
 * accordé le droit ni **quand** — deux questions qu'on se pose forcément le
 * jour où quelqu'un voit ce qu'il ne devrait pas.
 */
enum Capability: string
{
    /** Voir la supervision des bases, et acquitter ses incidents. */
    case Monitoring = 'monitoring';

    /** Ajouter une base surveillée et définir ses sondes. */
    case MonitoringAdmin = 'monitoring.admin';

    /**
     * Les droits impliqués par celui-ci.
     *
     * Administrer la supervision sans pouvoir la consulter n'aurait aucun
     * sens ; le dire ici évite d'avoir à accorder les deux à la main, et
     * d'oublier le second.
     *
     * @return array<int, self>
     */
    public function implies(): array
    {
        return match ($this) {
            self::MonitoringAdmin => [self::Monitoring],
            default => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Monitoring => 'Supervision des bases',
            self::MonitoringAdmin => 'Administration de la supervision',
        };
    }
}
