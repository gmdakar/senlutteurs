<?php

namespace Drupal\shared_lib\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\shared_lib\Tools\SomeTools;
use Drupal\node\NodeInterface;

/**
 * Base class for combat-related QueueWorkers.
 */
abstract class BaseCombatQueueWorker extends QueueWorkerBase {
    use StringTranslationTrait;

    protected ConfigFactoryInterface $configFactory;
    protected ClientInterface $httpClient;
    protected LoggerInterface $logger;
    protected EntityTypeManagerInterface $entityTypeManager;

    public function __construct(
        array $configuration,
        $plugin_id,
        $plugin_definition,
        ConfigFactoryInterface $config_factory,
        ClientInterface $http_client,
        LoggerInterface $logger,
        EntityTypeManagerInterface $entity_type_manager
    ) {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->configFactory = $config_factory;
        $this->httpClient = $http_client;
        $this->logger = $logger;
        $this->entityTypeManager = $entity_type_manager;
    }

    public function getOrCreateAthlete(string &$name, ?string $ecurie = null): ?NodeInterface {
        if (trim($name) === '') {
            return null;
        }
        return SomeTools::getOrCreateAthlete(
            $name,
            $this->entityTypeManager,
            $this->logger,
            $ecurie
        );
    }

    public function combatExists($fightId, $type): mixed {
        return SomeTools::combatExists($fightId, $type, $this->entityTypeManager, $this->logger);
    }

    public function correctNames(string $name): string {
        return SomeTools::correctNames($name);
    }

    public function technicalWrestlerName(string $name): string {
        return SomeTools::technicalWrestlerName($name);
    }

    public function normalizeDate(?string $raw): ?\DateTime {
        if (empty($raw)) {
            return null;
        }

        $raw = trim($raw);

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                return new \DateTime($raw . ' 16:00:00', new \DateTimeZone('UTC'));
            }

            $months = [
                'janvier' => 'January', 'février' => 'February', 'mars' => 'March',
                'avril' => 'April', 'mai' => 'May', 'juin' => 'June',
                'juillet' => 'July', 'août' => 'August', 'septembre' => 'September',
                'octobre' => 'October', 'novembre' => 'November', 'décembre' => 'December',
            ];

            $clean = mb_strtolower($raw);
            $clean = strtr($clean, $months);
            $clean = str_replace('T', ' ', $clean);

            return new \DateTime($clean, new \DateTimeZone('UTC'));

        } catch (\Exception $e) {
            \Drupal::logger('confrontations_recentes')->warning('Impossible de normaliser la date "@date": @error', [
                '@date' => $raw,
                '@error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function arrayIsEmpty(?array $arr): bool {
        if (empty($arr) || !is_array($arr)) {
            return TRUE;
        }
        foreach ($arr as $v) {
            if (is_array($v) && !empty($v['uri'])) {
                return FALSE;
            }
        }
        return TRUE;
    }

    /**
     * @param int $maxVideoCombatAgeDays
     *   Si > 0 : exclut les video_combat de plus de N jours (ex: 5 pour cri).
     */
    public function searchYoutubeVideos(string $date, string $wrestler1, string $wrestler2, int $maxVideoCombatAgeDays = 0): array {
        return SomeTools::searchYoutubeVideosBase($date, $wrestler1, $wrestler2, $maxVideoCombatAgeDays);
    }

    /**
     * Recherche des vidéos les plus récentes (Latest)
     */
    public function searchYoutubeVideosLatests(string $wrestler1, string $wrestler2): array {
        return SomeTools::searchYoutubeVideosLatestsBase($wrestler1, $wrestler2);
    }

    public function urlsToUriArray(?array $urls): array {
        return SomeTools::urlsToUriArrayBase($urls);
    }

    /**
     * Met à jour les vidéos d'une confrontation (Passé).
     * Ne supprime l'existant que si de nouveaux résultats sont trouvés.
     * @param boolean $resetVideos : true si on veut effacer toutes videos existantes pour 
     * chaque categorie (face2face / video_combat) même s'il n ya aucun resultat videos dans ces categories
     */
    public function updateVideosConfrontation($item, $resetVideos = false) {
        \Drupal::logger('shared_lib')->debug('Traitement Vidéos Confrontation / Nid : ' . $item);

        $node = $item instanceof \Drupal\node\NodeInterface ? $item : \Drupal\node\Entity\Node::load($item);
        if (!$node) return;

        $fight_name_lutteur1 = $fight_name_lutteur2 = '';

        if (!SomeTools::isYoutubeQuotaAvailable()) //si quota Youtube toujours expiré
            return; //on sort direct pas la peine même de continuer

        // Récupération des noms des lutteurs
        if ($node->hasField('field_athlete_1') && !$node->get('field_athlete_1')->isEmpty()) {
            $athlete1 = $node->get('field_athlete_1')->entity;
            $fight_name_lutteur1 = $athlete1 ? $athlete1->getTitle() : '';
        }
        if ($node->hasField('field_athlete_2') && !$node->get('field_athlete_2')->isEmpty()) {
            $athlete2 = $node->get('field_athlete_2')->entity;
            $fight_name_lutteur2 = $athlete2 ? $athlete2->getTitle() : '';
        }

        // Récupération de la date
        $dateObj = null;
        if ($node->hasField('field_confrontation_date') && !$node->get('field_confrontation_date')->isEmpty()) {
            $date_value = $node->get('field_confrontation_date')->value;
            try {
                $dateObj = new \DateTime($date_value);
            } catch (\Exception $e) { return; }
        }

        if (!$dateObj || !$fight_name_lutteur1 || !$fight_name_lutteur2) {
            \Drupal::logger('shared_lib')->warning('updateVideosConfrontation Données insuffisantes pour le Nid @nid.', ['@nid' => $node->id()]);
            return;
        }

        // Recherche YouTube
        $newUris = $this->searchYoutubeVideos(
            $dateObj->format('Y-m-d'),
            $fight_name_lutteur1,
            $fight_name_lutteur2
        );

        $hasChanged = false;

        // --- LOGIQUE FACE 2 FACE ---
        $hasNewF2F = !empty($newUris['face2face']) && is_array($newUris['face2face']);
        
        // Condition : On reset (forcé) OU on a du nouveau (comportement original)
        if ($resetVideos || $hasNewF2F) {
            $node->set('field_face_2_face', []); // On vide
            if ($hasNewF2F) {
                $node->get('field_face_2_face')->setValue(
                    array_map(fn($uri) => ['uri' => $uri], array_slice($newUris['face2face'], 0, 4))
                );
            }
            $hasChanged = true;
        }

        // --- LOGIQUE VIDÉO DU COMBAT ---
        $hasNewCombat = !empty($newUris['video_combat']) && is_array($newUris['video_combat']);

        if ($resetVideos || $hasNewCombat) {
            $node->set('field_video_du_combat', []); // On vide
            if ($hasNewCombat) {
                $node->get('field_video_du_combat')->setValue(
                    array_map(fn($uri) => ['uri' => $uri], array_slice($newUris['video_combat'], 0, 2))
                );
            }
            $hasChanged = true;
        }

        if ($hasChanged) {
            $node->setNewRevision(FALSE); 
            $node->_skip_other_only_update_videos = TRUE; // flag custom FICTIF sert uniquement pour éviter lors d'update video de faire plein d'autre traitement non essentiels
            $node->save();
            \Drupal::logger('shared_lib')->info('updateVideosConfrontation Mise à jour : @title (Mode Reset: @reset).', [
                '@title' => $node->getTitle(),
                '@reset' => $resetVideos ? 'Oui' : 'Non'
            ]);
        }
    }

    /**
     * Force update videos for NEXT fights (avenir)
     */
    public function updateVideosCombatNext($item) {
        \Drupal::logger('shared_lib')->debug('Nid /' . $item);

        $node = $item instanceof NodeInterface ? $item : \Drupal\node\Entity\Node::load($item);
        if (!$node) return;

        $fight_name_lutteur1 = $fight_name_lutteur2 = '';

        if ($node->hasField('field_lutteur_1') && !$node->get('field_lutteur_1')->isEmpty()) {
            $athlete1 = $node->get('field_lutteur_1')->entity;
            $fight_name_lutteur1 = $athlete1 ? $athlete1->getTitle() : '';
        }

        if ($node->hasField('field_lutteur_2') && !$node->get('field_lutteur_2')->isEmpty()) {
            $athlete2 = $node->get('field_lutteur_2')->entity;
            $fight_name_lutteur2 = $athlete2 ? $athlete2->getTitle() : '';
        }

        $dateObj = null;
        if ($node->hasField('field_date') && !$node->get('field_date')->isEmpty()) {
            $date_value = $node->get('field_date')->value;
            $dateObj = new \DateTime($date_value);
        }

        if (!$dateObj || !$fight_name_lutteur1 || !$fight_name_lutteur2) {
            return;
        }

        \Drupal::logger('shared_lib')->debug('Next fight found!');

        // APPEL CORRECT : on passe uniquement les 2 lutteurs (pas de date)
        $newUris = $this->searchYoutubeVideosLatests(
            $fight_name_lutteur1,
            $fight_name_lutteur2
        );

        \Drupal::logger('shared_lib')->notice('Videos found (latest): {data}', [
            'data' => print_r($newUris, TRUE),
        ]);

        $hasChanged = false;

        $processField = function($fieldName, $newVideos) use ($node, &$hasChanged) {
            if (empty($newVideos) || !is_array($newVideos)) return;

            if (!$node->hasField($fieldName)) return;

            $existing = $node->get($fieldName)->getValue();
            $allVideos = [];

            foreach ($existing as $item) {
                if (!empty($item['uri'])) {
                    $allVideos[$item['uri']] = ['uri' => $item['uri'], 'videoDate' => 0];
                }
            }

            foreach ($newVideos as $video) {
                if (!empty($video['url'])) {
                    $allVideos[$video['url']] = [
                        'uri' => $video['url'],
                        'videoDate' => $video['videoDate'] ?? 0
                    ];
                }
            }

            $allVideos = array_values($allVideos);
            usort($allVideos, fn($a, $b) => $b['videoDate'] <=> $a['videoDate']);
            $top4 = array_slice($allVideos, 0, 4);
            usort($top4, fn($a, $b) => $a['videoDate'] <=> $b['videoDate']);

            $finalUris = array_column($top4, 'uri');
            $existingUris = array_column($existing, 'uri');

            if ($finalUris !== $existingUris) {
                $node->set($fieldName, []);
                foreach ($finalUris as $uri) {
                    $node->get($fieldName)->appendItem(['uri' => $uri]);
                }
                $hasChanged = true;
            }
        };

        $processField('field_avenir_face2face', $newUris['face2face'] ?? []);
        $processField('field_avenir_video_combat', $newUris['video_combat'] ?? []);

        if ($hasChanged) {
            $node->setNewRevision(FALSE); 
            $node->save();
            \Drupal::logger('shared_lib')->info(
                'Mis à jour du next combat : @title',
                ['@title' => $node->getTitle()]
            );
        }
    }
}