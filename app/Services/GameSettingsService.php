<?php

namespace App\Services;

use App\Models\Game;

class GameSettingsService
{
    /**
     * Valide un tableau de timers contre les limites configurées dans config/game.php (timers.limits).
     * Vérifie que chaque clé est configurable (host_configurable = true) et dans la plage min/max.
     *
     * @param  array<string, int> $timers Tableau [nom_timer => secondes] à valider
     * @return void
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException (422) si un timer est non configurable ou hors plage
     */
    public function validateTimerSettings(array $timers): void
    {
        $limits = config('game.timers.limits');

        foreach ($timers as $key => $value) {
            if (! isset($limits[$key]) || $limits[$key]['host_configurable'] === false) {
                abort(422, "Le timer '{$key}' n'est pas configurable.");
            }

            if (! is_int($value) || $value < $limits[$key]['min'] || $value > $limits[$key]['max']) {
                abort(422, "Le timer '{$key}' doit être compris entre {$limits[$key]['min']} et {$limits[$key]['max']} secondes.");
            }
        }
    }

    /**
     * Fusionne les nouveaux timers dans game->settings['timers'] et persiste.
     * TimerCalculator::get() lit settings['timers'] en priorité sur config() pour toutes les clés configurables.
     *
     * @param  Game               $game   La partie concernée (statut 'waiting' requis)
     * @param  array<string, int> $timers Tableau [nom_timer => secondes] déjà validé
     * @return Game                        La partie avec settings mis à jour
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException (409) si la partie n'est plus en 'waiting'
     */
    public function updateTimerSettings(Game $game, array $timers): Game
    {
        if ($game->status !== 'waiting') {
            abort(409, 'Les timers ne peuvent être modifiés que dans la salle d\'attente.');
        }

        $this->validateTimerSettings($timers);

        $currentSettings = $game->settings ?? [];
        $currentSettings['timers'] = array_merge($currentSettings['timers'] ?? [], $timers);

        $game->update(['settings' => $currentSettings]);

        return $game;
    }

    /**
     * Valide un tableau de rôles : seuls 'witch' et 'hunter' sont configurables, chacun valant 0 ou 1.
     *
     * @param  array<string, int> $roles Tableau [nom_role => 0|1] à valider
     * @return void
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException (422) si un rôle est inconnu ou a une valeur invalide
     */
    public function validateRoleSettings(array $roles): void
    {
        foreach (['witch', 'hunter'] as $key) {
            if (array_key_exists($key, $roles) && ! in_array($roles[$key], [0, 1], true)) {
                abort(422, "Le rôle '{$key}' doit être 0 ou 1.");
            }
        }

        foreach (array_keys($roles) as $key) {
            if (! in_array($key, ['witch', 'hunter'], true)) {
                abort(422, "Le rôle '{$key}' n'est pas configurable.");
            }
        }
    }

    /**
     * Fusionne la composition de rôles dans game->settings['roles'] et persiste.
     * RoleDistributor lit settings['roles'] en priorité sur config/game.php au démarrage.
     *
     * @param  Game               $game  La partie concernée (statut 'waiting' requis)
     * @param  array<string, int> $roles Tableau [nom_role => 0|1] déjà validé
     * @return Game                       La partie avec settings mis à jour
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException (409) si la partie n'est plus en 'waiting'
     */
    public function updateRoleSettings(Game $game, array $roles): Game
    {
        if ($game->status !== 'waiting') {
            abort(409, 'La composition des rôles ne peut être modifiée que dans la salle d\'attente.');
        }

        $this->validateRoleSettings($roles);

        $currentSettings = $game->settings ?? [];
        $currentSettings['roles'] = array_merge($currentSettings['roles'] ?? [], $roles);

        $game->update(['settings' => $currentSettings]);

        return $game;
    }
}
