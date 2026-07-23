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
  // copyAsset() + mirrorAsset()
  //
  // Package-owned scaffolding must refresh on every `-u`. Symfony's
  // Filesystem::copy()/mirror() are mtime-gated by default and silently skip
  // when the target is not older than the source — and composer-installed
  // assets routinely carry older mtimes than the already-deployed .ddev files,
  // so the refresh was no-opping (the reported symptom: a bumped redis.conf
  // never landing). These guard the force-overwrite. Generic (not
  // Drupal-specific); belong in DdevTestCase once ddev-wordpress carries the
  // same helpers, kept here for now so the shared base stays byte-identical.
  // ---------------------------------------------------------------------------

  public function testCopyAssetOverwritesTargetNewerThanSource(): void {
    $dir = $this->setConfigDir();
    $source = $dir . '/source.conf';
    $target = $dir . '/target.conf';
    file_put_contents($source, "latest\n");
    file_put_contents($target, "stale\n");
    // Reproduce the skip condition: an existing target newer than the asset.
    touch($source, time() - 100);
    touch($target, time());

    self::call('copyAsset', $source, $target);

    $this->assertSame("latest\n", file_get_contents($target),
      'copyAsset must overwrite a target that is newer than the source.');
  }

  public function testMirrorAssetOverwritesDirectoryFileNewerThanSource(): void {
    $dir = $this->setConfigDir();
    $source = $dir . '/asset-dir';
    $target = $dir . '/live-dir';
    mkdir($source);
    mkdir($target);
    file_put_contents($source . '/f.conf', "latest\n");
    file_put_contents($target . '/f.conf', "stale\n");
    touch($source . '/f.conf', time() - 100);
    touch($target . '/f.conf', time());

    self::call('mirrorAsset', $source, $target);

    // Capture then clean up the nested dirs before asserting, so a failure
    // still leaves no temp cruft (tearDown only sweeps top-level files).
    $content = file_get_contents($target . '/f.conf');
    (new \Symfony\Component\Filesystem\Filesystem())->remove([$source, $target]);

    $this->assertSame("latest\n", $content,
      'mirrorAsset must overwrite a directory file newer than its source.');
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

  // ---------------------------------------------------------------------------
  // Mode self-detection: argsRequestUpdate() + isConfigured()
  //
  // These back shouldUpdate(), which decides fresh-vs-refresh without a flag.
  // shouldUpdate() itself needs a Composer Event/IO (not a dev dep here), so we
  // test the two pure seams it composes. Generic (not Drupal-specific); belong
  // in DdevTestCase once ddev-wordpress carries the same change.
  // ---------------------------------------------------------------------------

  public function testArgsRequestUpdateDetectsFlags(): void {
    $this->assertTrue(self::call('argsRequestUpdate', ['-u']));
    $this->assertTrue(self::call('argsRequestUpdate', ['--update']));
    $this->assertTrue(self::call('argsRequestUpdate', ['update']));
    $this->assertTrue(self::call('argsRequestUpdate', ['x', 'update']));
  }

  public function testArgsRequestUpdateFalseWithoutFlag(): void {
    $this->assertFalse(self::call('argsRequestUpdate', []));
    $this->assertFalse(self::call('argsRequestUpdate', ['foo', '-x']));
  }

  public function testIsConfiguredTrueForNonEmptyName(): void {
    $dir = $this->setConfigDir();
    file_put_contents($dir . '/config.yaml', "name: ilc\n");
    $this->assertTrue(self::call('isConfigured'));
  }

  public function testIsConfiguredFalseForEmptyName(): void {
    // The asset config.yaml a fresh scaffold lands ships an empty name — this is
    // the case that must route to first-time setup, not a refresh.
    $dir = $this->setConfigDir();
    file_put_contents($dir . '/config.yaml', "name:\ndocroot: web\n");
    $this->assertFalse(self::call('isConfigured'));
  }

  public function testIsConfiguredFalseWhenNameKeyMissingOrFileAbsent(): void {
    $dir = $this->setConfigDir();
    file_put_contents($dir . '/config.yaml', "docroot: web\n");
    $this->assertFalse(self::call('isConfigured'));

    // No config.yaml at all (setConfigDir points at an empty temp dir).
    $this->setConfigDir();
    $this->assertFalse(self::call('isConfigured'));
  }

  // ---------------------------------------------------------------------------
  // Value preservation on the fresh path: configureSite() / currentDrupalVersion
  // / pantheonEnvValues()
  //
  // The reported bug: a non-interactive re-run wrote empty prompt answers over
  // an already-configured site (name → null, php → default, Pantheon site →
  // 'aai'+empty). configureSite() now guards name; the seed helpers let the
  // prompts default to the existing values.
  // ---------------------------------------------------------------------------

  public function testConfigureSitePreservesNameWhenClientCodeEmpty(): void {
    // Both '' and NULL (the two shapes an unanswered prompt takes) must leave
    // the existing name intact — the core of the reported regression.
    $config = ['name' => 'ilc'];
    $this->assertSame('ilc', self::call('configureSite', $config, '', 'web', '10', '8.3')['name']);
    $this->assertSame('ilc', self::call('configureSite', $config, NULL, 'web', '10', '8.3')['name']);
  }

  public function testConfigureSiteSetsNameWhenClientCodeGiven(): void {
    $result = self::call('configureSite', [], 'newcode', 'web', '11', '8.3');
    $this->assertSame('newcode', $result['name']);
    $this->assertSame('drupal11', $result['type']);
    $this->assertSame('8.3', $result['php_version']);
    $this->assertSame('web', $result['docroot']);
  }

  public function testCurrentDrupalVersionParsesTypeElseFallsBack(): void {
    $this->assertSame('10', self::call('currentDrupalVersion', ['type' => 'drupal10']));
    $this->assertSame('11', self::call('currentDrupalVersion', ['type' => 'drupal11']));
    $this->assertSame('10', self::call('currentDrupalVersion', []));
    $this->assertSame('10', self::call('currentDrupalVersion', ['type' => 'wordpress']));
  }

  public function testPantheonEnvValuesExtractsSiteAndEnvironment(): void {
    $config = ['web_environment' => [
      'DDEV_PANTHEON_SITE=aaiilc',
      'DDEV_PANTHEON_ENVIRONMENT=live',
      'OTHER=1',
    ]];
    $this->assertSame(['aaiilc', 'live'], self::call('pantheonEnvValues', $config));
  }

  public function testPantheonEnvValuesNullsWhenAbsent(): void {
    $this->assertSame([NULL, NULL], self::call('pantheonEnvValues', []));
    $this->assertSame([NULL, NULL], self::call('pantheonEnvValues', ['web_environment' => ['X=1']]));
  }

  // ---------------------------------------------------------------------------
  // No-op detection: config-shaping convergence
  //
  // The closing "Everything up-to-date." vs "run ddev restart" message is gated
  // on a fingerprint delta, so the shaping pipeline must reach a fixed point —
  // a second identical pass changes nothing, or a re-run would forever claim a
  // change and nag for a restart.
  // ---------------------------------------------------------------------------

  public function testConfigShapingConvergesOnSecondPass(): void {
    // The shaping pipeline must reach a fixed point — a second identical pass
    // changes nothing — so a genuine no-op run reports up-to-date.
    $config = [
      'name' => 'site',
      'web_environment' => ['PANTHEON_SITE=mysite', 'WORKING_ENVIRONMENT=live'],
      'hooks' => ['post-start' => [['exec-host' => 'ddev add-on get augustash/ddev-pantheon-db']]],
      'webserver_type' => 'nginx-fpm',
    ];
    $shape = function ($c) {
      $c = self::call('migratePantheonEnv', $c);
      $c = self::call('applyPantheonHooks', $c);
      return self::call('pruneDefaultKeys', $c);
    };
    $once = $shape($config);
    $this->assertSame($once, $shape($once), 'Shaping must be a fixed point on the second pass.');
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
