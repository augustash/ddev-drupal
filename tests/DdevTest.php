<?php

declare(strict_types=1);

namespace Augustash\Tests;

/**
 * ddev-drupal specific tests, on top of the shared DdevTestCase base.
 *
 * Drupal is the package that actually ships a Selenium override
 * (assets/config.selenium-standalone-chrome.yaml), so the dedupe behaviour has
 * a real asset to verify against here — unlike WordPress, where it's a no-op.
 */
final class DdevTest extends DdevTestCase {

  /**
   * The shipped Selenium override's vars must all be stripped from the main
   * config — this guards the dedupe against the real asset, so adding a var to
   * the override (or to a site's inline web_environment) can't silently leave a
   * duplicate behind.
   */
  public function testDedupeStripsEntireShippedSeleniumOverride(): void {
    $asset = __DIR__ . '/../assets/config.selenium-standalone-chrome.yaml';
    $this->assertFileExists($asset, 'ddev-drupal should ship the Selenium override.');

    $dir = $this->setConfigDir();
    copy($asset, $dir . '/config.selenium-standalone-chrome.yaml');

    $overrideVars = \Symfony\Component\Yaml\Yaml::parseFile($asset)['web_environment'];
    $this->assertNotEmpty($overrideVars);

    // Main config = every override var inline, plus two genuinely site-specific.
    $config = ['web_environment' => array_merge($overrideVars, [
      'DDEV_PANTHEON_SITE=mspairport',
      'DDEV_PANTHEON_ENVIRONMENT=live',
    ])];

    $result = self::call('dedupeWebEnvironment', $config);

    $this->assertSame([
      'DDEV_PANTHEON_SITE=mspairport',
      'DDEV_PANTHEON_ENVIRONMENT=live',
    ], $result['web_environment']);
  }

}
