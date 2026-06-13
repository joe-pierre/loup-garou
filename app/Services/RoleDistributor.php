<?php

namespace App\Services;

use App\Models\Game;
use Illuminate\Support\Collection;

class RoleDistributor
{
    /**
     * Distribue les rôles à une collection de GamePlayers.
     *
     * @param  Collection<int, \App\Models\GamePlayer> $players
     * @return array<int, string>  [player_id => role]
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

        if (($overrides['witch'] ?? 0) > 0) {
            $config['witch'] = $overrides['witch'];
        }

        if (($overrides['hunter'] ?? 0) > 0) {
            $config['hunter'] = $overrides['hunter'];
        }

        $config['villager'] = 'fill';

        return $config;
    }

    /**
     * Calcule le nombre de joueurs par rôle pour un effectif donné.
     *
     * @return array<string, int>  [role => count]
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
     * Résout le nombre de joueurs pour un rôle et un montant donnés.
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
