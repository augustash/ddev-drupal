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

  // ---------------------------------------------------------------------------
  // hashPath() + fingerprint()
  //
  // These back the no-op detection that keeps postUpdate from printing a
  // restart prompt on every `composer update`. They're generic (not
  // Drupal-specific) and belong in DdevTestCase once ddev-wordpress carries the
  // same change; kept here for now so the shared base stays byte-identical.
  // ---------------------------------------------------------------------------

  public function testHashPathMatchesFileContents(): void {
    $dir = $this->setConfigDir();
    $file = $dir . '/a.txt';
    file_put_contents($file, 'hello');
    $this->assertSame(md5_file($file), self::call('hashPath', $file));
  }

  public function testHashPathReturnsEmptyForMissingPath(): void {
    $this->assertSame('', self::call('hashPath', '/no/such/path-' . __LINE__));
  }

  public function testHashPathHashesDirectoryAndReactsToContentChange(): void {
    $dir = $this->setConfigDir();
    mkdir($dir . '/tree');
    file_put_contents($dir . '/tree/one.txt', 'one');
    file_put_contents($dir . '/tree/two.txt', 'two');

    $hash = self::call('hashPath', $dir . '/tree');
    $this->assertNotSame('', $hash);
    // Stable across calls when nothing changed.
    $this->assertSame($hash, self::call('hashPath', $dir . '/tree'));

    // A content change anywhere in the tree moves the hash.
    file_put_contents($dir . '/tree/two.txt', 'CHANGED');
    $this->assertNotSame($hash, self::call('hashPath', $dir . '/tree'));
  }

  public function testFingerprintIsStableOnNoopRun(): void {
    // The whole point: with no managed file touched between calls, the
    // fingerprint must not move — that's what lets the run stay silent.
    $dir = $this->setConfigDir();
    $this->setManagedRoots($dir);
    file_put_contents($dir . '/config.yaml', "name: site\ndocroot: web\n");

    $this->assertSame(self::call('fingerprint'), self::call('fingerprint'));
  }

  public function testFingerprintChangesWhenManagedFileChanges(): void {
    $dir = $this->setConfigDir();
    $this->setManagedRoots($dir);
    file_put_contents($dir . '/config.yaml', "name: site\ndocroot: web\n");

    $before = self::call('fingerprint');
    file_put_contents($dir . '/docker-compose.browsersync.yaml', "version: '3'\n");
    $this->assertNotSame($before, self::call('fingerprint'));
  }

  /**
   * Point $ddevRoot and $gitIgnorePath at the temp dir setConfigDir() created.
   *
   * setConfigDir() only redirects $configPath; fingerprint() also reads the
   * ddev asset root and the project .gitignore, so those need redirecting too
   * to keep the test off the real filesystem.
   */
  private function setManagedRoots(string $dir): void {
    foreach (['ddevRoot' => $dir . '/', 'gitIgnorePath' => $dir . '/.gitignore'] as $prop => $value) {
      $ref = new \ReflectionProperty(\Augustash\Ddev::class, $prop);
      $ref->setAccessible(TRUE);
      $ref->setValue(NULL, $value);
    }
  }

}
