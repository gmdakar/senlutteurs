<?php

namespace Drupal\elo_ranking\Commands;

use Drush\Attributes\Command;
use Drush\Attributes\Aliases;
use Drush\Attributes\Usage;
use Drush\Commands\DrushCommands;
use Drupal\elo_ranking\Service\EloManager;
use Symfony\Component\Console\Style\SymfonyStyle;
use Drupal\misc_divers\Commands\CommandsBase;

/**
 * Gère les commandes Drush pour le système de classement ELO.
 */
final class EloCommands extends CommandsBase {
  /**
   * Injection de dépendances via la promotion de propriété (PHP 8.0+).
   * L'utilisation de 'protected' ici déclare et assigne la propriété automatiquement.
   */
  public function __construct(
    protected readonly EloManager $eloManager,
  ) {
    parent::__construct();
  }

  /**
   * Recalcule tout le classement ELO des combats de lutte.
   */
  #[Command(name: 'elo:rebuild', aliases: ['er', 'elorecalc'])]
  #[Usage(name: 'drush er', description: 'Met à jour tous les scores ELO.')]
  public function rebuild(): void {

    //check granularity authorization cron execution
    if (!$this->isScheduledNow('elo:rebuild')) {
      return; 
    }

    // SymfonyStyle offre une UX bien plus propre (titres, succès, barres de progression)
    $io = new SymfonyStyle($this->input(), $this->output());
    
    $io->title('Système de Classement ELO : senlutteurs.com');
    $io->info('Lancement du recalcul global...');
    
    $start = microtime(TRUE);
    
    // On passe l'output pour permettre au manager d'afficher une ProgressBar
    $this->eloManager->rebuildAll($io);
    
    $duration = round(microtime(TRUE) - $start, 2);
    
    $io->success("Rebuild terminé avec succès en $duration secondes.");
  }

  /**
   * Remet à NULL les valeurs de field_elo_ranking déjà en base qui
   * dépassent la limite d'affichage (100 par défaut). À lancer une fois
   * après la mise en place du plafond dans syncEloToNodes(), pour nettoyer
   * les valeurs écrites avant ce correctif.
   */
  #[Command(name: 'elo:reset-ranking-limit', aliases: ['erl'])]
  #[Usage(name: 'drush erl', description: 'Vide field_elo_ranking pour les rangs > 100 déjà en base.')]
  public function resetRankingLimit(): void {
    $io = new SymfonyStyle($this->input(), $this->output());

    $io->title('Nettoyage du champ Rang ELO (> 100)');

    $count = $this->eloManager->resetRankingOverLimit(100);

    if ($count === 0) {
      $io->info('Aucun rang à nettoyer, rien à faire.');
      return;
    }

    $io->success("$count athlète(s) mis à jour : field_elo_ranking vidé pour les rangs > 100.");
  }
}