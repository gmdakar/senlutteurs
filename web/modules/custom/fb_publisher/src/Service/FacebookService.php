<?php
namespace Drupal\fb_publisher\Service;

use GuzzleHttp\Client;
use Drupal\Core\Config\ConfigFactoryInterface;

class FacebookService {

  protected $config;
  protected $http;

  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->config = $config_factory->get('fb_publisher.settings');
    $this->http = new Client(['base_uri' => 'https://graph.facebook.com/']);
  }

  /**
   * Poste un message sur la page Facebook.
   *
   * @param string $message
   *   Le texte à poster.
   *
   * @return string
   *   L'ID du post créé.
   *
   * @throws \Exception
   */
  public function postToPage(string $message): string {
    $page_id = $this->config->get('page_id');
    $page_access_token = trim($this->config->get('page_access_token'));

    if (empty($page_id) || empty($page_access_token)) {
      throw new \Exception('Page ID ou Page Access Token manquant.');
    }

    // POST sur l'API Graph Facebook
    $response = $this->http->post("v24.0/{$page_id}/feed", [
      'form_params' => [
        'message' => $message,
        'access_token' => $page_access_token,
      ],
    ]);

    $data = json_decode($response->getBody()->getContents(), TRUE);

    if (!isset($data['id'])) {
      throw new \Exception('Échec de la publication du message.');
    }

    return $data['id'];
  }

}
