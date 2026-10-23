<?php

namespace Drupal\shared_lib\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\shared_lib\Tools\SomeTools;
use Drupal\sl_live_search\Service\LiveSearchService;

/**
 * Base service for Wrestling modules.
 * Fournit des méthodes communes aux modules Gemini Wrestling et NextCombats.
 */
abstract class BaseWrestlingService
{
    use StringTranslationTrait;

    protected ClientInterface $httpClient;
    protected ConfigFactoryInterface $configFactory;
    protected EntityTypeManagerInterface $entityTypeManager;
    protected $logger;
    protected ?LiveSearchService $liveSearchService = NULL; // Ajoute le ? et le = NULL
    protected array $defaultResponses = [];

    // web/modules/custom/shared_lib/src/Service/BaseWrestlingService.php

    public function __construct(
        ClientInterface $httpClient,
        ConfigFactoryInterface $configFactory,
        EntityTypeManagerInterface $entityTypeManager,
        LoggerChannelFactoryInterface $loggerFactory,
        $liveSearchService = NULL // <-- ON RETIRE LE TYPE STRICT ET ON MET NULL
    ) {
        $this->httpClient = $httpClient;
        $this->configFactory = $configFactory;
        $this->entityTypeManager = $entityTypeManager;
        $this->logger = $loggerFactory->get('shared_lib');

        // On vérifie manuellement si c'est bien notre objet
        if ($liveSearchService instanceof \Drupal\sl_live_search\Service\LiveSearchService) {
            $this->liveSearchService = $liveSearchService;
        }
    }

    /**
     * Convert an array of URLs to Drupal file/uri structure.
     */
    public function urlsToUriArray(?array $urls): array
    {
        return SomeTools::urlsToUriArrayBase($urls);
    }

    /**
     * Search YouTube videos for the two wrestlers around a date.
     */
    public function searchYoutubeVideos(string $date, string $wrestler1, string $wrestler2): array
    {
        return SomeTools::searchYoutubeVideosBase($date, $wrestler1, $wrestler2);
    }

    protected function correctNames(string $texte): string
    {
        return SomeTools::correctNames($texte);
    }

    protected function technicalWrestlerName(string $name): string
    {
        return SomeTools::technicalWrestlerName($name);
    }

    protected function getNodeByTitleCached(string $title, string $type): ?Node
    {
        static $nodes = [];
        $cacheKey = $type . '_' . md5($title);
        if (isset($nodes[$cacheKey])) {
            return $nodes[$cacheKey];
        }

        $query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->accessCheck(FALSE)
            ->condition('type', $type)
            ->condition('title', $title)
            ->condition('status', 1)
            ->execute();

        $node = null;
        if ($query) {
            $node = $this->entityTypeManager->getStorage('node')->load(reset($query));
        }

        $nodes[$cacheKey] = $node;
        return $node;
    }

    public function athleteExists(string $name): ?\Drupal\node\NodeInterface
    {
        $cleanName = $this->technicalWrestlerName($name);
        $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties([
            'type' => 'athlete',
            'field_athlete_nom_technique' => $cleanName,
        ]);
        return reset($nodes) ?: null;
    }

    public function combatExists($fightId, $type): mixed
    {
        return SomeTools::combatExists($fightId, $type, $this->entityTypeManager, $this->logger);
    }

    public function getOrCreateAthlete(string &$name, ?string $ecurie = null): ?\Drupal\node\NodeInterface
    {
        if (trim($name) === '') {
            return null;
        }

        return SomeTools::getOrCreateAthlete(
            $name,
            $this->entityTypeManager,
            $this->logger,
            $ecurie,
        );
    }

    /**
     * Retrieves confrontation nodes associated with a specific athlete, sorted by date.
     *
     * This function looks up an athlete by their title, finds all published
     * confrontation nodes where the athlete participates as either field_athlete_1
     * or field_athlete_2, and returns them ordered from the most recent to the oldest.
     *
     * @param string $athleteTitle
     *   The title (name) of the athlete node to search for.
     *
     * @return \Drupal\Core\Entity\EntityInterface[]|Node[]
     *   An array of loaded confrontation node entities, or an empty array if none are found.
     */
    public function getConfrontationsByAthlete(string $athleteTitle): array
    {
        $athleteNids = \Drupal::entityQuery('node')
            ->accessCheck(FALSE)
            ->condition('type', 'athlete')
            ->condition('title', $athleteTitle)
            ->condition('status', 1)
            ->execute();

        if (empty($athleteNids)) {
            return [];
        }

        $athleteNid = reset($athleteNids);

        $or = \Drupal::entityQuery('node')
            ->orConditionGroup()
            ->condition('field_athlete_1', $athleteNid)
            ->condition('field_athlete_2', $athleteNid);

        $confrontationNids = \Drupal::entityQuery('node')
            ->accessCheck(FALSE)
            ->condition('type', 'confrontation')
            ->condition('status', 1)
            ->condition($or)
            ->sort('field_confrontation_date', 'DESC')
            ->execute();

        return !empty($confrontationNids) ? Node::loadMultiple($confrontationNids) : [];
    }

    /**
     * Récupère les identifiants (NIDs) des derniers contenus d'un type spécifique.
     * * Signature préservée pour éviter les régressions :
     * @param string $contentType Le type de contenu.
     * @param int $N Le nombre maximum de résultats (par défaut 10). les plus récents (date de creation)
     * @param int $daysLimit La limite de jours (par défaut 7). 0 ou null pour désactiver le filtre.
     *
     * @return array Tableau de NIDs.
     */
    public function getLastContentsOf($contentType, $N = 10, $daysLimit = 7)
    {
        // 1. Initialisation de la requête
        $query = \Drupal::entityQuery('node')
            ->condition('type', $contentType)
            ->accessCheck(FALSE)
            ->condition('status', 1);

        // 2. FILTRE DE DATE (Optionnel selon $daysLimit)
        // On n'ajoute la condition que si une limite de jours est définie
        if ($daysLimit && $daysLimit > 0) {
            $dateLimit = new \DateTime();
            $dateLimit->modify("-" . (int) $daysLimit . " days");
            $query->condition('created', $dateLimit->getTimestamp(), '>=');
        }

        // 3. TRI ET LIMITE
        //$query->sort('field_confrontation_date', 'DESC')
        $query->sort('created', 'DESC') //les plus récents (date de creation)
            ->range(0, $N);

        // 4. Exécution
        $nids = $query->execute();

        return $nids;
    }

    public function getNotExpiredFights()
    {
        $current_date = gmdate('Y-m-d\TH:i:s');
        $nids = \Drupal::entityQuery('node')
            ->condition('type', 'a_venir')
            ->condition('status', 1)
            ->condition('field_date.value', $current_date, '>=')
            ->accessCheck(FALSE)
            ->sort('field_date.value', 'DESC')
            ->execute();

        return $nids;
    }

    protected function normalizeVideoField(array $value, int $limit = 2): array
    {
        $urls = [];

        foreach ($value as $item) {
            if (is_array($item) && isset($item['uri'])) {
                $item = $item['uri'];
            }
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }

            $decoded = urldecode($item);
            if (str_contains($decoded, ',')) {
                foreach (explode(',', $decoded) as $p) {
                    $p = trim($p);
                    if ($p !== '') {
                        $urls[] = $p;
                    }
                }
            } else {
                $urls[] = $decoded;
            }
        }

        $urls = array_unique($urls);
        $urls = array_slice($urls, 0, $limit);

        return array_map(fn($u) => ['uri' => $u], $urls);
    }

    /**
     * Nettoie le markdown éventuel, décode le JSON et log en critical si invalide.
     *
     * @param string $raw     La chaîne brute retournée par l'IA.
     * @param string $context Nom de la méthode appelante pour identifier l'origine dans les logs.
     *
     * @return array|null     Tableau PHP décodé, ou null si JSON invalide.
     */
    protected function decodeJson(string $raw, string $context = 'inconnu'): ?array
    {
        return SomeTools::decodeJson($raw, $context);
    }
}
