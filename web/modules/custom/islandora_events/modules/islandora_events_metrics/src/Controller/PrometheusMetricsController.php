<?php

declare(strict_types=1);

namespace Drupal\islandora_events_metrics\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\islandora_events_metrics\Service\MetricsCollector;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes Prometheus-style text metrics.
 */
final class PrometheusMetricsController implements ContainerInjectionInterface {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private MetricsCollector $collector,
  ) {}

  /**
   * Creates the controller.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('islandora_events_metrics.collector'));
  }

  /**
   * Returns the metrics exposition.
   */
  public function metrics(): Response {
    return new Response(
      $this->collector->renderPrometheus(),
      200,
      ['Content-Type' => 'text/plain; version=0.0.4; charset=UTF-8'],
    );
  }

}
