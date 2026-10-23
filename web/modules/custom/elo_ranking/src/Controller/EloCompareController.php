<?php

namespace Drupal\elo_ranking\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Url;

class EloCompareController extends ControllerBase {

  protected $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  public function compare($id1, $id2) {
    return new JsonResponse([
      'athlete1' => $this->getWrestlerHistory($id1),
      'athlete2' => $this->getWrestlerHistory($id2),
    ]);
  }

  /**
   * Récupère l'historique brut d'un lutteur avec son alias.
   */
  protected function getWrestlerHistory($nid) {
    $query = $this->database->select('elo_history', 'h')
      ->fields('h', ['fight_date', 'elo_after'])
      ->condition('wrestler_id', $nid)
      ->orderBy('fight_date', 'ASC')
      ->orderBy('elo_after', 'ASC')
      ->execute();

    $results = $query->fetchAll();
    
    $dates = [];
    $scores = [];
    
    foreach ($results as $row) {
      $dates[] = $row->fight_date;
      $scores[] = (float) $row->elo_after;
    }

    $node = Node::load($nid);
    $name = $node ? $node->getTitle() : "Lutteur $nid";
    
    // --- MODIFICATION ICI : Génération de l'alias ---
    $url = '';
    if ($node) {
      $url = Url::fromRoute('entity.node.canonical', ['node' => $nid])->toString();
    }

    return [
      'name' => $name,
      'url' => $url, // On envoie l'alias tout prêt au JS
      'dates' => $dates,
      'scores' => $scores,
    ];
  }
}