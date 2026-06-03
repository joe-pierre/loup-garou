<?php

namespace App\Services;

use Illuminate\Support\Collection;

class RoleDistributor
{
    /**
     * Distribue les rôles à une collection de GamePlayers.
     *
     * @param  Collection<int, \App\Models\GamePlayer> $players
     * @return array<int, string>  [player_id => role]
     */
    public function distribute(Collection $players): array
    {
        $total    = $players->count();
        $counts   = $this->computeCounts($total);
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
     * Retourne la configuration des rôles depuis config/game.php.
     * Surcharger cette méthode en v1.2 pour ajouter de nouveaux rôles.
     *
     * @return array<string, int|string>  [role => count|'auto'|'fill']
     */
    public function getRoleConfig(): array
    {
        return config('game.roles', [
            'seer'     => 1,
            'werewolf' => 'auto',
            'villager' => 'fill',
        ]);
    }

    /**
     * Calcule le nombre de joueurs par rôle pour un effectif donné.
     *
     * @return array<string, int>  [role => count]
     */
    private function computeCounts(int $total): array
    {
        $config   = $this->getRoleConfig();
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
