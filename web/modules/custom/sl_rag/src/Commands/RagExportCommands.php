<?php

namespace Drupal\sl_rag\Commands;

use Drupal\sl_rag\Service\RagAskService;
use Drupal\sl_rag\Service\RagEntityTextualizerService;
use Drush\Commands\DrushCommands;

/**
 * Commandes Drush du module sl_rag.
 *
 * - sl-rag:export-entities  → export des fiches
 * - sl-rag:ask              → question/réponse via IA avec logs complets
 */
class RagExportCommands extends DrushCommands
{

  /**
   * Récupère le service de textualisation.
   */
  protected function textualizer(): RagEntityTextualizerService
  {
    return \Drupal::service('sl_rag.entity_textualizer');
  }

  /**
   * Récupère le service de question/réponse.
   */
  protected function askService(): RagAskService
  {
    return \Drupal::service('sl_rag.ask_service');
  }

  /**
   * Exporte les fiches lutteurs en documents textuels + métadonnées.
   *
   * @command sl-rag:export-entities
   * @aliases srag-export, sre
   * @option limit Nombre maximum d'athletes (ex: --limit=3). 0 ou omis = tous.
   * @option nids Liste de NIDs séparés par des virgules.
   * @option recent-combats Combats récents inclus dans le texte (défaut 12).
   * @option output Chemin du fichier de sortie. Si omis → stdout.
   * @option format jsonl (défaut) ou json.
   * @option order nid (défaut) ou title.
   * @bootstrap full
   */
  public function exportEntities(array $options = [
    'limit' => self::REQ,
    'nids' => self::REQ,
    'recent-combats' => 12,
    'output' => self::REQ,
    'format' => 'jsonl',
    'order' => 'nid',
  ]): void
  {
    $limitOpt = $options['limit'] ?? null;
    $nidsOpt = $options['nids'] ?? null;
    $recentCombats = (int) ($options['recent-combats'] ?? 12);
    $outputPath = $options['output'] ?? null;
    $format = strtolower((string) ($options['format'] ?? 'jsonl'));
    $order = (string) ($options['order'] ?? 'nid');

    $limit = null;
    if ($limitOpt !== null && $limitOpt !== '' && $limitOpt !== self::REQ) {
      $limit = (int) $limitOpt;
      if ($limit <= 0) {
        $limit = null;
      }
    }

    $nids = [];
    if ($nidsOpt !== null && $nidsOpt !== '' && $nidsOpt !== self::REQ) {
      foreach (preg_split('/\s*,\s*/', (string) $nidsOpt) as $p) {
        $p = trim($p);
        if ($p !== '' && ctype_digit($p)) {
          $nids[] = (int) $p;
        }
      }
    }

    if (!in_array($format, ['jsonl', 'json'], TRUE)) {
      $this->logger()->error('Format invalide. Utilisez "jsonl" ou "json".');
      return;
    }

    $this->logger()->notice('Démarrage export RAG entities…');
    if ($limit !== null) {
      $this->logger()->notice('Limite active : @limit athlete(s).', ['@limit' => $limit]);
    }

    $documents = $this->textualizer()->exportAthletes([
      'limit'          => $limit,
      'nids'           => $nids,
      'recent_combats' => $recentCombats,
      'order'          => $order,
    ]);

    if (empty($documents)) {
      $this->logger()->warning('Aucun document généré.');
      return;
    }

    $count = count($documents);

    if ($format === 'json') {
      $payload = json_encode($documents, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    else {
      $lines = [];
      foreach ($documents as $doc) {
        $lines[] = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      }
      $payload = implode("\n", $lines) . "\n";
    }

    if ($outputPath && $outputPath !== self::REQ) {
      $dir = dirname($outputPath);
      if (!is_dir($dir) && !mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
        $this->logger()->error('Impossible de créer le répertoire : @dir', ['@dir' => $dir]);
        return;
      }
      if (file_put_contents($outputPath, $payload) === FALSE) {
        $this->logger()->error('Échec d\'écriture : @path', ['@path' => $outputPath]);
        return;
      }
      $this->logger()->success('@count document(s) écrit(s) dans @path', [
        '@count' => $count,
        '@path'  => $outputPath,
      ]);
    }
    else {
      $this->output()->write($payload);
      $this->logger()->success('@count document(s) généré(s) (stdout).', ['@count' => $count]);
    }
  }

  /**
   * Pose une question sur les lutteurs (filtre intelligent + appel IA + logs complets).
   *
   * @param string $question
   *   La question en langage naturel.
   *
   * @command sl-rag:ask
   * @aliases srag-ask, sra
   * @option max-docs Nombre max de fiches lutteurs injectées dans le prompt (défaut 30).
   * @option dry-run Si présent, construit le prompt mais n'appelle pas l'IA.
   * @usage drush sl-rag:ask "Qui sont les lutteurs invaincus ?"
   * @usage drush sl-rag:ask "Qui sont les lutteurs invaincus de Door Doorat ?" --max-docs=20
   * @usage drush sl-rag:ask "Qui est le roi des arènes ?" --dry-run
   * @bootstrap full
   */
  public function askQuestion(
    string $question,
    array $options = [
      'max-docs' => 30,
      'dry-run' => FALSE,
    ]
  ): void
  {
    $question = trim($question);
    if ($question === '') {
      $this->logger()->error('Question vide.');
      return;
    }

    $maxDocs = (int) ($options['max-docs'] ?? 30);
    $dryRun = !empty($options['dry-run']);

    $this->logger()->notice('Question : @q', ['@q' => $question]);
    if ($dryRun) {
      $this->logger()->notice('Mode dry-run : pas d\'appel IA.');
    }

    $result = $this->askService()->ask($question, [
      'max_docs' => $maxDocs,
      'dry_run'  => $dryRun,
    ]);

    // Affichage console.
    $this->output()->writeln('');
    $this->output()->writeln('--- Filtres détectés ---');
    $this->output()->writeln(json_encode($result['filters'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $this->output()->writeln('');
    $this->output()->writeln('Documents envoyés à l\'IA : ' . $result['docs_count']);
    $this->output()->writeln('');

    if ($dryRun) {
      $this->output()->writeln('--- PROMPT (dry-run) ---');
      $this->output()->writeln($result['prompt']);
      return;
    }

    if (!$result['success']) {
      $this->logger()->error('Échec : @err', ['@err' => $result['error'] ?? 'inconnu']);
      if (!empty($result['answer'])) {
        $this->output()->writeln($result['answer']);
      }
      return;
    }

    $this->output()->writeln('--- RÉPONSE IA ---');
    $this->output()->writeln($result['answer']);
    $this->output()->writeln('');
    $this->logger()->success('Réponse reçue. Tous les échanges sont dans les logs (canal sl_rag).');
  }

}
