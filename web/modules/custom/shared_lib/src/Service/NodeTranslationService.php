<?php

namespace Drupal\shared_lib\Service;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Garantit qu'un node traductible existe dans TOUTES les langues du site.
 *
 * Principe :
 *  - À la création : chaque langue du site (hors langue d'origine du node) reçoit
 *    une traduction initiale = copie des champs traduisibles du node source.
 *  - À la mise à jour : une traduction qui est encore une copie « intacte » de
 *    la source suit les modifications de la source (titre, statut publié/dépublié,
 *    flags traduisibles…). Une traduction modifiée à la main (ou par une vraie
 *    traduction IA/humaine) n'est JAMAIS écrasée.
 *  - Les langues manquantes (ex. traduction supprimée, nouvelle langue ajoutée
 *    au site, ancien node) sont recréées au prochain save.
 *
 * Tout se passe en mémoire pendant hook_node_presave() : les traductions sont
 * persistées par le même $node->save() que le node (pas de double save, pas de
 * récursion). Voir shared_lib_node_presave().
 */
class NodeTranslationService {

  /**
   * Champs jamais copiés vers une traduction.
   */
  const EXCLUDED_FIELDS = [
    'nid', 'vid', 'uuid', 'type', 'langcode', 'default_langcode',
    'path', 'changed', 'feeds_item', 'revision_translation_affected',
    'revision_timestamp', 'revision_uid', 'revision_log',
  ];

  protected LanguageManagerInterface $languageManager;
  protected EntityTypeBundleInfoInterface $bundleInfo;
  protected $logger;
  protected $contentTranslationManager;

  public function __construct(
    LanguageManagerInterface $languageManager,
    EntityTypeBundleInfoInterface $bundleInfo,
    LoggerChannelFactoryInterface $loggerFactory,
    $contentTranslationManager = NULL
  ) {
    $this->languageManager = $languageManager;
    $this->bundleInfo = $bundleInfo;
    $this->logger = $loggerFactory->get('shared_lib');
    $this->contentTranslationManager = $contentTranslationManager;
  }

  /**
   * Le bundle node est-il déclaré traductible ?
   */
  public function isBundleTranslatable(string $bundle): bool {
    $info = $this->bundleInfo->getBundleInfo('node');
    return !empty($info[$bundle]['translatable']);
  }

  /**
   * Codes de toutes les langues configurables du site (hors und/zxx).
   */
  public function getSiteLanguageIds(): array {
    return array_keys($this->languageManager->getLanguages());
  }

  /**
   * Ce node doit-il être traité ? (node source, traductible, site multilingue)
   */
  public function canProcess(NodeInterface $node): bool {
    if (!$node->isDefaultTranslation()) {
      return FALSE;
    }
    if (!empty($node->_skip_translation_sync)) {
      return FALSE;
    }
    if ($node->isSyncing()) {
      return FALSE;
    }
    // Vérifie : bundle traductible + langue source non verrouillée (und/zxx)
    // + site multilingue.
    return $node->isTranslatable();
  }

  /**
   * Langues du site pour lesquelles le node n'a pas encore de traduction.
   */
  public function getMissingLanguageIds(NodeInterface $node): array {
    if (!$this->canProcess($node)) {
      return [];
    }
    $existing = array_keys($node->getTranslationLanguages());
    $missing = array_diff($this->getSiteLanguageIds(), $existing);

    // Suppression volontaire d'une traduction (formulaire « Supprimer la
    // traduction ») : Drupal retire la traduction puis sauvegarde le node
    // source. Les langues présentes dans l'original mais absentes du node en
    // cours de sauvegarde ont été retirées dans CE save : on ne les recrée pas,
    // sinon la suppression est annulée aussitôt (bug « impossible de supprimer »).
    // Elles seront recréées au prochain save « normal » du node (cron, import,
    // force-update-nodes), conformément à l'exigence « toutes les langues ».
    if (!$node->isNew()) {
      $original = method_exists($node, 'getOriginal') ? $node->getOriginal() : ($node->original ?? NULL);
      if ($original instanceof NodeInterface) {
        $removed = array_diff(array_keys($original->getTranslationLanguages()), $existing);
        $missing = array_diff($missing, $removed);
      }
    }
    return array_values($missing);
  }

  /**
   * Crée (en mémoire) les traductions manquantes. Ne sauvegarde pas.
   *
   * @return string[]
   *   Codes de langue des traductions ajoutées.
   */
  public function ensureTranslations(NodeInterface $node): array {
    $missing = $this->getMissingLanguageIds($node);
    if (!$missing) {
      return [];
    }

    $values = $this->collectSourceValues($node);
    $created = [];
    foreach ($missing as $langcode) {
      try {
        $translation = $node->addTranslation($langcode, $values);
        $this->markTranslationSource($node, $translation);
        $created[] = $langcode;
      }
      catch (\Throwable $e) {
        $this->logger->warning('Création traduction @lang impossible pour @type "@title" (nid @nid) : @msg', [
          '@lang' => $langcode,
          '@type' => $node->bundle(),
          '@title' => $node->label(),
          '@nid' => $node->id() ?? 'nouveau',
          '@msg' => $e->getMessage(),
        ]);
      }
    }

    if ($created) {
      $this->logger->debug('Traductions ajoutées (@langs) pour @type "@title" (nid @nid).', [
        '@langs' => implode(',', $created),
        '@type' => $node->bundle(),
        '@title' => $node->label(),
        '@nid' => $node->id() ?? 'nouveau',
      ]);
    }
    return $created;
  }

  /**
   * Répercute sur les traductions « intactes » les changements de la source.
   *
   * Pour chaque champ traduisible modifié dans la source : si la traduction
   * portait encore exactement l'ancienne valeur de la source (= copie non
   * retouchée), elle reçoit la nouvelle valeur. Sinon elle est laissée telle
   * quelle. Ne sauvegarde pas.
   *
   * @return string[]
   *   Codes de langue dont au moins un champ a été resynchronisé.
   */
  public function syncUnmodifiedTranslations(NodeInterface $node): array {
    if ($node->isNew() || !$this->canProcess($node)) {
      return [];
    }
    $original = method_exists($node, 'getOriginal') ? $node->getOriginal() : ($node->original ?? NULL);
    if (!$original instanceof NodeInterface) {
      return [];
    }

    $newSource = $node->getUntranslated();
    $oldSource = $original->getUntranslated();
    $fields = $this->getCopyableFieldNames($newSource);
    $synced = [];

    foreach (array_keys($node->getTranslationLanguages(FALSE)) as $langcode) {
      if (!$original->hasTranslation($langcode)) {
        // Traduction tout juste créée : déjà copiée depuis la source actuelle.
        continue;
      }
      $translation = $node->getTranslation($langcode);
      $oldTranslation = $original->getTranslation($langcode);

      foreach ($fields as $name) {
        if (!$oldSource->hasField($name) || !$oldTranslation->hasField($name)) {
          continue;
        }
        $oldSourceValue = $this->normalize($oldSource->get($name));
        $newSourceValue = $this->normalize($newSource->get($name));
        if ($oldSourceValue === $newSourceValue) {
          continue;
        }
        if ($this->normalize($oldTranslation->get($name)) !== $oldSourceValue) {
          // Traduction personnalisée : on n'y touche pas.
          continue;
        }
        $translation->set($name, $newSource->get($name)->getValue());
        $synced[$langcode] = $langcode;
      }
    }
    return array_values($synced);
  }

  /**
   * Point d'entrée hook_node_presave : resynchronise puis complète.
   *
   * @return array
   *   ['created' => string[], 'synced' => string[]]
   */
  public function processBeforeSave(NodeInterface $node): array {
    return [
      'synced' => $this->syncUnmodifiedTranslations($node),
      'created' => $this->ensureTranslations($node),
    ];
  }

  /**
   * Valeurs des champs traduisibles de la source, prêtes pour addTranslation().
   */
  protected function collectSourceValues(NodeInterface $node): array {
    $source = $node->getUntranslated();
    $values = [];
    foreach ($this->getCopyableFieldNames($source) as $name) {
      $values[$name] = $source->get($name)->getValue();
    }
    return $values;
  }

  /**
   * Noms des champs traduisibles à copier/synchroniser.
   */
  protected function getCopyableFieldNames(NodeInterface $source): array {
    $names = [];
    foreach ($source->getFieldDefinitions() as $name => $definition) {
      if (!$definition->isTranslatable() || $definition->isComputed()) {
        continue;
      }
      if (in_array($name, self::EXCLUDED_FIELDS, TRUE)
        || str_starts_with($name, 'content_translation_')
        || str_starts_with($name, 'revision_')) {
        continue;
      }
      $names[] = $name;
    }
    return $names;
  }

  /**
   * Représentation comparable (colonnes de stockage uniquement) d'un champ.
   */
  protected function normalize(FieldItemListInterface $list): string {
    $columns = array_keys($list->getFieldDefinition()->getFieldStorageDefinition()->getColumns());
    $rows = [];
    foreach ($list as $item) {
      if ($item->isEmpty()) {
        continue;
      }
      $value = $item->getValue();
      $row = [];
      foreach ($columns as $column) {
        $v = $value[$column] ?? NULL;
        $row[$column] = is_scalar($v) || $v === NULL ? (string) $v : serialize($v);
      }
      $rows[] = $row;
    }
    return json_encode($rows);
  }

  /**
   * Renseigne la langue source dans les métadonnées content_translation.
   */
  protected function markTranslationSource(NodeInterface $source, NodeInterface $translation): void {
    if (!$this->contentTranslationManager) {
      return;
    }
    try {
      $this->contentTranslationManager
        ->getTranslationMetadata($translation)
        ->setSource($source->getUntranslated()->language()->getId());
    }
    catch (\Throwable $e) {
      // Métadonnées non essentielles : on ignore.
    }
  }

}
