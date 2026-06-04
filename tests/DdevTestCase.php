<?php

declare(strict_types=1);

namespace Augustash\Tests;

use Augustash\Ddev;
use PHPUnit\Framework\TestCase;

/**
 * Shared base for the Augustash\Ddev unit tests.
 *
 * The config-shaping helpers (migratePantheonEnv, isPantheonSite,
 * pruneDefaultKeys, dedupeWebEnvironment) are byte-identical across the
 * ddev-drupal and ddev-wordpress packages, so their tests live here and run
 * under each package's concrete subclass. Package-specific behaviour (e.g.
 * Drupal's Selenium override vs. WordPress shipping none) is added in the
 * subclass — base for uniformity, subclass for specifics.
 *
 * The methods under test are `protected static` internal steps of the
 * scaffolding run, not public API — the public entry points (postUpdate /
 * postPackageInstall) need a full Composer Event + a host filesystem a unit
 * test can't supply. We reach the helpers with reflection; the tradeoff is
 * coupling to internal method names, acceptable because these are stable seams
 * and the alternative (driving run() through mocks) would test Composer
 * plumbing rather than our logic.
 */
abstract class DdevTestCase extends TestCase {

  /**
   * Temp dirs created for config.*.yaml fixtures, removed in tearDown().
   *
   * @var string[]
   */
  private array $tempDirs = [];

  protected function tearDown(): void {
    foreach ($this->tempDirs as $dir) {
      foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
      }
      @rmdir($dir);
    }
    $this->tempDirs = [];
    parent::tearDown();
  }

  /**
   * Invoke a protected static method on Ddev by name.
   */
  protected static function call(string $method, mixed ...$args): mixed {
    $ref = new \ReflectionMethod(Ddev::class, $method);
    $ref->setAccessible(TRUE);
    return $ref->invoke(NULL, ...$args);
  }

  /**
   * Point Ddev::$configPath at a fresh temp .ddev dir and return that dir.
   *
   * dedupeWebEnvironment() globs config.*.yaml from the directory holding
   * $configPath, so each test that exercises it needs an isolated dir with
   * real files on disk (glob() doesn't work through stream wrappers like
   * vfsStream, so we use a real temp dir rather than a virtual filesystem).
   */
  protected function setConfigDir(): string {
    $dir = sys_get_temp_dir() . '/ddev-test-' . uniqid('', TRUE);
    mkdir($dir, 0777, TRUE);
    $this->tempDirs[] = $dir;

    $ref = new \ReflectionProperty(Ddev::class, 'configPath');
    $ref->setAccessible(TRUE);
    $ref->setValue(NULL, $dir . '/config.yaml');

    return $dir;
  }

  // ---------------------------------------------------------------------------
  // migratePantheonEnv()
  // ---------------------------------------------------------------------------

  public function testMigratePantheonEnvRenamesLegacyVars(): void {
    $config = ['web_environment' => [
      'WORKING_ENVIRONMENT=live',
      'PANTHEON_SITE=mspairport',
      'DRUPAL_TEST_DB_URL=mysql://db:db@db/db',
    ]];

    $result = self::call('migratePantheonEnv', $config);

    $this->assertSame([
      'DDEV_PANTHEON_ENVIRONMENT=live',
      'DDEV_PANTHEON_SITE=mspairport',
      // Unrelated vars are left untouched, in place.
      'DRUPAL_TEST_DB_URL=mysql://db:db@db/db',
    ], $result['web_environment']);
  }

  public function testMigratePantheonEnvIsIdempotent(): void {
    // The strict-prefix guard must NOT re-rewrite already-migrated vars:
    // 'DDEV_PANTHEON_SITE=' contains 'PANTHEON_SITE=' but not as a prefix.
    $config = ['web_environment' => [
      'DDEV_PANTHEON_SITE=mspairport',
      'DDEV_PANTHEON_ENVIRONMENT=live',
    ]];

    $once = self::call('migratePantheonEnv', $config);
    $twice = self::call('migratePantheonEnv', $once);

    $this->assertSame($config['web_environment'], $once['web_environment']);
    $this->assertSame($once, $twice);
  }

  public function testMigratePantheonEnvNoWebEnvironmentIsNoop(): void {
    $config = ['name' => 'site'];
    $this->assertSame($config, self::call('migratePantheonEnv', $config));
  }

  public function testMigratePantheonEnvSplitsOldestProjectVar(): void {
    // Oldest format: `project=<site>.<env>` packs both values into one var,
    // dot-separated. It must split into the two DDEV_-prefixed vars, in place.
    $config = ['web_environment' => [
      'project=mysite.live',
      'DRUPAL_TEST_DB_URL=mysql://db:db@db/db',
    ]];

    $result = self::call('migratePantheonEnv', $config);

    $this->assertSame([
      'DDEV_PANTHEON_SITE=mysite',
      'DDEV_PANTHEON_ENVIRONMENT=live',
      // Unrelated vars are left untouched, after the split-in pair.
      'DRUPAL_TEST_DB_URL=mysql://db:db@db/db',
    ], $result['web_environment']);
  }

  public function testMigratePantheonEnvProjectVarWithoutEnvDefaultsToLive(): void {
    // No dot means no environment was recorded; default to 'live' to match
    // configurePantheon()'s default rather than emitting an empty value.
    $config = ['web_environment' => ['project=mysite']];

    $result = self::call('migratePantheonEnv', $config);

    $this->assertSame([
      'DDEV_PANTHEON_SITE=mysite',
      'DDEV_PANTHEON_ENVIRONMENT=live',
    ], $result['web_environment']);
  }

  public function testMigratePantheonEnvProjectVarIsIdempotent(): void {
    // After the split there is no `project=` var left, so a second pass is a
    // no-op — the migrated config feeds straight back through unchanged.
    $config = ['web_environment' => ['project=mysite.dev']];

    $once = self::call('migratePantheonEnv', $config);
    $twice = self::call('migratePantheonEnv', $once);

    $this->assertSame([
      'DDEV_PANTHEON_SITE=mysite',
      'DDEV_PANTHEON_ENVIRONMENT=dev',
    ], $once['web_environment']);
    $this->assertSame($once, $twice);
  }

  // ---------------------------------------------------------------------------
  // isPantheonSite()
  // ---------------------------------------------------------------------------

  public function testIsPantheonSiteDetectsByEnvVar(): void {
    $config = ['web_environment' => ['DDEV_PANTHEON_SITE=mspairport']];
    $this->assertTrue(self::call('isPantheonSite', $config));
  }

  public function testIsPantheonSiteDetectsByExistingHook(): void {
    $config = ['hooks' => ['post-start' => [
      ['exec-host' => 'ddev add-on get augustash/ddev-pantheon-db --version develop'],
    ]]];
    $this->assertTrue(self::call('isPantheonSite', $config));
  }

  public function testIsPantheonSiteFalseForLegacyEnvVar(): void {
    // Legacy (un-prefixed) var must NOT count as Pantheon — this is exactly the
    // bug migratePantheonEnv() fixes: detection only matches DDEV_PANTHEON_SITE=.
    $config = ['web_environment' => ['PANTHEON_SITE=mspairport']];
    $this->assertFalse(self::call('isPantheonSite', $config));
  }

  public function testIsPantheonSiteFalseForNonPantheon(): void {
    $config = ['web_environment' => ['DRUPAL_TEST_DB_URL=mysql://db']];
    $this->assertFalse(self::call('isPantheonSite', $config));
  }

  // ---------------------------------------------------------------------------
  // mergePostStartHooks()
  // ---------------------------------------------------------------------------

  public function testMergePostStartHooksPlacesAssetHooksFirst(): void {
    // Asset hooks lead, unique site hooks follow — so add-on installs run
    // before commands that depend on them.
    $site = ['hooks' => ['post-start' => [['exec-host' => 'ddev solrcollection']]]];
    $asset = ['hooks' => ['post-start' => [['exec-host' => 'ddev db']]]];

    $result = self::call('mergePostStartHooks', $site, $asset);

    $this->assertSame([
      ['exec-host' => 'ddev db'],
      ['exec-host' => 'ddev solrcollection'],
    ], $result['hooks']['post-start']);
  }

  public function testMergePostStartHooksDedupesByExecHost(): void {
    // A site hook already present in the asset set is not duplicated.
    $site = ['hooks' => ['post-start' => [
      ['exec-host' => 'ddev db'],
      ['exec-host' => 'ddev solrcollection'],
    ]]];
    $asset = ['hooks' => ['post-start' => [['exec-host' => 'ddev db']]]];

    $result = self::call('mergePostStartHooks', $site, $asset);

    $this->assertSame([
      ['exec-host' => 'ddev db'],
      ['exec-host' => 'ddev solrcollection'],
    ], $result['hooks']['post-start']);
  }

  public function testMergePostStartHooksWithNoSiteHooks(): void {
    $site = [];
    $asset = ['hooks' => ['post-start' => [['exec-host' => 'ddev db']]]];

    $result = self::call('mergePostStartHooks', $site, $asset);

    $this->assertSame([['exec-host' => 'ddev db']], $result['hooks']['post-start']);
  }

  // ---------------------------------------------------------------------------
  // applyPantheonHooks()
  // ---------------------------------------------------------------------------

  public function testApplyPantheonHooksFromScratch(): void {
    // No prior hooks: assert the develop add-on pull, then the db pull.
    $result = self::call('applyPantheonHooks', []);

    $this->assertSame([
      ['exec-host' => 'ddev add-on get augustash/ddev-pantheon-db --version develop >/dev/null 2>&1'],
      ['exec-host' => 'ddev db'],
    ], $result['hooks']['post-start']);
  }

  public function testApplyPantheonHooksUpgradesPriorAddonHookInPlace(): void {
    // A pre-existing pantheon-db hook (here a bare one without --version) is
    // stripped and replaced by the develop-pinned hook — upgraded, not
    // duplicated. This is the behaviour that keeps the add-on self-updating.
    $config = ['hooks' => ['post-start' => [
      ['exec-host' => 'ddev add-on get augustash/ddev-pantheon-db'],
      ['exec-host' => 'ddev db'],
    ]]];

    $result = self::call('applyPantheonHooks', $config);

    $this->assertSame([
      ['exec-host' => 'ddev add-on get augustash/ddev-pantheon-db --version develop >/dev/null 2>&1'],
      ['exec-host' => 'ddev db'],
    ], $result['hooks']['post-start']);
  }

  public function testApplyPantheonHooksPreservesOtherSiteHooks(): void {
    // Pantheon hooks lead; unrelated site hooks (e.g. solrcollection) survive.
    $config = ['hooks' => ['post-start' => [
      ['exec-host' => 'ddev solrcollection'],
      ['exec-host' => 'ddev db'],
    ]]];

    $result = self::call('applyPantheonHooks', $config);

    $this->assertSame([
      ['exec-host' => 'ddev add-on get augustash/ddev-pantheon-db --version develop >/dev/null 2>&1'],
      ['exec-host' => 'ddev db'],
      ['exec-host' => 'ddev solrcollection'],
    ], $result['hooks']['post-start']);
  }

  public function testApplyPantheonHooksIsIdempotent(): void {
    $once = self::call('applyPantheonHooks', []);
    $twice = self::call('applyPantheonHooks', $once);
    $this->assertSame($once, $twice);
  }

  // ---------------------------------------------------------------------------
  // pruneDefaultKeys()
  // ---------------------------------------------------------------------------

  public function testPruneDefaultKeysRemovesNoiseButKeepsPins(): void {
    $config = [
      'name' => 'site',
      'php_version' => '8.3',
      'webserver_type' => 'nginx-fpm',
      'xdebug_enabled' => FALSE,
      'additional_hostnames' => [],
      'additional_fqdns' => [],
      'use_dns_when_possible' => TRUE,
      'composer_version' => '2',
      'corepack_enable' => FALSE,
      'xhgui_https_port' => '8142',
      'xhgui_http_port' => '8143',
      'database' => ['type' => 'mariadb', 'version' => '10.6'],
    ];

    $result = self::call('pruneDefaultKeys', $config);

    $this->assertSame([
      'name' => 'site',
      'php_version' => '8.3',
      'database' => ['type' => 'mariadb', 'version' => '10.6'],
    ], $result);
  }

  public function testPruneDefaultKeysPreservesNonDefaultValues(): void {
    // A key set to something OTHER than ddev's default is a real choice — keep.
    $config = [
      'xdebug_enabled' => TRUE,
      'composer_version' => '2.2',
      'webserver_type' => 'apache-fpm',
    ];
    $this->assertSame($config, self::call('pruneDefaultKeys', $config));
  }

  public function testPruneDefaultKeysIsIdempotent(): void {
    $config = ['webserver_type' => 'nginx-fpm', 'name' => 'site'];
    $once = self::call('pruneDefaultKeys', $config);
    $this->assertSame($once, self::call('pruneDefaultKeys', $once));
  }

  // ---------------------------------------------------------------------------
  // dedupeWebEnvironment()
  // ---------------------------------------------------------------------------

  public function testDedupeRemovesVarsSuppliedByOverride(): void {
    $dir = $this->setConfigDir();
    file_put_contents($dir . '/config.selenium-standalone-chrome.yaml',
      "web_environment:\n  - SIMPLETEST_DB=mysql://db:db@db/db\n  - DRUPAL_TEST_BASE_URL=http://web\n");

    $config = ['web_environment' => [
      'DDEV_PANTHEON_SITE=mspairport',
      'SIMPLETEST_DB=mysql://db:db@db/db',
      'DRUPAL_TEST_BASE_URL=http://web',
      'DDEV_PANTHEON_ENVIRONMENT=live',
    ]];

    $result = self::call('dedupeWebEnvironment', $config);

    $this->assertSame([
      'DDEV_PANTHEON_SITE=mspairport',
      'DDEV_PANTHEON_ENVIRONMENT=live',
    ], $result['web_environment']);
  }

  public function testDedupeUnionsAcrossMultipleOverrides(): void {
    $dir = $this->setConfigDir();
    file_put_contents($dir . '/config.selenium-standalone-chrome.yaml',
      "web_environment:\n  - SIMPLETEST_DB=mysql://db:db@db/db\n");
    file_put_contents($dir . '/config.local.yaml',
      "web_environment:\n  - EXTRA=1\n");

    $config = ['web_environment' => [
      'KEEP=me',
      'SIMPLETEST_DB=mysql://db:db@db/db',
      'EXTRA=1',
    ]];

    $result = self::call('dedupeWebEnvironment', $config);

    $this->assertSame(['KEEP=me'], $result['web_environment']);
  }

  public function testDedupeDropsKeyWhenNothingSiteSpecificRemains(): void {
    $dir = $this->setConfigDir();
    file_put_contents($dir . '/config.selenium-standalone-chrome.yaml',
      "web_environment:\n  - SIMPLETEST_DB=mysql://db:db@db/db\n");

    $config = ['web_environment' => ['SIMPLETEST_DB=mysql://db:db@db/db']];

    $result = self::call('dedupeWebEnvironment', $config);

    // Empty web_environment is removed entirely, not left as [].
    $this->assertArrayNotHasKey('web_environment', $result);
  }

  public function testDedupeIsNoopWithoutOverrides(): void {
    $this->setConfigDir();
    $config = ['web_environment' => ['DDEV_PANTHEON_SITE=foo']];
    $this->assertSame($config, self::call('dedupeWebEnvironment', $config));
  }

  public function testDedupeIgnoresMainConfigYaml(): void {
    // glob('config.*.yaml') must NOT match the main config.yaml itself.
    $dir = $this->setConfigDir();
    file_put_contents($dir . '/config.yaml',
      "web_environment:\n  - DDEV_PANTHEON_SITE=mspairport\n");

    $config = ['web_environment' => ['DDEV_PANTHEON_SITE=mspairport']];
    $this->assertSame($config, self::call('dedupeWebEnvironment', $config));
  }

}
