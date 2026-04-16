<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Unit;

use Drupal\islandora_events\Service\DefaultDerivativeRunnerDefaultsProvider;
use Drupal\Tests\UnitTestCase;

/**
 * Verifies built-in derivative defaults stay config-backed and env-agnostic.
 */
final class DefaultDerivativeRunnerDefaultsProviderUnitTest extends UnitTestCase {

  /**
   * Tests built-in defaults ignore environment endpoint overrides.
   */
  public function testDefaultsIgnoreEnvironmentVariables(): void {
    $previousFits = getenv('ALPACA_DERIVATIVE_FITS_URL');
    $previousTimeout = getenv('ALPACA_CLIENT_REQUEST_TIMEOUT');

    putenv('ALPACA_DERIVATIVE_FITS_URL=http://env-override.invalid/');
    putenv('ALPACA_CLIENT_REQUEST_TIMEOUT=999');

    try {
      $defaults = (new DefaultDerivativeRunnerDefaultsProvider())->getDefaults();

      static::assertSame('http://fits:8080/', $defaults['islandora-connector-fits']['endpoint']);
      static::assertSame(300, $defaults['islandora-connector-fits']['timeout']);
    }
    finally {
      $previousFits === FALSE
        ? putenv('ALPACA_DERIVATIVE_FITS_URL')
        : putenv('ALPACA_DERIVATIVE_FITS_URL=' . $previousFits);
      $previousTimeout === FALSE
        ? putenv('ALPACA_CLIENT_REQUEST_TIMEOUT')
        : putenv('ALPACA_CLIENT_REQUEST_TIMEOUT=' . $previousTimeout);
    }
  }

}
