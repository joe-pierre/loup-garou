#!/bin/bash
# ============================================================
# Orchestrateur d'agents IA — Loup-Garou Undu
# Usage : ./z_agents/orchestrate.sh [commande] [cible]
#
# Commandes :
#   audit   [alpine|timers|controllers|services|jobs|all]
#   detect  [night_sequence|day_vote|websocket|game_service|win_condition]
#   tests   [VoteService|PhaseManager|GameService|PhaseGuard|WitchTest|HunterTest]
#   fix     [chemin/rapport_bug.md]
#   snapshot
# ============================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
OUTPUTS_DIR="$SCRIPT_DIR/outputs"
LOGS_DIR="$SCRIPT_DIR/logs"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

mkdir -p "$OUTPUTS_DIR" "$LOGS_DIR"

log() {
  echo "[$(date '+%H:%M:%S')] $*" | tee -a "$LOGS_DIR/orchestrator_$TIMESTAMP.log"
}

check_prerequisites() {
  if ! command -v claude &> /dev/null; then
    echo "ERREUR : la commande 'claude' n'est pas disponible dans le PATH."
    echo "Installe Claude Code : https://docs.claude.com/claude-code"
    exit 1
  fi

  if [ ! -f "$PROJECT_ROOT/CLAUDE.md" ]; then
    echo "ERREUR : CLAUDE.md non trouvé à la racine du projet."
    echo "Lance ce script depuis la racine : cd $PROJECT_ROOT && ./z_agents/orchestrate.sh"
    exit 1
  fi
}

run_agent() {
  local agent_type="$1"
  local prompt_file="$2"
  local output_file="$3"
  shift 3
  local target_files=("$@")

  log "Lancement agent $agent_type → $output_file"
  log "Fichiers cibles : ${target_files[*]}"

  cd "$PROJECT_ROOT"

  claude --dangerously-skip-permissions \
    -p "$(cat "$SCRIPT_DIR/context/rules_extract.md" && echo "---" && cat "$prompt_file")" \
    -- "${target_files[@]}" \
    > "$output_file" 2>> "$LOGS_DIR/${agent_type}_$TIMESTAMP.log"

  local exit_code=$?

  if [ $exit_code -ne 0 ]; then
    log "ÉCHEC agent $agent_type (exit code: $exit_code)"
    log "Voir les logs : $LOGS_DIR/${agent_type}_$TIMESTAMP.log"
    return $exit_code
  fi

  if [ ! -s "$output_file" ]; then
    log "AVERTISSEMENT : fichier de sortie vide pour $agent_type"
    return 1
  fi

  local lines
  lines=$(wc -l < "$output_file")
  log "Succès agent $agent_type — $lines lignes produites dans $output_file"
  return 0
}

# ============================================================
# COMMANDES DISPONIBLES
# ============================================================

cmd_audit() {
  local target="${1:-all}"
  log "=== AUDIT DE CONFORMITÉ : $target ==="

  case "$target" in

    # ── Guard _initialized Alpine.js dans toutes les vues de jeu ──
    alpine)
      run_agent "audit_alpine" \
        "$SCRIPT_DIR/prompts/audit_conformity.md" \
        "$OUTPUTS_DIR/${TIMESTAMP}_audit_alpine.md" \
        "resources/views/game/day.blade.php" \
        "resources/views/game/night.blade.php" \
        "resources/views/game/mayor-election.blade.php" \
        "resources/views/game/spectator.blade.php" \
        "resources/js/game-state.js"
      ;;

    # ── Règle $game->timer() : aucun config('game.timers.x') sauvage ──
    timers)
      run_agent "audit_timers" \
        "$SCRIPT_DIR/prompts/audit_conformity.md" \
        "$OUTPUTS_DIR/${TIMESTAMP}_audit_timers.md" \
        "app/Services/TimerCalculator.php" \
        "app/Services/PhaseManager.php" \
        "app/Services/VoteService.php" \
        "app/Services/GameService.php" \
        "app/Jobs/ProcessNightActions.php" \
        "app/Jobs/ProcessSeerTurn.php" \
        "app/Jobs/ProcessWerewolvesTurn.php" \
        "app/Jobs/ProcessWitchTurn.php" \
        "app/Jobs/ProcessHunterTurn.php"
      ;;

    # ── Controllers : valider + appeler Service, rien d'autre ──
    controllers)
      run_agent "audit_controllers" \
        "$SCRIPT_DIR/prompts/audit_conformity.md" \
        "$OUTPUTS_DIR/${TIMESTAMP}_audit_controllers.md" \
        "app/Http/Controllers/Game/VoteController.php" \
        "app/Http/Controllers/Game/ActionController.php" \
        "app/Http/Controllers/Game/GameController.php" \
        "app/Http/Controllers/Game/LobbyController.php" \
        "app/Http/Controllers/Game/ChatController.php"
      ;;

    # ── Services : logique métier, pas dans les Jobs ──
    services)
      run_agent "audit_services" \
        "$SCRIPT_DIR/prompts/audit_conformity.md" \
        "$OUTPUTS_DIR/${TIMESTAMP}_audit_services.md" \
        "app/Services/GameService.php" \
        "app/Services/VoteService.php" \
        "app/Services/PhaseManager.php" \
        "app/Services/PhaseGuard.php" \
        "app/Services/WinConditionChecker.php" \
        "app/Services/ChatService.php"
      ;;

    # ── Jobs : gestion des timers uniquement, guards de round ──
    jobs)
      run_agent "audit_jobs" \
        "$SCRIPT_DIR/prompts/audit_conformity.md" \
        "$OUTPUTS_DIR/${TIMESTAMP}_audit_jobs.md" \
        "app/Jobs/ProcessNightActions.php" \
        "app/Jobs/ProcessNightEnd.php" \
        "app/Jobs/ProcessSeerTurn.php" \
        "app/Jobs/ProcessSeerAutoAction.php" \
        "app/Jobs/ProcessWerewolvesTurn.php" \
        "app/Jobs/ProcessWitchTurn.php" \
        "app/Jobs/ProcessWitchAutoAction.php" \
        "app/Jobs/ProcessHunterTurn.php" \
        "app/Jobs/ProcessHunterAutoAction.php" \
        "app/Jobs/ProcessMayorSuccession.php" \
        "app/Jobs/ProcessDayVote.php" \
        "app/Jobs/ProcessMayorElection.php"
      ;;

    # ── Audit complet en parallèle (lecture seule → safe) ──
    all)
      run_agent "audit_alpine" \
        "$SCRIPT_DIR/prompts/audit_conformity.md" \
        "$OUTPUTS_DIR/${TIMESTAMP}_audit_alpine.md" \
        "resources/views/game/day.blade.php" \
        "resources/views/game/night.blade.php" \
        "resources/views/game/mayor-election.blade.php" \
        "resources/views/game/spectator.blade.php" \
        "resources/js/game-state.js" &

      run_agent "audit_timers" \
        "$SCRIPT_DIR/prompts/audit_conformity.md" \
        "$OUTPUTS_DIR/${TIMESTAMP}_audit_timers.md" \
        "app/Services/TimerCalculator.php" \
        "app/Services/PhaseManager.php" \
        "app/Services/GameService.php" &

      run_agent "audit_controllers" \
        "$SCRIPT_DIR/prompts/audit_conformity.md" \
        "$OUTPUTS_DIR/${TIMESTAMP}_audit_controllers.md" \
        "app/Http/Controllers/Game/VoteController.php" \
        "app/Http/Controllers/Game/ActionController.php" \
        "app/Http/Controllers/Game/GameController.php" &

      run_agent "audit_services" \
        "$SCRIPT_DIR/prompts/audit_conformity.md" \
        "$OUTPUTS_DIR/${TIMESTAMP}_audit_services.md" \
        "app/Services/GameService.php" \
        "app/Services/VoteService.php" \
        "app/Services/PhaseManager.php" \
        "app/Services/PhaseGuard.php" &

      run_agent "audit_jobs" \
        "$SCRIPT_DIR/prompts/audit_conformity.md" \
        "$OUTPUTS_DIR/${TIMESTAMP}_audit_jobs.md" \
        "app/Jobs/ProcessNightActions.php" \
        "app/Jobs/ProcessNightEnd.php" \
        "app/Jobs/ProcessWitchTurn.php" \
        "app/Jobs/ProcessHunterTurn.php" \
        "app/Jobs/ProcessMayorSuccession.php" &

      wait
      log "=== AUDIT COMPLET TERMINÉ ==="
      log "Résultats dans : $OUTPUTS_DIR/"
      ;;

    *)
      echo "Cible audit inconnue : $target"
      echo "Cibles disponibles : alpine | timers | controllers | services | jobs | all"
      exit 1
      ;;
  esac
}

cmd_detect() {
  local target="${1:-}"
  if [ -z "$target" ]; then
    echo "Usage : ./orchestrate.sh detect [zone]"
    echo "Zones disponibles :"
    echo "  night_sequence   — séquence nocturne complète (Seer→Wolves→Witch→Hunter→NightEnd)"
    echo "  day_vote         — résolution du vote de jour + succession maire"
    echo "  websocket        — canaux Echo, double abonnement, guards Alpine"
    echo "  game_service     — GameService (broadcasts dans transactions, race conditions)"
    echo "  win_condition    — WinConditionChecker + conditions de fin de partie"
    exit 1
  fi

  log "=== DÉTECTION DE BUGS : $target ==="

  case "$target" in

    # ── Séquence nocturne complète — zone la plus critique ──
    # Couvre : race conditions jobs, guards round, broadcasts hors transaction,
    # dispatch ProcessNightEnd depuis mauvais endroit (cf. bug 2026-06-15)
    night_sequence)
      run_agent "detect_night" \
        "$SCRIPT_DIR/prompts/detect_bugs.md" \
        "$OUTPUTS_DIR/${TIMESTAMP}_bugs_night_sequence.md" \
        "app/Jobs/ProcessSeerTurn.php" \
        "app/Jobs/ProcessSeerAutoAction.php" \
        "app/Jobs/ProcessWerewolvesTurn.php" \
        "app/Jobs/ProcessNightActions.php" \
        "app/Jobs/ProcessWitchTurn.php" \
        "app/Jobs/ProcessWitchAutoAction.php" \
        "app/Jobs/ProcessNightEnd.php" \
        "app/Jobs/ProcessHunterTurn.php" \
        "app/Jobs/ProcessHunterAutoAction.php" \
        "app/Services/PhaseManager.php"
      ;;

    # ── Vote de jour, succession maire, chasseur mort le jour ──
    day_vote)
      run_agent "detect_day" \
        "$SCRIPT_DIR/prompts/detect_bugs.md" \
        "$OUTPUTS_DIR/${TIMESTAMP}_bugs_day_vote.md" \
        "app/Services/VoteService.php" \
        "app/Jobs/ProcessDayVote.php" \
        "app/Jobs/ProcessMayorSuccession.php" \
        "app/Jobs/ProcessHunterTurn.php" \
        "app/Jobs/ProcessHunterAutoAction.php" \
        "app/Services/WinConditionChecker.php"
      ;;

    # ── Canaux WebSocket, double abonnement Echo, guards Alpine ──
    websocket)
      run_agent "detect_ws" \
        "$SCRIPT_DIR/prompts/detect_bugs.md" \
        "$OUTPUTS_DIR/${TIMESTAMP}_bugs_websocket.md" \
        "resources/js/game-state.js" \
        "resources/js/echo.js" \
        "resources/views/game/day.blade.php" \
        "resources/views/game/night.blade.php" \
        "resources/views/game/mayor-election.blade.php"
      ;;

    # ── GameService : broadcasts dans transactions, joinGame, startGame ──
    # Bugs critiques identifiés dans le rapport 20260618_130553
    game_service)
      run_agent "detect_game_service" \
        "$SCRIPT_DIR/prompts/detect_bugs.md" \
        "$OUTPUTS_DIR/${TIMESTAMP}_bugs_game_service.md" \
        "app/Services/GameService.php" \
        "app/Services/PhaseManager.php" \
        "app/Services/VoteService.php" \
        "app/Services/ChatService.php"
      ;;

    # ── WinConditionChecker + fin de partie ──
    win_condition)
      run_agent "detect_win" \
        "$SCRIPT_DIR/prompts/detect_bugs.md" \
        "$OUTPUTS_DIR/${TIMESTAMP}_bugs_win_condition.md" \
        "app/Services/WinConditionChecker.php" \
        "app/Services/GameService.php" \
        "app/Jobs/ProcessNightActions.php" \
        "app/Jobs/ProcessDayVote.php"
      ;;

    *)
      echo "Zone inconnue : $target"
      echo "Zones disponibles : night_sequence | day_vote | websocket | game_service | win_condition"
      exit 1
      ;;
  esac
}

cmd_tests() {
  local target="${1:-}"
  if [ -z "$target" ]; then
    echo "Usage : ./orchestrate.sh tests [service]"
    echo "Services disponibles :"
    echo "  VoteService    — tests manquants pour VoteService"
    echo "  PhaseManager   — tests manquants pour PhaseManager"
    echo "  GameService    — tests manquants pour GameService"
    echo "  PhaseGuard     — tests manquants pour PhaseGuard"
    echo "  WitchTest      — couverture sorcière (ProcessWitchTurn + ProcessWitchAutoAction)"
    echo "  HunterTest     — couverture chasseur (ProcessHunterTurn + ProcessHunterAutoAction)"
    exit 1
  fi

  local source_file
  local test_file

  case "$target" in
    VoteService)
      source_file="app/Services/VoteService.php"
      test_file="tests/Feature/Game/DayPhaseTest.php"
      ;;
    PhaseManager)
      source_file="app/Services/PhaseManager.php"
      test_file="tests/Feature/Game/NightPhaseTest.php"
      ;;
    PhaseGuard)
      source_file="app/Services/PhaseGuard.php"
      test_file="tests/Unit/Services/PhaseGuardTest.php"
      ;;
    GameService)
      source_file="app/Services/GameService.php"
      test_file="tests/Feature/Game/CreateGameTest.php"
      ;;
    # ── Cibles spéciales : source = Job, test = fichier dédié ──
    WitchTest)
      source_file="app/Jobs/ProcessWitchTurn.php"
      test_file="tests/Feature/Game/WitchTest.php"
      ;;
    HunterTest)
      source_file="app/Jobs/ProcessHunterTurn.php"
      test_file="tests/Feature/Game/HunterTest.php"
      ;;
    *)
      echo "Service inconnu : $target"
      echo "Disponibles : VoteService | PhaseManager | GameService | PhaseGuard | WitchTest | HunterTest"
      exit 1
      ;;
  esac

  if [ ! -f "$source_file" ]; then
    echo "ERREUR : $source_file n'existe pas"
    exit 1
  fi

  log "=== GÉNÉRATION DE TESTS : $target ==="
  log "Source    : $source_file"
  log "Tests ref : $test_file"

  run_agent "tests_$target" \
    "$SCRIPT_DIR/prompts/generate_tests.md" \
    "$OUTPUTS_DIR/${TIMESTAMP}_tests_missing_${target}.md" \
    "$source_file" \
    "$test_file"

  log "IMPORTANT : Lis le rapport avant d'appliquer les tests générés."
  log "Vérifie avec : php artisan test"
}

cmd_fix() {
  local bug_report="${1:-}"
  if [ -z "$bug_report" ] || [ ! -f "$bug_report" ]; then
    echo "Usage : ./orchestrate.sh fix [chemin_vers_rapport_de_bug]"
    echo "Le rapport doit être produit par un agent 'detect' préalable."
    echo ""
    echo "⚠️  AVANT DE LANCER : git commit -am 'snapshot avant fix'"
    echo "⚠️  Lis toujours la proposition avant de l'appliquer."
    exit 1
  fi

  log "=== RÉSOLUTION DE BUG ==="
  log "Rapport source : $bug_report"
  log "ATTENTION : Cet agent va proposer des modifications de fichiers."
  log "Tu devras lire et valider chaque modification avant de la committer."

  # Extraction des fichiers cibles depuis la section "## Fichiers concernés"
  local target_files
  target_files=$(grep -A 30 "## Fichiers concernés" "$bug_report" | grep "^- " | sed 's/^- //' | head -20)

  if [ -z "$target_files" ]; then
    log "ERREUR : impossible d'extraire les fichiers concernés depuis le rapport."
    log "Le rapport doit contenir une section '## Fichiers concernés' avec une liste."
    exit 1
  fi

  log "Fichiers identifiés dans le rapport :"
  echo "$target_files" | while read -r f; do log "  - $f"; done

  local files_array
  mapfile -t files_array <<< "$target_files"

  run_agent "fix" \
    "$SCRIPT_DIR/prompts/resolve_bug.md" \
    "$OUTPUTS_DIR/${TIMESTAMP}_fix_proposal.md" \
    "$bug_report" \
    "${files_array[@]}"

  log "Proposition de correction dans : $OUTPUTS_DIR/${TIMESTAMP}_fix_proposal.md"
  log "Lis ce fichier AVANT d'appliquer quoi que ce soit."
}

cmd_snapshot() {
  log "=== GÉNÉRATION DU SNAPSHOT CODE ==="

  if [ ! -f "$PROJECT_ROOT/z_tools/code_snapshot.sh" ]; then
    log "ERREUR : script code_snapshot.sh introuvable dans z_tools/"
    exit 1
  fi

  cd "$PROJECT_ROOT"
  bash ./z_tools/code_snapshot.sh

  if [ -f "$PROJECT_ROOT/CODE_SNAPSHOT.md" ]; then
    local lines
    lines=$(wc -l < "$PROJECT_ROOT/CODE_SNAPSHOT.md")
    log "✅ CODE_SNAPSHOT.md régénéré ($lines lignes)"
  else
    log "❌ ÉCHEC : CODE_SNAPSHOT.md non généré"
    exit 1
  fi
}

# ============================================================
# POINT D'ENTRÉE
# ============================================================

check_prerequisites

case "${1:-help}" in
  audit)    cmd_audit "${2:-all}" ;;
  detect)   cmd_detect "${2:-}" ;;
  tests)    cmd_tests "${2:-}" ;;
  fix)      cmd_fix "${2:-}" ;;
  snapshot) cmd_snapshot ;;
  help|*)
    echo ""
    echo "Usage : ./z_agents/orchestrate.sh [commande] [cible]"
    echo ""
    echo "Commandes :"
    echo "  audit   [cible]   Audit de conformité (lecture seule)"
    echo "            alpine | timers | controllers | services | jobs | all"
    echo ""
    echo "  detect  [zone]    Détection de bugs (lecture seule)"
    echo "            night_sequence | day_vote | websocket | game_service | win_condition"
    echo ""
    echo "  tests   [service] Tests manquants (lecture seule)"
    echo "            VoteService | PhaseManager | GameService | PhaseGuard | WitchTest | HunterTest"
    echo ""
    echo "  fix     [rapport] Proposition de correction (⚠️ lire avant d'appliquer)"
    echo "            chemin vers un rapport produit par 'detect'"
    echo ""
    echo "  snapshot          Régénère CODE_SNAPSHOT.md"
    echo ""
    echo "Exemples :"
    echo "  ./z_agents/orchestrate.sh audit all"
    echo "  ./z_agents/orchestrate.sh audit alpine"
    echo "  ./z_agents/orchestrate.sh detect night_sequence"
    echo "  ./z_agents/orchestrate.sh detect game_service"
    echo "  ./z_agents/orchestrate.sh tests VoteService"
    echo "  ./z_agents/orchestrate.sh fix z_agents/outputs/20260618_130553_bugs_night_sequence.md"
    echo "  ./z_agents/orchestrate.sh snapshot"
    echo ""
    ;;
esac
