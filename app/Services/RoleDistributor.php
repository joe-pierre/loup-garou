<?php

namespace App\Services;

use App\Models\Game;
use Illuminate\Support\Collection;

/**
 * Distribue les rôles aux joueurs d'une partie selon la configuration active.
 *
 * Priorité de configuration : $game->settings['roles'] > config/game.php
 * Rôles spéciaux (seer, witch, hunter) : 0 ou 1 max chacun.
 * Villageois ('villager') : toujours en mode 'fill', complète les slots restants — non configurable.
 * Loups ('werewolf') : mode 'auto', calcul via werewolfCount() (table d'overrides ou formule floor(n×0.2)).
 *
 * v1.3+ uniquement : ne pas anticiper white_wolf, cupidon, petite_fille — absents de l'enum DB.
 */
class RoleDistributor
{
    /**
     * Distribue les rôles à une collection de joueurs de manière aléatoire.
     * L'ordre d'attribution est déterminé par shuffle() sur la collection mélangée.
     *
     * @param  Collection<int, \App\Models\GamePlayer> $players Collection des joueurs de la partie
     * @param  Game                                    $game    La partie (pour lire settings['roles'])
     * @return array<int, string>                               [player_id => role]
     */
    public function distribute(Collection $players, Game $game): array
    {
        $total    = $players->count();
        $counts   = $this->computeCounts($total, $game);
        $shuffled = $players->shuffle()->values();

        $assignments = [];
        $offset      = 0;

        foreach ($counts as $role => $count) {
            for ($i = 0; $i < $count; $i++) {
                if (isset($shuffled[$offset])) {
                    $assignments[$shuffled[$offset]->id] = $role;
                    $offset++;
                }
            }
        }

        return $assignments;
    }

    /**
     * Retourne la configuration des rôles : config/game.php en base,
     * surchargée par $game->settings['roles'] (witch/hunter, v1.2).
     * villager='fill' reste toujours en dernière position.
     *
     * @return array<string, int|string>  [role => count|'auto'|'fill']
     */
    public function getRoleConfig(Game $game): array
    {
        $overrides = $game->settings['roles'] ?? [];

        $config = [
            'seer'     => config('game.roles.seer', 1),
            'werewolf' => config('game.roles.werewolf', 'auto'),
        ];

        $witchAmount  = $overrides['witch']  ?? config('game.roles.witch', 0);
        $hunterAmount = $overrides['hunter'] ?? config('game.roles.hunter', 0);

        if ($witchAmount > 0) {
            $config['witch'] = $witchAmount;
        }

        if ($hunterAmount > 0) {
            $config['hunter'] = $hunterAmount;
        }

        $config['villager'] = 'fill';

        return $config;
    }

    /**
     * Calcule le nombre de joueurs par rôle pour un effectif donné.
     * Deux passes : d'abord les rôles à compte fixe ou 'auto', puis les rôles 'fill' (villager).
     * Garantit que le nombre de villageois est toujours positif ou nul (max(0, slots restants)).
     *
     * @param  int  $total Nombre total de joueurs
     * @param  Game $game  La partie (pour lire la configuration des rôles)
     * @return array<string, int> [role => nombre_de_joueurs]
     */
    private function computeCounts(int $total, Game $game): array
    {
        $config   = $this->getRoleConfig($game);
        $resolved = [];
        $usedSlots = 0;

        // Première passe : résoudre les rôles à compte fixe ou automatique
        foreach ($config as $role => $amount) {
            if ($amount === 'fill') {
                continue;
            }
            $count           = $this->resolveAmount($role, $amount, $total);
            $resolved[$role] = $count;
            $usedSlots      += $count;
        }

        // Deuxième passe : attribuer les slots restants aux rôles 'fill'
        foreach ($config as $role => $amount) {
            if ($amount === 'fill') {
                $resolved[$role] = max(0, $total - $usedSlots);
            }
        }

        return $resolved;
    }

    /**
     * Résout le nombre de joueurs pour un rôle selon son mode de configuration.
     * Modes : entier (count fixe), 'auto' (calcul par effectif via werewolfCount()), autre → 0.
     *
     * @param  string     $role   Nom du rôle
     * @param  int|string $amount Valeur de configuration : entier fixe, 'auto' ou toute autre chaîne → 0
     * @param  int        $total  Nombre total de joueurs (utilisé uniquement pour le mode 'auto')
     * @return int                Nombre de joueurs à attribuer à ce rôle
     */
    private function resolveAmount(string $role, int|string $amount, int $total): int
    {
        if (is_int($amount)) {
            return $amount;
        }

        if ($amount === 'auto') {
            return $this->werewolfCount($total);
        }

        return 0;
    }

    /**
     * Nombre de loups-garous pour un effectif donné.
     * Utilise d'abord la table d'overrides (config/game.php),
     * puis la formule floor(n * 0.2) min 1 comme fallback pour les effectifs custom (v1.2).
     */
    private function werewolfCount(int $playerCount): int
    {
        $overrides = config('game.werewolf_count_overrides', []);

        if (isset($overrides[$playerCount])) {
            return $overrides[$playerCount];
        }

        return max(1, (int) floor($playerCount * 0.2));
    }
}
