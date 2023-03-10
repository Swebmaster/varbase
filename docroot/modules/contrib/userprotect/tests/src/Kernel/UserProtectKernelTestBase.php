<?php

namespace Drupal\Tests\userprotect\Kernel;

use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\Tests\userprotect\Traits\UserProtectCreationTrait;

/**
 * Provides a base class for User Protect kernel tests.
 */
abstract class UserProtectKernelTestBase extends EntityKernelTestBase {

  use UserProtectCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['userprotect'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('user', ['users_data']);
  }

}
