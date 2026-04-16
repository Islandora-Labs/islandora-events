<?php

namespace Drupal\islandora_events_backfill\Message;

use Drupal\islandora_events\Message\IslandoraDerivativeMessage;

/**
 * Marks a derivative message as originating from the backfill scanner.
 */
readonly class IslandoraBackfillDerivativeMessage extends IslandoraDerivativeMessage {}
