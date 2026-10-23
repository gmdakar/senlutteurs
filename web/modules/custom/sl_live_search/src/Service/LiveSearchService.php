<?php

namespace Drupal\sl_live_search\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\shared_lib\Tools\SomeTools;

/**
 * Service de recherche en direct via IA (Grok ou Gemini).
 */
class LiveSearchService
{

  protected $httpClient;
  protected $configFactory;
  protected $loggerFactory;
  protected $state;
  protected $keyRepository;

  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state,
    KeyRepositoryInterface $key_repository
  ) {
    $this->httpClient    = $http_client;
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
    $this->state         = $state;
    $this->keyRepository = $key_repository;
  }

  /**
   * Resout une cle API via le module Key ou en valeur directe en fallback.
   *
   * Si la valeur configuree ressemble a un identifiant Key (pas de chiffres,
   * pas d'espace, pas de caracteres speciaux de cle API), on tente de la
   * resoudre via key.repository. Sinon on retourne la valeur brute.
   *
   * @param string $configured_value  Valeur issue de la config (id Key ou cle brute).
   * @return string                   Valeur reelle de la cle API.
   */
  protected function resolveApiKey(string $configured_value): string
  {
    if (empty($configured_value)) {
      return '';
    }
    $key_entity = $this->keyRepository->getKey($configured_value);
    if ($key_entity) {
      return $key_entity->getKeyValue() ?? '';
    }
    // Fallback : la valeur est deja une cle brute (ancien comportement).
    return $configured_value;
  }

  /**
   * Nettoie les caracteres UTF-8 invalides — 3 passes successives.
   */
  protected function sanitizeUtf8(string $text): string
  {
    $clean = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    if ($clean === false) {
      $clean = $text;
    }

    if (!mb_check_encoding($clean, 'UTF-8')) {
      $iconved = iconv('UTF-8', 'UTF-8//IGNORE', $clean);
      $clean   = ($iconved !== false) ? $iconved : $clean;
    }

    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean);
    $clean = preg_replace(
      '/[\xC0-\xC1]|[\xF5-\xFF]|' .
        '\xE0[\x80-\x9F]|' .
        '\xF0[\x80-\x8F]|' .
        '[\xC2-\xDF](?![\x80-\xBF])|' .
        '[\xE0-\xEF](?![\x80-\xBF]{2})|' .
        '[\xF0-\xF4](?![\x80-\xBF]{3})|' .
        '(?<![\xC2-\xF4])[\x80-\xBF]/',
      '',
      $clean
    );

    return $clean ?? '';
  }

  /**
   * Echappe les accolades d'un texte avant injection dans un message de log.
   *
   * Drupal (LogMessageParser / FormattableMarkup) traite {nom} comme placeholder
   * PSR-3. Un JSON brut injecte via @prompt / @data / @raw se fait donc mangler :
   * {"lutteur":"..."} → @"lutteur":"..."
   * On remplace par des accolades pleine chasse (visuellement proches, non parsees).
   */
  protected function escapeForLog(string $text): string
  {
    return str_replace(['{', '}'], ['｛', '｝'], $text);
  }

  /**
   * Retire toute clé API visible dans une URL avant logging.
   *
   * Filet de sécurité générique (Gemini en particulier passe sa clé en
   * paramètre ?key=... dans l'URL, donc tout message d'exception cURL
   * contenant cette URL expose la clé en clair dans les logs).
   */
  protected function redactApiKeyFromMessage(string $text): string
  {
    return preg_replace('/([?&]key=)[^&\s"\']+/i', '$1[REDACTED]', $text);
  }

  /**
   * Log detaille de l'etat UTF-8 d'une chaine pour diagnostic.
   */
  protected function logUtf8Debug(string $label, string $text, $logger): void
  {
    $len     = strlen($text);
    $mbValid = mb_check_encoding($text, 'UTF-8') ? 'OUI' : 'NON';

    $firstBadPos = null;
    $firstBadHex = '';

    for ($i = 0; $i < $len; $i++) {
      $byte = ord($text[$i]);

      if ($byte >= 0x80 && $byte <= 0xBF) {
        $firstBadPos = $i;
        $firstBadHex = sprintf('0x%02X', $byte);
        break;
      }
      if ($byte >= 0xC2 && $byte <= 0xDF) {
        if ($i + 1 >= $len || (ord($text[$i + 1]) & 0xC0) !== 0x80) {
          $firstBadPos = $i;
          $firstBadHex = sprintf('0x%02X', $byte);
          break;
        }
        $i += 1;
        continue;
      }
      if ($byte >= 0xE0 && $byte <= 0xEF) {
        if ($i + 2 >= $len || (ord($text[$i + 1]) & 0xC0) !== 0x80 || (ord($text[$i + 2]) & 0xC0) !== 0x80) {
          $firstBadPos = $i;
          $firstBadHex = sprintf('0x%02X', $byte);
          break;
        }
        $i += 2;
        continue;
      }
      if ($byte >= 0xF0 && $byte <= 0xF4) {
        if ($i + 3 >= $len || (ord($text[$i + 1]) & 0xC0) !== 0x80 || (ord($text[$i + 2]) & 0xC0) !== 0x80 || (ord($text[$i + 3]) & 0xC0) !== 0x80) {
          $firstBadPos = $i;
          $firstBadHex = sprintf('0x%02X', $byte);
          break;
        }
        $i += 3;
        continue;
      }
      if ($byte === 0xC0 || $byte === 0xC1 || $byte >= 0xF5) {
        $firstBadPos = $i;
        $firstBadHex = sprintf('0x%02X', $byte);
        break;
      }
    }

    if ($firstBadPos !== null) {
      $start      = max(0, $firstBadPos - 10);
      $end        = min($len - 1, $firstBadPos + 10);
      $snippet    = substr($text, $start, $end - $start + 1);
      $contextHex = '';
      for ($j = 0; $j < strlen($snippet); $j++) {
        $contextHex .= sprintf('%02X ', ord($snippet[$j]));
      }
      /*$logger->warning('@label [UTF8-DEBUG] len=@len valid=@valid | PREMIER OCTET INVALIDE pos=@pos val=@hex | contexte hex: @ctx', [
        '@label' => $label,
        '@len'   => $len,
        '@valid' => $mbValid,
        '@pos'   => $firstBadPos,
        '@hex'   => $firstBadHex,
        '@ctx'   => trim($contextHex),
      ]);*/
    } else {
      $logger->info('@label [UTF8-DEBUG] len=@len valid=@valid — aucun octet invalide detecte.', [
        '@label' => $label,
        '@len'   => $len,
        '@valid' => $mbValid,
      ]);
    }
  }

  /**
   * Tente de recuperer un tableau JSON partiellement tronque.
   */
  protected function recoverPartialJsonArray($text)
  {
    $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
    $text = preg_replace('/\s*```$/', '', $text);
    $text = trim($text);

    if (strpos($text, '[') !== 0) {
      return [];
    }

    $lastClose = strrpos($text, '}');
    if ($lastClose === false) {
      return [];
    }

    $candidate = substr($text, 0, $lastClose + 1) . ']';
    $candidate = preg_replace('/,\s*\]$/', ']', $candidate);

    $decoded = json_decode($candidate, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && !empty($decoded) && array_values($decoded) === $decoded) {
      return $decoded;
    }

    return [];
  }

  /**
   * Execute une recherche en direct via l'IA et retourne un tableau JSON.
   *
   * @param bool $bypass_lock
   *   Si TRUE, ignore le verrou de succes 24h (utilise par drush air pour
   *   permettre de rejouer le meme prompt de test a volonte). Le verrou de
   *   quota epuise reste actif dans tous les cas, pour ne pas continuer a
   *   solliciter une cle deja en echec.
   */
  public function liveSearch($prompt, $engine = 'gemini', $mode = 'normal', $force_engine = false, $bypass_lock = false)
  {
    $logger = $this->loggerFactory->get('sl_live_search');

    // Priorité maximale à la configuration globale si non forcée
    $config = $this->configFactory->get('request_ai.settings');
    if (!$force_engine) {
      $engine = $config->get('engine') ?? $engine;
    }

    $logger->info('liveSearch engine utilisé : "@engine"', [
      '@engine' => $engine,
    ]);

    if ($bypass_lock) {
      $logger->info('liveSearch : verrou de succes 24h contourne (bypass_lock actif, ex: drush air).');
    }

    $text = '';
    $short     = substr(preg_replace('/[^a-z0-9]/', '_', strtolower($prompt)), 0, 50);
    $hash      = substr(hash('sha256', $prompt), 0, 12);
    $state_key = 'sl_live_search.success.' . $engine . '.' . $short . '_' . $hash;
    $quota_key = 'sl_live_search.quota_exhausted.' . $engine . '.' . $short . '_' . $hash;

    // --- VERROU SUCCES ---
    // ATTENTION : la valeur stockee est une date d'EXPIRATION (time() + 86400,
    // voir plus bas), pas un simple flag booleen. Il faut donc la comparer a
    // l'heure actuelle pour savoir si le verrou est encore valide, sinon il
    // reste actif indefiniment (bug historique qui necessitait un
    // "drush sl:live-search-reset" manuel a chaque fois qu'un meme prompt
    // — ex. confirmation Grok sur un groupe de combats non encore verrouille —
    // etait resoumis apres expiration prevue de 24h).
    $success_expiry = $bypass_lock ? null : $this->state->get($state_key);
    if ($success_expiry) {
      if ($success_expiry > time()) {
        $logger->info('liveSearch Appel ignore : deja traite via @engine (verrou valide jusqu\'a @expiry).', [
          '@name'   => $this->escapeForLog($prompt),
          '@engine' => $engine,
          '@expiry' => date('Y-m-d H:i:s', $success_expiry),
        ]);
        return ['success' => true, 'content' => '[]', 'skipped' => true];
      }

      // Verrou expire : on le supprime et on laisse l'appel repartir
      // normalement au lieu de bloquer indefiniment.
      $logger->info('liveSearch Verrou @engine expire (etait valide jusqu\'a @expiry), on retente.', [
        '@name'   => $this->escapeForLog($prompt),
        '@engine' => $engine,
        '@expiry' => date('Y-m-d H:i:s', $success_expiry),
      ]);
      $this->state->delete($state_key);
    }

    // --- VERROU QUOTA ---
    $quota_failed_at = $this->state->get($quota_key);
    if ($quota_failed_at) {
      $midnight_after_failure = mktime(
        0,
        0,
        0,
        (int) date('n', $quota_failed_at),
        (int) date('j', $quota_failed_at) + 1,
        (int) date('Y', $quota_failed_at)
      );

      if (time() < $midnight_after_failure) {
        $retry_at = date('Y-m-d H:i:s', $midnight_after_failure);
        $logger->warning('liveSearch quota @engine epuise. Reessai apres @retry.', [
          '@engine' => $engine,
          '@name'   => $this->escapeForLog($prompt),
          '@retry'  => $retry_at,
        ]);
        return [
          'success'  => false,
          'error'    => 'quota_exhausted_until_midnight',
          'retry_at' => $retry_at,
          'content'  => '[]',
        ];
      }

      $logger->info('liveSearch quota @engine reinitialise, on retente.', [
        '@engine' => $engine,
        '@name'   => $this->escapeForLog($prompt),
      ]);
      $this->state->delete($quota_key);
    }

    $logger->notice('Debut liveSearch via @engine pour : @prompt', [
      '@engine' => $engine,
      '@prompt' => $this->escapeForLog($prompt),
    ]);

    try {
      // --- CONFIGURATION GROK ---
      if ($engine == 'grok') {
        $api_key = $this->resolveApiKey($config->get('api_key_grok') ?? '');
        $model   = $config->get('model_grok') ?: 'grok-4.3';

        if (empty($api_key)) {
          $logger->error('liveSearch : cle API Grok introuvable ou vide.');
          return ['success' => false, 'error' => 'grok_key_not_found', 'content' => '[]'];
        }

        $requestBody = [
          'model' => $model,
          'input' => [
            [
              'role'    => 'system',
              'content' => "Tu es un expert spécialisé dans la lutte sénégalaise. "
                . "Utilise activement les outils web_search et x_search pour chaque combat. "
                . "Accepte les variations d'orthographe des noms. "
                . "Priorise YouTube : si une vidéo du combat existe et que le vainqueur est évident (titre, description ou commentaires), valide-le. "
                . "Ne jamais inventer de résultat. "
                . "Si rien n'est clairement confirmé, ne retourne rien pour ce combat. "
                . "Réponds UNIQUEMENT avec un tableau JSON pur, sans aucun texte supplémentaire."
            ],
            [
              'role'    => 'user',
              'content' => $prompt
            ]
          ],
          'tools' => [
            [
              'type' => 'web_search',
              'excluded_domains' => ['senlutteurs.com']
            ],
            ['type' => 'x_search']
          ],
          'temperature'       => 0.0,
          'max_output_tokens' => 65536,
          'text' => ['format' => ['type' => 'json_object']]
        ];

        $response = null;
        try {
          $response = $this->httpClient->post('https://api.x.ai/v1/responses', [
            'headers'         => [
              'Authorization' => 'Bearer ' . $api_key,
              'Content-Type'  => 'application/json',
            ],
            'json'            => $requestBody,
            // Le timeout GLOBAL (connexion + transfert + attente réponse)
            // était déjà à 90s (largement au-dessus des 15-30s habituels
            // de Grok avec web_search/x_search actifs) : on ne le
            // réduit surtout PAS, on ajoute juste un connect_timeout
            // dédié pour distinguer une lenteur réseau à l'établissement
            // de la connexion d'un simple temps de traitement long côté
            // Grok.
            'timeout'         => 90,
            'connect_timeout' => 15,
            'http_errors'     => false,
          ]);
        } catch (RequestException $e) {
          // Log de CONFIRMATION dédié : permet de vérifier noir sur blanc
          // dans les logs si l'échec Grok est bien un TIMEOUT cURL (comme
          // suspecté) ou une autre erreur réseau (DNS, connexion
          // refusée, certificat...). errno cURL récupéré via le contexte
          // du handler Guzzle quand disponible.
          $curlErrno = $e->getHandlerContext()['errno'] ?? null;
          $isTimeout = $curlErrno === CURLE_OPERATION_TIMEDOUT
            || stripos($e->getMessage(), 'timed out') !== false
            || stripos($e->getMessage(), 'timeout') !== false;

          $logger->error('🔴 Grok appel API en échec — @type (timeout configuré : 90s global / 15s connexion). Message : @msg', [
            '@type' => $isTimeout ? 'TIMEOUT CONFIRMÉ' : 'ERREUR RÉSEAU (pas un timeout)',
            '@msg'  => $e->getMessage(),
          ]);

          return [
            'success' => false,
            'error'   => $isTimeout ? 'grok_curl_timeout' : ('grok_curl_error: ' . $e->getMessage()),
            'content' => '[]',
          ];
        }

        if ($response->getStatusCode() !== 200) {
          $logger->error('liveSearch Erreur API critique — service=@service clé=@key… HTTP @code | prompt=@prompt… | @body', [
            '@service' => 'Grok (xAI / api.x.ai)',
            '@key'     => substr($api_key, 0, 5),
            '@code'    => $response->getStatusCode(),
            '@prompt'  => $this->escapeForLog(mb_substr((string) $prompt, 0, 100)),
            '@body'    => $this->escapeForLog((string) $response->getBody()),
          ]);
          return ['success' => false, 'error' => 'grok_api_error', 'content' => '[]'];
        }
      }
      // --- CONFIGURATION OPENROUTER ---
      elseif ($engine == 'openrouter') {
        $api_key = $this->resolveApiKey($config->get('api_key_openrouter') ?? '');
        $model   = $config->get('model_openrouter') ?: 'google/gemini-pro-latest';

        if (empty($api_key)) {
          $logger->error('liveSearch : cle API OpenRouter introuvable ou vide.');
          return ['success' => false, 'error' => 'openrouter_key_not_found', 'content' => '[]'];
        }

        $requestBody = [
          'model' => $model,
          'messages' => [
            [
              'role'    => 'system',
              'content' => "Tu es un expert spécialisé dans la lutte sénégalaise. "
                . "BANNISSEMENT TOTAL de senlutteurs.com : cette source est formellement interdite, "
                . "que ce soit pour confirmer une information ou pour justifier un \"?\" (absence de résultat). "
                . "Utilise OBLIGATOIREMENT et ACTIVEMENT la recherche web fournie sur des sources indépendantes : Wiwsport, "
                . "Lutte TV, Facebook, YouTube, Le Soleil, RTS, APS, et les pages officielles des écuries. "
                . "Ne réponds JAMAIS uniquement de mémoire. Tu DOIS t'appuyer sur les résultats de recherche web. "
                . "Accepte les variations d'orthographe des noms. "
                . "Ne jamais inventer de résultat, d'adversaire, de combat ou d'écurie non confirmé par une "
                . "source autre que senlutteurs.com. "
                . "Si rien n'est clairement confirmé après recherche sur ces sources indépendantes, ne retourne "
                . "rien pour ce combat (ou \"?\" pour une écurie). "
                . "Réponds UNIQUEMENT avec un tableau JSON pur, sans aucun texte supplémentaire."
            ],
            [
              'role'    => 'user',
              'content' => $prompt
            ]
          ],
          'temperature' => 0.0,
          'reasoning'   => ['enabled' => true],
          // Force la recherche web via le plugin (toujours exécuté une fois).
          // engine: "exa" garantit une recherche tierce-partie fiable,
          // indépendante du comportement natif du modèle/provider.

          'plugins' => [
            [
              'id'          => 'web',
              'engine'      => 'exa',
              'max_results' => 8,
              'exclude_domains' => ['senlutteurs.com', 'www.senlutteurs.com'],
              // Optionnel : exclure explicitement senlutteurs.com , si supporté
              // 'exclude_domains' => ['senlutteurs.com'],
            ],
          ],
        ];

        // Log du payload exact envoye, AVANT l'appel, pour pouvoir comparer
        // deux requetes (ex : celle qui a bien declenche web_search_requests
        // et celle qui ne l'a pas fait) sans devoir deviner ce qui a ete
        // reellement transmis a OpenRouter.
        $logger->debug('liveSearch OpenRouter payload envoye : @payload', [
          '@payload' => $this->escapeForLog(json_encode($requestBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
        ]);

        // Relance automatique si le plugin web n'a produit AUCUNE recherche
        // (comportement non deterministe deja observe : meme payload,
        // meme modele, mais 0 recherche sur certains lots vs 14 sur
        // d'autres). Max 3 relances (donc 4 tentatives au total) avant
        // d'accepter la reponse telle quelle.
        $maxWebSearchRetries = 3;
        $attempt = 0;
        $response = null;

        do {
          $attempt++;

          try {
            $response = $this->httpClient->post('https://openrouter.ai/api/v1/chat/completions', [
              'headers'         => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
              ],
              'json'            => $requestBody,
              'timeout'         => 90,
              'connect_timeout' => 15,
              'http_errors'     => false,
            ]);
          } catch (RequestException $e) {
            $curlErrno = $e->getHandlerContext()['errno'] ?? null;
            $isTimeout = $curlErrno === CURLE_OPERATION_TIMEDOUT
              || stripos($e->getMessage(), 'timed out') !== false
              || stripos($e->getMessage(), 'timeout') !== false;

            $logger->error('🔴 OpenRouter appel API en échec — @type (timeout configuré : 90s global / 15s connexion). Message : @msg', [
              '@type' => $isTimeout ? 'TIMEOUT CONFIRMÉ' : 'ERREUR RÉSEAU (pas un timeout)',
              '@msg'  => $e->getMessage(),
            ]);

            // Pas de relance sur une erreur reseau/timeout : on ne veut
            // relancer que pour le cas precis "0 recherche web", pas pour
            // masquer un vrai probleme de connectivite.
            return [
              'success' => false,
              'error'   => $isTimeout ? 'openrouter_curl_timeout' : ('openrouter_curl_error: ' . $e->getMessage()),
              'content' => '[]',
            ];
          }

          if ($response->getStatusCode() !== 200) {
            $logger->error('liveSearch Erreur API critique — service=@service clé=@key… HTTP @code | prompt=@prompt… | @body', [
              '@service' => 'OpenRouter (openrouter.ai)',
              '@key'     => substr($api_key, 0, 5),
              '@code'    => $response->getStatusCode(),
              '@prompt'  => $this->escapeForLog(mb_substr((string) $prompt, 0, 100)),
              '@body'    => $this->escapeForLog((string) $response->getBody()),
            ]);
            return ['success' => false, 'error' => 'openrouter_api_error', 'content' => '[]'];
          }

          $rawCheck  = (string) $response->getBody();
          $bodyCheck = json_decode($rawCheck, true);

          // Vérification robuste : annotations (plugin) + compteur server_tool (si présent)
          $citations = $bodyCheck['choices'][0]['message']['annotations'] ?? [];
          $webSearchCount = $bodyCheck['usage']['server_tool_use_details']['web_search_requests']
            ?? $bodyCheck['usage']['server_tool_use']['web_search_requests']
            ?? 0;

          $hasWebEvidence = (!empty($citations) || $webSearchCount > 0);

          if ($hasWebEvidence) {
            if ($attempt > 1) {
              $logger->info('liveSearch OpenRouter : recherche web obtenue apres relance (tentative @attempt/@max, annotations=@ann, web_search_requests=@count).', [
                '@attempt' => $attempt,
                '@max'     => $maxWebSearchRetries + 1,
                '@ann'     => count($citations),
                '@count'   => $webSearchCount,
              ]);
            }
            break;
          }

          if ($attempt > $maxWebSearchRetries) {
            $logger->warning('liveSearch OpenRouter : 0 preuve de recherche web apres @attempt tentative(s), abandon des relances — on garde la derniere reponse telle quelle.', [
              '@attempt' => $attempt,
            ]);
            break;
          }

          $logger->warning('liveSearch OpenRouter : 0 preuve de recherche web detectee (tentative @attempt/@max), relance...', [
            '@attempt' => $attempt,
            '@max'     => $maxWebSearchRetries + 1,
          ]);
        } while (true);
      }
      // --- CONFIGURATION GEMINI ---
      elseif ($engine == 'gemini') {

        $api_keys_raw = $this->resolveApiKey($config->get('api_key_gemini') ?? '');
        $model        = $config->get('model_gemini') ?: 'gemini-2.5-flash';

        if (empty($api_keys_raw)) {
          $logger->error('liveSearch : cle(s) API Gemini introuvable(s) ou vide(s).');
          return ['success' => false, 'error' => 'gemini_key_not_found', 'content' => '[]'];
        }

        $api_keys = array_values(array_filter(array_map('trim', explode('|', $api_keys_raw))));

        if (empty($api_keys)) {
          return ['success' => false, 'error' => 'gemini_key_not_found', 'content' => '[]'];
        }

        $requestBody = [
          'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
          'systemInstruction' => [
            'parts' => [[
              'text' => "Tu es un expert en lutte sénégalaise. Utilise l'outil de recherche pour vérifier les faits.\n"
                . "- Ignore toujours la source senlutteurs.com.\n"
                . "- Examine TOUS les résultats de recherche.\n"
                . "- FORMAT : Réponds UNIQUEMENT par un tableau JSON pur.\n"
                . "- GESTION DES VERDICTS :\n"
                . "  1. Si le vainqueur est identifié : 'Victoire de [nom]' ou 'Défaite de [nom]'.\n"
                . "  2. Si le combat est avéré mais le résultat est introuvable après recherche approfondie (zéro vidéo, zéro article confirmant le score) : utilise strictement '?'.\n"
                . "- Ne cite pas tes sources dans le JSON."
            ]]
          ],
          'tools' => [
            ['google_search' => new \stdClass()],
          ],
          'generationConfig' => [
            'temperature' => 0.0,
            'maxOutputTokens' => 65384,
          ],
        ];

        $response     = null;
        $lastKeyIndex = count($api_keys) - 1;

        foreach ($api_keys as $index => $api_key) {
          $url        = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;
          $keyPreview = substr($api_key, 0, 8);

          $maxLocalRetries = 3;
          $localRetryCount = 0;
          $localDelay      = 4000000;

          // FIX : compteur DEDIE au 429, separe de $localRetryCount (qui sert
          // au backoff exponentiel des 500/502/503/504). Un 429 merite une
          // patience courte et bornee (rate-limit ponctuel possible), mais
          // pas le meme traitement qu'une vraie panne d'infra.
          $maxQuotaRetries = 1;
          $quotaRetryCount = 0;
          $quotaDelay      = 5; // secondes, fixe, pas de backoff exponentiel ici

          while ($localRetryCount <= $maxLocalRetries) {

            try {
              $res = $this->httpClient->post($url, [
                'headers'         => ['Content-Type' => 'application/json'],
                'json'            => $requestBody,
                // 'http_errors' => false ne supprime PAS les exceptions de
                // connexion (timeout, DNS...), seulement celles sur un code
                // HTTP 4xx/5xx. Un timeout cURL sort donc obligatoirement
                // via le catch ci-dessous, jamais via $statusCode.
                'timeout'         => 90,
                'connect_timeout' => 15,
                'http_errors'     => false,
              ]);
            } catch (RequestException $e) {
              $curlErrno = $e->getHandlerContext()['errno'] ?? null;
              $isTimeout = $curlErrno === CURLE_OPERATION_TIMEDOUT
                || stripos($e->getMessage(), 'timed out') !== false
                || stripos($e->getMessage(), 'timeout') !== false;

              // IMPORTANT : ne jamais logger $e->getMessage() brut ici. Pour
              // Gemini, la clé API est dans l'URL (?key=...), et le message
              // d'exception cURL inclut l'URL complète — donc la clé finirait
              // en clair dans les logs (bug corrigé le 2026-09-29).
              $logger->warning('liveSearch Gemini @type sur clé @key... (timeout configuré : 90s global / 15s connexion). Tentative @count/@max...', [
                '@type'  => $isTimeout ? 'TIMEOUT CONFIRMÉ' : 'ERREUR RÉSEAU (pas un timeout)',
                '@key'   => $keyPreview,
                '@count' => $localRetryCount + 1,
                '@max'   => $maxLocalRetries + 1,
              ]);

              if ($localRetryCount < $maxLocalRetries) {
                $localRetryCount++;
                usleep($localDelay);
                $localDelay *= 5;
                continue;
              }

              // Retries locaux épuisés sur cette clé : on passe à la clé
              // suivante (ou on sort proprement si c'était la dernière),
              // exactement comme pour un 500/502/503/504 persistant.
              if ($index === $lastKeyIndex) {
                $logger->warning('liveSearch : TOUTES les cles Gemini HS (y compris la derniere @key...) — cause : ERREUR RESEAU/TIMEOUT APRES RETRIES.', [
                  '@key' => $keyPreview,
                ]);
                return ['success' => false, 'error' => 'gemini_network_error_or_timeout', 'content' => '[]'];
              }

              // break 1 : sort du while(), le foreach passe a la cle suivante.
              break;
            }

            $statusCode = $res->getStatusCode();

            if ($statusCode === 200) {
              $response = $res;
              break 2;
            }

            $errorBody = (string) $res->getBody();
            $errorData = json_decode($errorBody, true);
            $apiStatus = $errorData['error']['status'] ?? '';
            $statusUpper = strtoupper(trim($apiStatus));

            // Clé définitivement inutilisable : invalide, révoquée, ou signalée
            // comme leaked (403 PERMISSION_DENIED — cas observé en prod :
            // "Your API key was reported as leaked"). Sans ce cas, l'erreur
            // tombait dans le branchement "critique et inattendue" qui
            // ABORTAIT sans passer à la clé suivante → rotation morte.
            $errorMessage = (string) ($errorData['error']['message'] ?? '');
            $isInvalidKey = (
              $statusUpper === 'INVALID_ARGUMENT'
              || $statusUpper === 'PERMISSION_DENIED'
              || $statusUpper === 'UNAUTHENTICATED'
              || $statusCode === 400
              || $statusCode === 401
              || $statusCode === 403
              || stripos($errorMessage, 'leaked') !== false
              || stripos($errorMessage, 'API key not valid') !== false
            );
            $isQuotaExhausted = ($statusUpper === 'RESOURCE_EXHAUSTED' || $statusCode === 429);
            // 502 (Bad Gateway) et 504 (Gateway Timeout) sont des erreurs
            // transitoires d'infrastructure (proxy Google en amont de l'API), au
            // meme titre que 503. Le body est souvent du HTML, pas du JSON, d'ou
            // l'ajout explicite sur $statusCode plutot que de se fier a $statusUpper.
            $isUnavailable    = str_contains($statusUpper, 'UNAVAILABLE') || in_array($statusCode, [502, 503, 504], true);
            $isInternalError  = ($statusUpper === 'INTERNAL') || ($statusCode === 500);

            // FIX : patience COURTE et BORNEE pour le 429, au cas ou ce ne
            // soit qu'une saturation ponctuelle (rate-limit RPM) plutot qu'un
            // vrai quota journalier epuise. Une seule tentative, delai fixe
            // de 5s (pas de backoff exponentiel — si ca persiste au 2e essai,
            // ce n'est pas transitoire).
            if ($isQuotaExhausted && $quotaRetryCount < $maxQuotaRetries) {
              $quotaRetryCount++;
              $logger->warning('liveSearch Gemini 429 sur clé @key... possible saturation ponctuelle, patience de @delay s avant reessai @count/@max...', [
                '@key'   => $keyPreview,
                '@delay' => $quotaDelay,
                '@count' => $quotaRetryCount,
                '@max'   => $maxQuotaRetries,
              ]);
              sleep($quotaDelay);
              continue;
            }

            // Retry local avec backoff exponentiel UNIQUEMENT pour les
            // pannes d'infra transitoires (500/502/503/504).
            if (($isInternalError || $isUnavailable) && $localRetryCount < $maxLocalRetries) {
              $localRetryCount++;
              $errorLabel = match (true) {
                $isInternalError    => '500 (INTERNAL)',
                $statusCode === 502 => '502 (BAD_GATEWAY)',
                $statusCode === 504 => '504 (GATEWAY_TIMEOUT)',
                default             => '503 (UNAVAILABLE)',
              };

              $logger->warning('liveSearch Gemini @status sur clé @key... Tentative de réessai local @count/@max dans @delay s...', [
                '@status' => $errorLabel,
                '@key'    => $keyPreview,
                '@count'  => $localRetryCount,
                '@max'    => $maxLocalRetries,
                '@delay'  => $localDelay / 1000000,
              ]);

              usleep($localDelay);
              $localDelay *= 5;
              continue;
            }

            if ($isInvalidKey || $isQuotaExhausted || $isUnavailable || $isInternalError) {
              // FIX : si on arrive ici avec $isQuotaExhausted, c'est que la
              // patience courte n'a pas suffi — on considere alors que c'est
              // un vrai quota epuise (journalier/mensuel) et on bascule sur
              // la clé suivante (ou verrou minuit si derniere clé).
              $reason = match (true) {
                $isQuotaExhausted => 'QUOTA EPUISE (429) APRES PATIENCE COURTE',
                $isInvalidKey && (stripos($errorMessage, 'leaked') !== false || $statusCode === 403)
                  => 'CLE REVOQUEE/LEAKED (' . $statusCode . ')',
                $isInvalidKey     => 'CLE INVALIDE (' . $statusCode . ')',
                $isInternalError  => 'ERREUR INTERNE SERVEUR (500) APRES RETRIES',
                default           => 'SERVICE INDISPONIBLE (' . $statusCode . ') APRES RETRIES',
              };

              if ($index === $lastKeyIndex) {
                $logger->warning('liveSearch : TOUTES les cles Gemini HS (y compris la derniere @key...) — cause : @reason. Détail: @body', [
                  '@reason' => $reason,
                  '@key'    => $keyPreview,
                  '@body'   => $this->escapeForLog((string) $errorBody),
                ]);

                if ($isQuotaExhausted) {
                  $this->state->set($quota_key, time());
                  $retry_at = date('Y-m-d H:i:s', mktime(0, 0, 0, (int) date('n'), (int) date('j') + 1, (int) date('Y')));
                  return [
                    'success'  => false,
                    'error'    => 'gemini_quota_exhausted',
                    'retry_at' => $retry_at,
                    'content'  => '[]',
                  ];
                }

                if ($isInvalidKey) {
                  return ['success' => false, 'error' => 'gemini_keys_expired', 'content' => '[]'];
                }

                return ['success' => false, 'error' => 'gemini_service_unavailable_or_internal', 'content' => '[]'];
              }

              // On quitte la boucle while() pour cette clé (via break) et le
              // foreach passe naturellement a $api_keys[$index+1].
              $logger->warning('liveSearch GEMINI @reason (@key...), passage a la clé suivante. Détail: @body', [
                '@reason' => $reason,
                '@key'    => $keyPreview,
                '@body'   => $this->escapeForLog((string) $errorBody),
              ]);

              break;
            }

            // Inclure service + aperçu de clé (5 premiers car.) + début du
            // prompt (100 car.) pour identifier rapidement quelle clé /
            // quel backend / quel appel a planté, sans logger la clé complète.
            $logger->error('liveSearch Erreur API critique et inattendue — service=@service clé=@key… HTTP @code | prompt=@prompt… | @error', [
              '@service' => 'Gemini (Google AI / generativelanguage.googleapis.com)',
              '@key'     => substr($api_key, 0, 5),
              '@code'    => $statusCode,
              '@prompt'  => $this->escapeForLog(mb_substr((string) $prompt, 0, 100)),
              '@error'   => $this->escapeForLog((string) $errorBody),
            ]);
            return ['success' => false, 'error' => $errorBody, 'content' => '[]'];
          }
        }

        if ($response === null) {
          return ['success' => false, 'error' => 'all_gemini_keys_expired', 'content' => '[]'];
        }
      } else {
        return ['success' => false, 'error' => 'Engine non supporte'];
      }

      // -----------------------------------------------------------------------
      // LECTURE DU BODY BRUT EN SUCCÈS
      // -----------------------------------------------------------------------
      $raw = (string) $response->getBody();
      //$this->logUtf8Debug('LOG1_RAW_BODY', $raw, $logger);

      $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
      $logger->debug('liveSearch Raw API Response: @raw', ['@raw' => $this->escapeForLog($raw)]);

      // --- EXTRACTION DU TEXTE BRUT ---
      if ($engine == 'grok') {
        if (isset($body['output']) && is_array($body['output'])) {
          foreach ($body['output'] as $message) {
            if (isset($message['content']) && is_array($message['content'])) {
              foreach ($message['content'] as $item) {
                if (isset($item['type']) && $item['type'] === 'output_text') {
                  $text = $item['text'];
                  //$this->logUtf8Debug('LOG2_GROK_AVANT_SANITIZE', $text, $logger);
                  $text = $this->sanitizeUtf8($text);
                  //$this->logUtf8Debug('LOG3_GROK_APRES_SANITIZE', $text, $logger);
                  break 2;
                }
              }
            }
          }
        }
      } elseif ($engine == 'openrouter') {
        $text = $body['choices'][0]['message']['content'] ?? '';
        $text = $this->sanitizeUtf8($text);

        // Log complet de l'objet usage : c'est ici qu'apparaissent les
        // compteurs type "web_search_requests" permettant de confirmer si le
        // plugin web a reellement ete sollicite pour CETTE requete precise
        // (comportement observe comme non garanti a 100% selon le modele).
        if (isset($body['usage'])) {
          $logger->info('liveSearch OpenRouter usage : @usage', [
            '@usage' => $this->escapeForLog(json_encode($body['usage'], JSON_UNESCAPED_UNICODE) ?: ''),
          ]);
        }

        // Diagnostic : verifie que le plugin web a bien ete sollicite
        // (annotations/citations presentes) plutot que le modele ayant
        // repondu uniquement depuis ses connaissances figees.
        $citations = $body['choices'][0]['message']['annotations'] ?? [];
        if (empty($citations)) {
          $logger->warning('liveSearch OpenRouter : aucune annotation/citation web detectee dans la reponse — le modele a peut-etre repondu sans recherche reelle.');
        } else {
          $logger->info('liveSearch OpenRouter : @count annotation(s) web detectee(s).', ['@count' => count($citations)]);

          // L'exclusion de senlutteurs.com est une consigne "molle" (system
          // prompt), pas une exclusion dure comme pour Grok (excluded_domains).
          // On verifie donc explicitement que le modele l'a bien respectee.
          foreach ($citations as $citation) {
            $citedUrl = $citation['url_citation']['url'] ?? '';
            if (stripos($citedUrl, 'senlutteurs.com') !== false) {
              $logger->warning('liveSearch OpenRouter : citation senlutteurs.com detectee malgre la consigne d\'exclusion ("@url").', [
                '@url' => $citedUrl,
              ]);
              break;
            }
          }
        }
      } elseif ($engine == 'gemini') {
        $parts = $body['candidates'][0]['content']['parts'] ?? [];

        $text = '';
        foreach ($parts as $partIdx => $part) {
          if (isset($part['text'])) {
            //$this->logUtf8Debug('LOG2_GEMINI_PART_' . $partIdx, $part['text'], $logger);
            $text .= $part['text'];
          }
        }

        //$this->logUtf8Debug('LOG3_GEMINI_CONCAT_AVANT_SANITIZE', $text, $logger);
        $text = $this->sanitizeUtf8($text);
        $this->logUtf8Debug('LOG4_GEMINI_APRES_SANITIZE', $text, $logger);

        $thoughtsTokens = $body['usageMetadata']['thoughtsTokenCount'] ?? 0;
        $outputTokens   = $body['usageMetadata']['candidatesTokenCount'] ?? 0;
        if ($thoughtsTokens > 0) {
          $logger->info('liveSearch Gemini tokens - thinking: @t, output: @o, total: @tot', [
            '@t'   => $thoughtsTokens,
            '@o'   => $outputTokens,
            '@tot' => $body['usageMetadata']['totalTokenCount'] ?? 0,
          ]);
        }
      }

      // --- DETECTION MAX_TOKENS GEMINI ---
      if ($engine == 'gemini') {
        $finishReason = $body['candidates'][0]['finishReason'] ?? '';
        if ($finishReason === 'MAX_TOKENS') {
          $recovered = $this->recoverPartialJsonArray($text);

          if (!empty($recovered)) {
            $logger->warning('liveSearch Gemini MAX_TOKENS : @count item(s) recupere(s).', [
              '@count' => count($recovered),
            ]);

            $encoded = json_encode($recovered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            $logger->info('LOG5_MAX_TOKENS json_encode — result : @ok / erreur : @err', [
              '@ok'  => ($encoded !== false) ? 'OK' : 'ECHEC',
              '@err' => json_last_error_msg(),
            ]);

            if ($encoded === false) {
              $logger->warning('liveSearch MAX_TOKENS json_encode a echoue meme avec SUBSTITUTE.');
              return ['success' => false, 'error' => 'json_encode_failed_max_tokens', 'content' => '[]'];
            }

            $this->state->set($state_key, time() + 86400);
            $logger->info('liveSearch Succes partiel (@engine / MAX_TOKENS).', [
              '@engine' => $engine,
              '@name'   => $this->escapeForLog($prompt),
            ]);
            return ['success' => true, 'content' => $encoded, 'raw' => $body];
          }

          $logger->warning('liveSearch Gemini MAX_TOKEN : aucun item recuperable.');
          return ['success' => false, 'error' => 'max_tokens_reached', 'content' => '[]'];
        }
      }

      // --- NETTOYAGE SÉCURISÉ ---
      $text = SomeTools::extractPureJson($text);
      $text = $this->sanitizeUtf8($text); // re-sanitize après extraction, quel que soit l'engine
      $this->logUtf8Debug('LOG6_AVANT_JSON_DECODE_FINAL', $text, $logger);

      $data = json_decode($text, true);

      $logger->info('LOG7_json_decode final — erreur : @err | nb items : @nb', [
        '@err' => json_last_error_msg(),
        '@nb'  => is_array($data) ? count($data) : 'N/A',
      ]);

      if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return ['success' => false, 'error' => 'invalid_json', 'content' => '[]'];
      }

      // Tableau vide
      if (empty($data)) {
        if ($mode === 'diff') {
          if ($engine != 'gemini') {
            $this->state->set($state_key, time() + 86400);
          }
          $logger->info('liveSearch SUCCESS diff (existant confirmés) via @engine.', ['@engine' => $engine]);
          return ['success' => true, 'content' => '[]'];
        }
        return ['success' => false, 'error' => 'invalid_json', 'content' => '[]'];
      }

      //$logger->info('LOG8_avant_json_encode_final — items : @nb', ['@nb' => count($data)]);
      $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

      if ($encoded === false) {
        $logger->warning('LOG9_ECHEC — json_encode ECHEC. Tentative deep array_walk.');

        array_walk_recursive($data, function (&$val) {
          if (is_string($val)) {
            $clean = mb_convert_encoding($val, 'UTF-8', 'UTF-8');
            if ($clean === false || !mb_check_encoding($clean, 'UTF-8')) {
              $clean = iconv('UTF-8', 'UTF-8//IGNORE', $val);
            }
            $val = ($clean !== false) ? $clean : '';
          }
        });

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
      }

      if ($encoded === false) {
        return [
          'success' => false,
          'error'   => 'json_encode_failed_after_deep_sanitize: ' . json_last_error_msg(),
          'content' => '[]',
        ];
      }

      if ($engine != 'gemini') {
        $this->state->set($state_key, time() + 86400);
      }

      $logger->info('LiveSearch SUCCESS : @data', ['@data' => $this->escapeForLog($encoded)]);
      return ['success' => true, 'content' => $encoded, 'raw' => $body];
    } catch (\Exception $e) {
      $rawMsg = $e->getMessage();
      $isTimeout = stripos($rawMsg, 'timed out') !== false
        || stripos($rawMsg, 'cURL error 28') !== false
        || stripos($rawMsg, 'operation timed out') !== false;

      if ($isTimeout) {
        // Exceptionnel : un timeout réseau est un cas attendu et transitoire,
        // pas une erreur applicative — on le loggue en info pour ne pas
        // polluer les rapports d'erreurs. On ne loggue JAMAIS $rawMsg tel
        // quel ici : pour Gemini, l'URL contient la clé API en clair
        // (?key=...), donc le message brut ne doit jamais atteindre les
        // logs, quel que soit le niveau (bug corrigé le 2026-09-29).
        $logger->info('liveSearch : timeout réseau (cURL 28) sur @engine, considéré comme transitoire.', [
          '@engine' => $engine ?? 'inconnu',
        ]);
        return ['success' => false, 'error' => 'network_timeout', 'content' => '[]'];
      }

      // Tout le reste (erreur applicative réelle) reste en error, mais on
      // assainit quand même le message au cas où il contiendrait une URL
      // avec une clé API (Gemini notamment).
      $logger->error('Erreur LiveSearch : @msg', ['@msg' => $this->redactApiKeyFromMessage($rawMsg)]);
      return ['success' => false, 'error' => $rawMsg, 'content' => '[]'];
    }
  }
}  // fin classe LiveSearchService