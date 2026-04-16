<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\islandora_events\Index\Target\AbstractHttpIndexTarget;
use Drupal\sm_workers\Service\CircuitBreakerService;
use Drupal\Tests\UnitTestCase;
use Drupal\jwt\Authentication\Provider\JwtAuth;
use GuzzleHttp\ClientInterface;

/**
 * Unit tests for the shared HTTP index target base class.
 */
final class AbstractHttpIndexTargetUnitTest extends UnitTestCase {

  /**
   * Tests the base class accepts any entity type by default.
   */
  public function testSupportsAllowsCustomEntityTypesByDefault(): void {
    $target = new TestHttpIndexTarget(
      $this->createMock(ClientInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(JwtAuth::class),
      $this->createMock(CircuitBreakerService::class),
      $this->createMock(AccountProxyInterface::class),
    );

    static::assertTrue($target->supports('commerce_product', 'insert'));
    static::assertTrue($target->supports('paragraph', 'update'));
    static::assertFalse($target->supports('paragraph', 'unknown'));
  }

  /**
   * Tests subclasses can still explicitly restrict supported entity types.
   */
  public function testSupportsAllowsSubclassEntityRestrictions(): void {
    $target = new RestrictedTestHttpIndexTarget(
      $this->createMock(ClientInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(JwtAuth::class),
      $this->createMock(CircuitBreakerService::class),
      $this->createMock(AccountProxyInterface::class),
    );

    static::assertTrue($target->supports('node', 'delete'));
    static::assertFalse($target->supports('commerce_product', 'delete'));
  }

}

/**
 * Test-only target that uses the default support behavior.
 */
final class TestHttpIndexTarget extends AbstractHttpIndexTarget {

  /**
   * {@inheritdoc}
   */
  public function getTargetId(): string {
    return 'test';
  }

}

/**
 * Test-only target that narrows entity support explicitly.
 */
final class RestrictedTestHttpIndexTarget extends AbstractHttpIndexTarget {

  /**
   * {@inheritdoc}
   */
  protected const SUPPORTED_ENTITY_TYPES = ['node'];

  /**
   * {@inheritdoc}
   */
  public function getTargetId(): string {
    return 'restricted-test';
  }

}
