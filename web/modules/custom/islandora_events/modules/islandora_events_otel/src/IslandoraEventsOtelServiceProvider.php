<?php

declare(strict_types=1);

namespace Drupal\islandora_events_otel;

use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Registers compiler passes for Islandora Events OTEL integration.
 */
final class IslandoraEventsOtelServiceProvider implements ServiceProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $container->addCompilerPass(new IslandoraEventsOtelCompilerPass(), priority: 100);
  }

}
