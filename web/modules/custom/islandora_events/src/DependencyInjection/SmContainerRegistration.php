<?php

namespace Drupal\islandora_events\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;

/**
 * Registers SM transport and routing config into the compiled container.
 *
 * SM reads transport and routing parameters from the compiled container, so
 * module-provided YAML must be merged during container build rather than at
 * runtime from regular service definitions.
 */
final class SmContainerRegistration {

  /**
   * Registers one module's SM transport and routing files.
   */
  public static function registerModuleFiles(
    ContainerBuilder $container,
    string $transportsPath,
    string $routingPath,
  ): void {
    $transportsConfig = Yaml::parseFile($transportsPath);
    $routingConfig = Yaml::parseFile($routingPath);

    $existingTransports = $container->hasParameter('sm.transports')
      ? $container->getParameter('sm.transports')
      : [];
    $existingRouting = $container->hasParameter('sm.routing')
      ? $container->getParameter('sm.routing')
      : [];

    $container->setParameter(
      'sm.transports',
      array_merge($existingTransports, $transportsConfig['transports'] ?? []),
    );
    $container->setParameter(
      'sm.routing',
      array_merge($existingRouting, self::normalizeRouting($routingConfig['routing'] ?? [])),
    );
  }

  /**
   * Normalizes messenger routing for the installed drupal/sm format.
   *
   * @param array<string, mixed> $routing
   *   Message class to transport mapping.
   *
   * @return array<string, string|array<int, string>>
   *   Normalized routing definitions.
   */
  public static function normalizeRouting(array $routing): array {
    $normalized = [];

    foreach ($routing as $messageClass => $mapping) {
      if (is_array($mapping) && isset($mapping['senders']) && is_array($mapping['senders'])) {
        // drupal/sm versions used by this project expect message-to-sender
        // routing as a plain list of sender aliases, not an associative array
        // with additional metadata such as failure_transport.
        $normalized[$messageClass] = array_values(array_filter($mapping['senders'], 'is_string'));
        continue;
      }

      if (is_string($mapping)) {
        $normalized[$messageClass] = $mapping;
        continue;
      }

      if (is_array($mapping)) {
        $normalized[$messageClass] = array_values(array_filter($mapping, 'is_string'));
      }
    }

    return $normalized;
  }

}
