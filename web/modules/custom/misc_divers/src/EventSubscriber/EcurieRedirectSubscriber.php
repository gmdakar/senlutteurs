<?php

namespace Drupal\misc_divers\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class EcurieRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected RouteMatchInterface $routeMatch
  ) {}

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $node = $this->routeMatch->getParameter('node');

    if (is_numeric($node)) {
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($node);
    }

    if (!$node instanceof NodeInterface || $node->bundle() !== 'ecurie') {
      return;
    }

    $url = Url::fromUri('internal:/lutteurs', [
      'query' => ['field_ecurie2' => $node->id()],
    ])->toString();

    $event->setResponse(new TrustedRedirectResponse($url, 301));
  }

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
    ];
  }

}
