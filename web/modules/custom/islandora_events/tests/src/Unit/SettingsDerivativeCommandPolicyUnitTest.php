<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Unit;

use Drupal\islandora_events\Service\SettingsDerivativeCommandPolicy;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for code-defined derivative command policy.
 */
final class SettingsDerivativeCommandPolicyUnitTest extends UnitTestCase {

  /**
   * Tests safe args are parsed into argv tokens.
   */
  public function testParsePassedArgsAcceptsSafeInput(): void {
    $policy = new SettingsDerivativeCommandPolicy();

    $this->assertSame(
      ['-quality', '80', '-resize', '100x100'],
      $policy->parsePassedArgs('-quality 80 -resize 100x100'),
    );
  }

  /**
   * Tests dangerous input is rejected with Scyllaridae-style validation.
   */
  public function testParsePassedArgsRejectsDangerousInput(): void {
    $policy = new SettingsDerivativeCommandPolicy();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Invalid input for passed arg');
    $policy->parsePassedArgs('-quality 80; rm -rf /');
  }

}
