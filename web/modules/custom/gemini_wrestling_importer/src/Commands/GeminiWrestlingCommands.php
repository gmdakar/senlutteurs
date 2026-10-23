<?php

namespace Drupal\gemini_wrestling_importer\Commands;

use Drush\Commands\DrushCommands;
use Drush\Attributes\Command;
use Drush\Attributes\Argument;
use Drush\Attributes\Option;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\gemini_wrestling_importer\Service\GeminiWrestlingService;
use Drupal\Core\Queue\RequeueException;
use \Drupal\Core\Queue\QueueWorkerInterface;
use Drupal\misc_divers\Traits\CronScheduleTrait;
use Symfony\Component\Console\Input\InputOption;

/**
 * Drush commands for the Gemini Wrestling Importer module.
 */
class GeminiWrestlingCommands extends DrushCommands
{

  use CronScheduleTrait;

  public function __construct(
    protected QueueWorkerManagerInterface $queueWorkerManager,
    protected QueueFactory $queueFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected GeminiWrestlingService $geminiWrestlingService,
    protected Connection $database
  ) {
    parent::__construct();
  }

  /**
   * Supprime les doublons dans une file d'attente, par NID d'athlète.
   * CLI : ddev drush gemini:queue-remove-duplicates --queue=add_confrontations_athlete_queue
   */
  #[Command(name: 'gemini:queue-remove-duplicates')]
  #[Option(name: 'queue', description: "Le nom de la file d'attente à nettoyer")]
  public function removeDuplicates($options = ['queue' => 'add_confrontations_athlete_queue'])
  {
    $queue_name = $options['queue'];
    $deleted = $this->deduplicateQueueByNid($queue_name);

    if ($deleted === 0) {
      $this->io()->success("Aucun doublon trouvé dans $queue_name.");
      return;
    }

    $this->io()->success("$deleted doublon(s) supprimé(s) dans $queue_name.");
  }

  /**
   * Déduplique une file d'attente par NID (clé métier réelle), et non par
   * la donnée sérialisée complète.
   *
   * IMPORTANT : l'ancienne implémentation groupait par la colonne `data`
   * brute (`GROUP BY data`). Or le payload de add_confrontations_athlete_queue
   * est ['nid' => ..., 'cpt' => ..., 'force' => ...] — si le MÊME athlète
   * (même nid) est mis en queue deux fois à des moments différents avec un
   * 'cpt' différent (le compteur de combats a évolué entre-temps), les deux
   * items sérialisent en des chaînes DIFFÉRENTES et n'étaient donc JAMAIS
   * détectés comme doublons, alors qu'ils correspondent bien au même
   * athlète et provoquent un appel IA en double. On regroupe donc ici par
   * nid réel (après unserialize), et on conserve l'item le plus à jour
   * (le plus grand 'cpt', c'est-à-dire l'information la plus récente sur
   * le nombre de combats déjà connus) plutôt qu'un item arbitraire — pour
   * éviter de faire régresser à tort le compteur en State lors du
   * traitement (voir le garde-fou seuil dans processItem()).
   *
   * @return int Nombre d'items supprimés.
   */
  protected function deduplicateQueueByNid(string $queue_name): int
  {
    $raw_items = $this->database->select('queue', 'q')
      ->fields('q', ['item_id', 'data'])
      ->condition('q.name', $queue_name)
      ->execute()
      ->fetchAll();

    // Regroupe les item_id par nid.
    $byNid = [];
    foreach ($raw_items as $raw) {
      $data = @unserialize($raw->data);
      $nid = is_array($data) ? ($data['nid'] ?? null) : null;
      if ($nid === null) {
        // Payload sans nid exploitable (ne devrait pas arriver pour cette
        // queue) : on l'ignore, on ne touche pas à ce qu'on ne comprend pas.
        continue;
      }
      $cpt = is_array($data) ? (int) ($data['cpt'] ?? 0) : 0;
      $byNid[$nid][] = ['item_id' => (int) $raw->item_id, 'cpt' => $cpt];
    }

    $deleted = 0;
    foreach ($byNid as $nid => $items) {
      if (count($items) < 2) {
        continue;
      }

      // Conserve l'item avec le 'cpt' le plus élevé (info la plus
      // récente) ; à égalité, conserve le plus ancien item_id (créé en
      // premier).
      usort($items, function ($a, $b) {
        if ($a['cpt'] !== $b['cpt']) {
          return $b['cpt'] <=> $a['cpt'];
        }
        return $a['item_id'] <=> $b['item_id'];
      });

      $keepId = $items[0]['item_id'];
      $toDeleteIds = array_column(array_slice($items, 1), 'item_id');

      if (!empty($toDeleteIds)) {
        $count = $this->database->delete('queue')
          ->condition('name', $queue_name)
          ->condition('item_id', $toDeleteIds, 'IN')
          ->execute();

        $deleted += $count;

        $this->output()->writeln("NID $nid : $count doublon(s) supprimé(s), item #$keepId conservé.");
      }
    }

    return $deleted;
  }

  /**
   * Liste le contenu d'une queue (par défaut celle des athlètes).
   * CLI : ddev drush gwql
   * CLI : ddev drush gwql --queue=add_confrontations_athlete_queue
   */
  #[Command(name: 'gemini:queue-list', aliases: ['gwql'])]
  #[Option(name: 'queue', description: "Le nom de la file d'attente à inspecter")]
  public function listQueueContent($options = ['queue' => 'add_confrontations_athlete_queue'])
  {
    $queue_name = $options['queue'];

    $query = $this->database->select('queue', 'q')
      ->fields('q', ['data', 'expire', 'created'])
      ->condition('name', $queue_name)
      ->execute();

    $rows = [];
    $node_storage = $this->entityTypeManager->getStorage('node');

    foreach ($query as $item) {
      $data = unserialize($item->data);
      $nid = $data['nid'] ?? 'N/A';
      $cpt = $data['cpt'] ?? '0';

      $title = 'N/A';
      if ($nid !== 'N/A') {
        $node = $node_storage->load($nid);
        $title = $node ? $node->getTitle() : 'Node introuvable';
      }

      $rows[] = [
        'nid'     => $nid,
        'title'   => $title,
        'status'  => ($item->expire > 0) ? 'Bloqué (Claimed)' : 'En attente',
        'created' => date('Y-m-d H:i:s', $item->created),
        'cpt'     => $cpt,
      ];
    }

    if (empty($rows)) {
      $this->writeln("La queue <info>$queue_name</info> est vide.");
    } else {
      $this->writeln("Contenu de la queue : <info>$queue_name</info>");
      $this->io()->table(['NID', 'Titre', 'Statut', 'Créé le', 'Cpt'], $rows);
    }
  }

  /**
   * Import fights for a single wrestler immediately.
   * CLI : ddev drush -y gwoff "Ness 2" --engine=grok
   */
  #[Command(name: 'gemini:import-one-fighter-fights', aliases: ['gwoff'])]
  #[Argument(name: 'wrestler_name', description: 'Name of the wrestler')]
  #[Option(name: 'engine', description: 'The engine to use')]
  public function importFights(string $wrestler_name, string $engine = 'gemini')
  {
    // Votre logique ici, utilisant la variable $engine

    if (!$this->isScheduledNow('gemini:import-one-fighter-fights')) {
      $this->writeln("Execution not scheduled for now. Skipping.");
      return DrushCommands::EXIT_SUCCESS;
    }

    $this->writeln("Starting import for wrestler: $wrestler_name");
    $config = \Drupal::config('gemini_wrestling_importer.settings');
    $mode   = $config->get('wrestling_importer_use_diff') ? 'diff' : 'normal';
    $engine  = $engine ?? $config->get('wrestling_importer_use_llm') ?? 'grok';
    $this->io()->note("Utilisation du moteur : " . $engine);

    $result = $this->geminiWrestlingService->getFightsFromAiRequest($wrestler_name, $engine, $mode);

    if (!$result['success']) {
      $error = $result['error'] ?? 'unknown';

      $message = match ($error) {
        'gemini_quota_exhausted'         => 'KO : Quota Gemini epuise. Reessai apres : ' . ($result['retry_at'] ?? 'minuit'),
        'gemini_keys_expired'            => 'KO : Cle(s) API Gemini expiree(s) — verifier la configuration.',
        'gemini_service_unavailable'     => 'KO : Service Gemini indisponible (503) — reessayer plus tard.',
        'quota_exhausted_until_midnight' => 'KO : Quota deja epuise. Reessai apres : ' . ($result['retry_at'] ?? 'minuit'),
        'skipped'                        => 'OK : Deja traite — ' . ($result['message'] ?? 'ignore.'),  // ← message optionnel
        default                          => 'KO : Erreur Gemini — ' . $error,
      };

      $this->output()->writeln("<error>$message</error>");

      // On ne leve une exception bloquante que si c'est une erreur temporaire
      // (503, quota) pour stopper un batch. Les cles expirees ne sont pas
      // recuperables par un retry immediat : on loggue et on continue.
      if (in_array($error, ['gemini_service_unavailable', 'gemini_quota_exhausted', 'quota_exhausted_until_midnight'])) {
        throw new \Exception($error);
      }

      return DrushCommands::EXIT_FAILURE;
    }

    $fights = $result['fights'];

    if (empty($fights)) {
      $this->output()->writeln("<info>OK : Aucun combat trouve pour $wrestler_name.</info>");
      return DrushCommands::EXIT_SUCCESS;
    }

    $this->geminiWrestlingService->importFights($fights, $wrestler_name);
    $this->output()->writeln("<info>OK : Importation terminee pour $wrestler_name.</info>");

    return DrushCommands::EXIT_SUCCESS;
  }

  /**
   * Import fights for all wrestlers tagged as LESS using Queue API.
   * CLI : ddev drush -y gwfal
   * CLI : ddev drush -y gwfal --step --engine=grok
   */
  #[Command(name: 'gemini:import-all-fights-athlete-less', aliases: ['gwfal'])]
  #[Option(name: 'step', description: 'Si activé, traite un seul athlète par exécution.')]
  #[Option(name: 'engine', description: 'Le moteur IA à utiliser (grok, gemini, etc.)')]
  public function importAllFightsAthleteLess(bool $step = false, string $engine = 'grok')
  {

    $config = \Drupal::config('gemini_wrestling_importer.settings');
    $mode   = $config->get('wrestling_importer_use_diff') ? 'diff' : 'normal';
    $engine  = $engine ?? $config->get('wrestling_importer_use_llm') ?? 'grok';

    $this->io()->note("Moteur sélectionné : " . $engine);

    // Vérification de la planification (Custom logic isScheduledNow)
    if (!$this->isScheduledNow('gemini:import-all-fights-athlete-less')) {
      $this->writeln("Exécution non planifiée pour le moment. Annulation.");
      return DrushCommands::EXIT_SUCCESS;
    }

    set_time_limit(0);
    $queue_name = 'add_confrontations_athlete_queue';
    $queue = $this->queueFactory->get($queue_name);
    $worker = $this->queueWorkerManager->createInstance($queue_name);
    $node_storage = $this->entityTypeManager->getStorage('node');

    // -------------------------------------------------------------------------
    // ÉTAPE 1 : Nettoyage des items orphelins (nodes supprimés entre temps)
    // -------------------------------------------------------------------------
    $this->output()->writeln("<info>Vérification des items orphelins dans la queue $queue_name...</info>");

    $orphan_count = 0;
    $claimed_for_cleanup = [];

    // On libère d'abord les items expirés pour pouvoir tous les inspecter
    \Drupal::database()->update('queue')
      ->fields(['expire' => 0])
      ->condition('name', $queue_name)
      ->condition('expire', 0, '>')
      ->execute();

    // Lecture de tous les items via la DB directement pour ne pas consommer la queue
    $raw_items = \Drupal::database()->select('queue', 'q')
      ->fields('q', ['item_id', 'data'])
      ->condition('q.name', $queue_name)
      ->execute()
      ->fetchAll();

    foreach ($raw_items as $raw) {
      $data = unserialize($raw->data);
      $nid = $data['nid'] ?? null;

      if (!$nid) {
        continue;
      }

      // Vérification d'existence du node sans le charger entièrement
      $exists = $node_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('nid', $nid)
        ->count()
        ->execute();

      if ((int) $exists === 0) {
        // Suppression directe en DB par item_id
        \Drupal::database()->delete('queue')
          ->condition('item_id', $raw->item_id)
          ->execute();

        $orphan_count++;
        $this->output()->writeln("<comment>Node #$nid introuvable : item #{$raw->item_id} supprimé de la queue.</comment>");
      }
    }

    if ($orphan_count > 0) {
      $this->output()->writeln("<info>$orphan_count item(s) orphelin(s) supprimé(s).</info>");
    } else {
      $this->output()->writeln("<info>Aucun item orphelin détecté.</info>");
    }

    // -------------------------------------------------------------------------
    // ÉTAPE 1bis : Déduplication par NID (voir deduplicateQueueByNid()) —
    // un même athlète a pu être mis en queue plusieurs fois (cycles cron
    // successifs, ou déclenchement manuel via field_search_combats) avec
    // des 'cpt' différents. On ne garde qu'un seul item par athlète, le
    // plus à jour, AVANT de commencer à consommer la queue.
    // -------------------------------------------------------------------------
    $dedup_count = $this->deduplicateQueueByNid($queue_name);
    if ($dedup_count > 0) {
      $this->output()->writeln("<info>$dedup_count doublon(s) supprimé(s) de la queue $queue_name avant traitement.</info>");
    }

    // -------------------------------------------------------------------------
    // ÉTAPE 2 : Traitement normal des items restants
    // -------------------------------------------------------------------------
    while ($item = $queue->claimItem()) {
      $data = $item->data;
      $nid = $data['nid'] ?? null;
      $cpt = $data['cpt'] ?? 0;

      if ($nid) {
        $node = $node_storage->load($nid);

        // SÉCURITÉ : On vérifie si le node est passé en "field_not_check" entre temps
        if ($node && $node->hasField('field_not_check') && (int) $node->get('field_not_check')->value === 1) {
          $queue->deleteItem($item);
          $this->output()->writeln("Node #$nid : 'field_not_check' est actif. Item supprimé.");
          continue;
        }

        $title = $node ? $node->getTitle() : "Node #$nid";
        $this->output()->write("Traitement : $title (cpt=$cpt) ... ");

        try {
          // Injection de l'engine dans les datas si nécessaire pour le worker
          $data['engine'] = $engine;

          // Le worker processItem gère les tests de compteurs et l'import
          $worker->processItem($data);

          $queue->deleteItem($item);
          $this->output()->writeln("<info>Terminé.</info>");

          \Drupal::logger('gemini_wrestling_importer')->info(
            'GetFights gwfal : Athlète @nid (@title) traité avec succès.',
            ['@nid' => $nid, '@title' => $title]
          );
        } catch (\Drupal\gemini_wrestling_importer\Exception\ThresholdNotReachedException $e) {
          // Seuil non atteint : on supprime proprement, pas un succès, pas une erreur
          $queue->deleteItem($item);
          $this->output()->writeln("<comment>Ignoré (seuil non atteint : {$e->getMessage()}).</comment>");
          \Drupal::logger('gemini_wrestling_importer')->warning(
            'GetFights gwfal : Traitement queue Athlète @nid (@title) ignoré (seuil non atteint :' . $e->getMessage() . ')',
            ['@nid' => $nid, '@title' => $title]
          );
        } catch (RequeueException $e) {
          $queue->releaseItem($item);
          $this->output()->writeln("<error>Échec API (Requeue).</error>");
          // On stoppe tout le batch si l'API est HS pour ne pas brûler les quotas
          throw new \Exception("Arrêt du batch : L'API ne répond plus.");
        } catch (\Exception $e) {
          $queue->releaseItem($item);
          $this->output()->writeln("<error>Erreur : " . $e->getMessage() . "</error>");
          \Drupal::logger('gemini_wrestling_importer')->error(
            'gwfal : Erreur sur @nid — @message',
            ['@nid' => $nid, '@message' => $e->getMessage()]
          );
          throw $e;
        }
      }

      if ($step) {
        $this->io()->info("Mode 'step' : arrêt après un athlète.");
        break;
      }
    }

    return DrushCommands::EXIT_SUCCESS;
  }

  /**
   * Import fights for all wrestlers using Queue API.
   * CLI : ddev drush -y gwfai
   * CLI : ddev drush -y gwfai --reset
   * CLI : ddev drush -y gwfai --step
   */
  #[Command(name: 'gemini:import-all-fights', aliases: ['gwfai'])]
  #[Option(name: 'reset', description: 'Empty the queue and start from scratch.')]
  #[Option(name: 'step', description: 'If set, process only 1 athlete per run.')]
  public function importAllFights(bool $reset = false, bool $step = false)
  {
    $queue_name = 'gemini_wrestling_importer_queue';
    $queue = $this->queueFactory->get($queue_name);

    if ($reset) {
      while ($item = $queue->claimItem()) {
        $queue->deleteItem($item);
      }
      $queue->deleteQueue();
      $this->writeln("Queue vidée.");
    }

    if ($queue->numberOfItems() === 0) {
      $nids = $this->entityTypeManager->getStorage('node')
        ->getQuery()
        ->condition('type', 'athlete')
        ->accessCheck(FALSE)
        ->execute();

      foreach ($nids as $nid) {
        $queue->createItem(['nid' => $nid]);
      }
      $this->writeln("Enqueued " . count($nids) . " wrestlers.");
    }

    $worker = $this->queueWorkerManager->createInstance($queue_name);
    $node_storage = $this->entityTypeManager->getStorage('node');

    while ($item = $queue->claimItem()) {
      $data = $item->data;
      $nid = $data['nid'] ?? null;

      if ($nid) {
        $node = $node_storage->load($nid);
        $title = $node ? $node->getTitle() : "Node #$nid";

        $this->output()->write("Traitement : $title ... ");

        try {
          if ($worker->processItem($data)) {
            $queue->deleteItem($item);
            $this->output()->writeln("<info>Done.</info>");
          } else {
            $this->output()->writeln("<error>Failed (API). Relâché.</error>");
            $queue->releaseItem($item);
            throw new \Exception("Batch stopped due to API error.");
          }
        } catch (\Exception $e) {
          $this->output()->writeln("<error>Error: " . $e->getMessage() . "</error>");
          $queue->releaseItem($item);
          throw $e;
        }
      }

      if ($step) break;
    }
  }
}
