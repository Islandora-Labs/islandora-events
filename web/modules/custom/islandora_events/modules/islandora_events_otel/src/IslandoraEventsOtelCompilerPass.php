<?php

declare(strict_types=1);

namespace Drupal\islandora_events_otel;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;

/**
 * Appends OTEL trace middleware to SM buses.
 */
final class IslandoraEventsOtelCompilerPass implements CompilerPassInterface {

  /**
   * {@inheritdoc}
   */
  public function process(ContainerBuilder $container): void {
    try {
      /** @var array<string, array{middleware: array<mixed>}> $buses */
      $buses = $container->getParameter('sm.buses');
    }
    catch (ParameterNotFoundException) {
      $buses = [];
    }

    foreach ($buses as $id => $bus) {
      $buses[$id]['middleware'][] = ['id' => 'islandora_events_otel.trace_context'];
    }

    $container->setParameter('sm.buses', $buses);
  }

}
