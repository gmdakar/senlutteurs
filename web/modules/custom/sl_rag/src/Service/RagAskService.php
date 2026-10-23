<?php

namespace Drupal\sl_rag\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de question/réponse RAG :
 * - Mode B1 (vectoriel) si activé : recherche via API Pinecone
 * - Mode A (fallback) : filtrage Entity Query + mots-clés
 * - Construction d'un prompt limité
 * - Appel IA via sl_live_search
 * - Logging complet de tous les échanges
 */
class RagAskService
{

  /**
   * Nombre maximum de fiches lutteurs injectées dans le prompt.
   */
  const MAX_DOCS_IN_PROMPT = 30;

  /**
   * Nombre de combats récents par fiche dans le prompt.
   */
  const RECENT_COMBATS_IN_PROMPT = 6;

  protected RagEntityTextualizerService $textualizer;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected LoggerInterface $logger;
  protected VectorSearchClient $vectorSearch;

  /**
   * Service centralisé d'appel IA (module sl_live_search / request_ai).
   *
   * @var object
   */
  protected $liveSearch;

  public function __construct(
    RagEntityTextualizerService $textualizer,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerInterface $logger,
    $liveSearch,
    VectorSearchClient $vectorSearch
  ) {
    $this->textualizer = $textualizer;
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $logger;
    $this->liveSearch = $liveSearch;
    $this->vectorSearch = $vectorSearch;
  }

  /**
   * Pose une question et retourne la réponse de l'IA.
   *
   * @param string $question
   *   Question en langage naturel.
   * @param array $options
   *   - max_docs (int) : max fiches dans le prompt (défaut 30)
   *   - dry_run (bool) : si TRUE, n'appelle pas l'IA, retourne juste le prompt
   *
   * @return array{
   *   success: bool,
   *   answer: ?string,
   *   error: ?string,
   *   filters: array,
   *   docs_count: int,
   *   prompt: string,
   *   raw_response: mixed
   * }
   */
  public function ask(string $question, array $options = []): array
  {
    $question = trim($question);
    $maxDocs = (int) ($options['max_docs'] ?? self::MAX_DOCS_IN_PROMPT);
    $dryRun = !empty($options['dry_run']);

    $this->logger->info('=== RAG ASK START === Question: @q', ['@q' => $question]);

    // 1. Extraire des filtres simples (utiles en mode A et pour logs).
    $filters = $this->extractFilters($question);
    $this->logger->info('Filtres détectés: @filters', [
      '@filters' => json_encode($filters, JSON_UNESCAPED_UNICODE),
    ]);

    // 2. Charger les documents : mode vectoriel (B1) ou fallback SQL (A).
    if ($this->vectorSearch->isEnabled()) {
      $this->logger->info('Mode recherche vectorielle (B1) activé.');
      $documents = $this->loadDocumentsFromVector($question, $maxDocs);
      $filters['mode'] = 'vector';
    }
    else {
      $this->logger->info('Mode filtrage SQL (A) — vectoriel désactivé.');
      $documents = $this->loadCandidateDocuments($filters, $maxDocs);
      $filters['mode'] = 'sql';
    }
    $docsCount = count($documents);

    $this->logger->info('Documents retenus pour le prompt: @count (max=@max)', [
      '@count' => $docsCount,
      '@max'   => $maxDocs,
    ]);

    if ($docsCount === 0) {
      $msg = 'Aucun lutteur trouvé correspondant aux critères de la question.';
      $this->logger->warning($msg);
      return [
        'success'      => FALSE,
        'answer'       => $msg,
        'error'        => NULL,
        'filters'      => $filters,
        'docs_count'   => 0,
        'prompt'       => '',
        'raw_response' => NULL,
      ];
    }

    // 3. Construire le prompt.
    $prompt = $this->buildPrompt($question, $documents, $filters);

    // Log complet du prompt envoyé.
    $this->logger->info("=== PROMPT ENVOYÉ À L'IA ===\n@prompt", ['@prompt' => $prompt]);

    if ($dryRun) {
      $this->logger->info('Dry-run activé : pas d\'appel IA.');
      return [
        'success'      => TRUE,
        'answer'       => '[dry-run] Prompt construit, aucun appel IA.',
        'error'        => NULL,
        'filters'      => $filters,
        'docs_count'   => $docsCount,
        'prompt'       => $prompt,
        'raw_response' => NULL,
      ];
    }

    // 4. Appel IA via le service centralisé.
    $response = $this->liveSearch->liveSearch($prompt);

    // Log complet de la réponse brute.
    $this->logger->info("=== RÉPONSE BRUTE DE L'IA ===\n@resp", [
      '@resp' => is_array($response) ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : (string) $response,
    ]);

    if (empty($response['success']) && empty($response['content'])) {
      $error = $response['error'] ?? 'Réponse IA vide ou en échec';
      $this->logger->error('Échec appel IA: @err', ['@err' => $error]);
      return [
        'success'      => FALSE,
        'answer'       => NULL,
        'error'        => $error,
        'filters'      => $filters,
        'docs_count'   => $docsCount,
        'prompt'       => $prompt,
        'raw_response' => $response,
      ];
    }

    // Extraire la réponse texte (le service liveSearch peut renvoyer du JSON).
    $rawContent = $response['content'] ?? '';
    $answer = '';

    if (is_string($rawContent) && $rawContent !== '' && $rawContent !== '[]') {
      $decoded = json_decode($rawContent, TRUE);
      if (is_array($decoded) && isset($decoded['answer'])) {
        $answer = trim((string) $decoded['answer']);
      }
      elseif (is_array($decoded) && isset($decoded[0]['answer'])) {
        $answer = trim((string) $decoded[0]['answer']);
      }
      else {
        // Contenu texte brut (ou JSON non standard).
        $answer = trim($rawContent);
        // Nettoyage éventuel de fences markdown.
        $answer = preg_replace('/^```(?:json)?\s*/i', '', $answer);
        $answer = preg_replace('/\s*```$/', '', $answer);
        $decoded2 = json_decode($answer, TRUE);
        if (is_array($decoded2) && isset($decoded2['answer'])) {
          $answer = trim((string) $decoded2['answer']);
        }
      }
    }

    if ($answer === '' || $answer === '[]') {
      $error = $response['error'] ?? 'Réponse IA vide ou non exploitable';
      $this->logger->error('Échec extraction réponse IA: @err | content=@c', [
        '@err' => $error,
        '@c' => is_string($rawContent) ? mb_substr($rawContent, 0, 500) : gettype($rawContent),
      ]);
      return [
        'success'      => FALSE,
        'answer'       => NULL,
        'error'        => $error,
        'filters'      => $filters,
        'docs_count'   => $docsCount,
        'prompt'       => $prompt,
        'raw_response' => $response,
      ];
    }

    $this->logger->info("=== RÉPONSE FINALE ===\n@answer", ['@answer' => $answer]);
    $this->logger->info('=== RAG ASK END ===');

    return [
      'success'      => TRUE,
      'answer'       => $answer,
      'error'        => NULL,
      'filters'      => $filters,
      'docs_count'   => $docsCount,
      'prompt'       => $prompt,
      'raw_response' => $response,
    ];
  }

  /**
   * Extrait des filtres simples à partir de la question (règles + mots-clés).
   *
   * @return array{
   *   invaincu: bool,
   *   is_king: bool,
   *   ecurie: ?string,
   *   nom_contains: ?string,
   *   min_combats: ?int
   * }
   */
  /**
   * Charge les documents via l'API vectorielle (Pinecone).
   *
   * @return array<int, array{id: string, text: string, metadata: array}>
   */
  protected function loadDocumentsFromVector(string $question, int $maxDocs): array
  {
    $result = $this->vectorSearch->search($question, $maxDocs);
    if (!$result['success']) {
      $this->logger->warning('Vector search échec (@err), fallback SQL vide.', [
        '@err' => $result['error'] ?? '?',
      ]);
      return [];
    }

    $nameDocs = [];
    $vectorDocs = [];
    $storage = $this->entityTypeManager->getStorage('node');

    foreach ($result['hits'] as $hit) {
      $body = trim((string) ($hit['text'] ?? ''));
      $meta = $hit['metadata'] ?? [];
      $drupalId = (int) ($meta['drupal_id'] ?? 0);
      $matchType = (string) ($hit['match_type'] ?? 'vector');
      $matchHow = (string) ($hit['match_how'] ?? '');

      if ($body === '' && $drupalId > 0) {
        $node = $storage->load($drupalId);
        if ($node) {
          $doc = $this->textualizer->textualizeAthlete($node, self::RECENT_COMBATS_IN_PROMPT);
          if ($doc) {
            $doc['metadata']['_match_type'] = $matchType;
            $doc['metadata']['_match_how'] = $matchHow;
            $doc['metadata']['_vector_score'] = $hit['score'] ?? 0;
            if ($matchType === 'name') {
              $nameDocs[] = $doc;
            }
            else {
              $vectorDocs[] = $doc;
            }
            continue;
          }
        }
      }

      if ($body === '') {
        continue;
      }

      $doc = [
        'id' => (string) ($hit['id'] ?? ('athlete_' . $drupalId)),
        'text' => $body,
        'metadata' => $meta + [
          '_vector_score' => $hit['score'] ?? 0,
          '_match_type' => $matchType,
          '_match_how' => $matchHow,
        ],
      ];
      if ($matchType === 'name') {
        $nameDocs[] = $doc;
      }
      else {
        $vectorDocs[] = $doc;
      }
    }

    // Priorité aux matchs nom : limite le bruit des hits purement vectoriels.
    if (!empty($nameDocs)) {
      $extra = max(0, min(5, $maxDocs - count($nameDocs)));
      $documents = array_merge($nameDocs, array_slice($vectorDocs, 0, $extra));
      $this->logger->info('Matchs nom: @n — vectoriels gardés: @v', [
        '@n' => count($nameDocs),
        '@v' => min($extra, count($vectorDocs)),
      ]);
    }
    else {
      $documents = $vectorDocs;
    }

    return array_slice($documents, 0, $maxDocs);
  }

  protected function extractFilters(string $question): array
  {
    $q = mb_strtolower($question);

    $filters = [
      'invaincu'     => FALSE,
      'is_king'      => FALSE,
      'ecurie'       => NULL,
      'nom_contains' => NULL,
      'min_combats'  => NULL,
    ];

    // Invaincu / sans défaite.
    if (preg_match('/\b(invaincu|invaincus|sans\s+d[eé]faite|0\s+d[eé]faite|aucune\s+d[eé]faite)\b/u', $q)) {
      $filters['invaincu'] = TRUE;
    }

    // Roi des arènes.
    if (preg_match('/\b(roi\s+des\s+ar[eè]nes|rois\s+des\s+ar[eè]nes|actuel\s+roi)\b/u', $q)) {
      $filters['is_king'] = TRUE;
    }

    // Écurie : "de l'écurie X", "écurie X", "de X".
    if (preg_match('/(?:[eé]curie|ecurie)\s+(?:de\s+|d[\'’])?["«]?([^"»,\.\?]+?)["»]?(?:\s|$|\.|\?)/ui', $question, $m)) {
      $filters['ecurie'] = trim($m[1]);
    }
    elseif (preg_match('/\bde\s+(?:l[\'’])?([A-ZÀÂÄÉÈÊËÎÏÔÖÙÛÜ][\wÀ-ÿ\'\-\s]{2,40}?)(?:\s*\?|$)/u', $question, $m)) {
      // Heuristique légère : "de Door Doorat ?"
      $candidate = trim($m[1]);
      if (!preg_match('/^(les|des|un|une|la|le|lutteurs?|combats?)$/ui', $candidate)) {
        $filters['ecurie'] = $candidate;
      }
    }

    // Nom de lutteur probable (si la question contient un nom propre isolé).
    // On reste prudent : on ne force pas trop.

    return $filters;
  }

  /**
   * Charge les documents candidats selon les filtres, calcule les stats,
   * et ne garde que ceux qui matchent vraiment (ex. invaincu).
   *
   * @return array<int, array{id: string, text: string, metadata: array}>
   */
  protected function loadCandidateDocuments(array $filters, int $maxDocs): array
  {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'athlete')
      ->condition('status', 1);

    // Filtre écurie si détecté (entity reference → title).
    if (!empty($filters['ecurie'])) {
      $ecurieName = $filters['ecurie'];
      // Cherche l'écurie par titre (approximation).
      $ecurieNids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'ecurie')
        ->condition('title', $ecurieName, 'CONTAINS')
        ->range(0, 5)
        ->execute();

      // Fallback : certains sites stockent l'écurie autrement.
      // Si on trouve des nœuds écurie, on filtre field_ecurie.
      if (!empty($ecurieNids)) {
        $query->condition('field_ecurie', array_values($ecurieNids), 'IN');
      }
      else {
        // Pas de nœud écurie trouvé : on chargera plus large et on filtrera
        // après textualisation sur metadata.ecurie.
        $this->logger->notice('Aucune écurie trouvée en base pour "@name", filtrage post-textualisation.', [
          '@name' => $ecurieName,
        ]);
      }
    }

    if (!empty($filters['is_king'])) {
      $query->condition('field_king', 1);
    }

    // On charge un pool un peu plus large que maxDocs pour pouvoir
    // appliquer les filtres de stats (invaincu) ensuite.
    $poolSize = max($maxDocs * 3, 80);
    $query->range(0, $poolSize);
    $query->sort('nid', 'ASC');

    $nids = $query->execute();
    if (empty($nids)) {
      return [];
    }

    $documents = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $doc = $this->textualizer->textualizeAthlete($node, self::RECENT_COMBATS_IN_PROMPT);
      if ($doc === null) {
        continue;
      }

      $meta = $doc['metadata'];

      // Filtre invaincu (calculé).
      if (!empty($filters['invaincu'])) {
        if (($meta['defaites'] ?? 1) > 0 || ($meta['total_combats'] ?? 0) === 0) {
          continue;
        }
      }

      // Filtre écurie post-hoc si on n'a pas pu filtrer en query.
      if (!empty($filters['ecurie']) && empty($ecurieNids)) {
        $ecurieMeta = mb_strtolower((string) ($meta['ecurie'] ?? ''));
        $needle = mb_strtolower($filters['ecurie']);
        if ($ecurieMeta === '' || !str_contains($ecurieMeta, $needle)) {
          continue;
        }
      }

      $documents[] = $doc;
      if (count($documents) >= $maxDocs) {
        break;
      }
    }

    return $documents;
  }

  /**
   * Construit le prompt final envoyé à l'IA.
   */
  protected function buildPrompt(string $question, array $documents, array $filters): string
  {
    $parts = [];
    $parts[] = "Tu es un expert de la lutte sénégalaise. Tu réponds UNIQUEMENT à partir des fiches lutteurs fournies ci-dessous.";
    $parts[] = "Si l'information n'est pas dans les fiches, dis-le clairement. Ne invente rien.";
    $parts[] = "Réponds en français, de façon claire et concise.";
    $parts[] = "";
    $parts[] = "RÈGLES IMPORTANTES SUR LES NOMS :";
    $parts[] = "- Les orthographes dans la question peuvent différer des fiches (ex. Louis/Liss, Chikh/Cheikh).";
    $parts[] = "- Si une fiche est marquée [MATCH NOM] ou est clairement le lutteur visé malgré une variante d'écriture, utilise-la.";
    $parts[] = "- Ne dis PAS qu'un lutteur est absent s'il figure dans les fiches sous un nom proche.";
    $parts[] = "- Pour une comparaison entre 2 lutteurs, base-toi en priorité sur les fiches [MATCH NOM].";
    $parts[] = "";
    $parts[] = "=== QUESTION ===";
    $parts[] = $question;
    $parts[] = "";
    $parts[] = "=== FICHES LUTTEURS (" . count($documents) . ") ===";
    $parts[] = "";

    foreach ($documents as $i => $doc) {
      $meta = $doc['metadata'] ?? [];
      $matchType = $meta['_match_type'] ?? '';
      $label = ($matchType === 'name') ? ' [MATCH NOM — prioritaire]' : '';
      $parts[] = "--- Fiche " . ($i + 1) . $label . " ---";
      $parts[] = $doc['text'];
      $parts[] = "";
    }

    $parts[] = "=== FIN DES FICHES ===";
    $parts[] = "";
    $parts[] = "IMPORTANT : tu dois répondre UNIQUEMENT avec un JSON valide de la forme exacte :";
    $parts[] = '{"answer": "ta réponse en français ici"}';
    $parts[] = "Pas de texte avant ou après le JSON. Pas de markdown.";

    return implode("\n", $parts);
  }

}
