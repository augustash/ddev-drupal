<?php

namespace Augustash;

use Composer\Script\Event;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Ddev console class.
 */
class Ddev {

  /**
   * Path to config file.
   *
   * @var string
   */
  private static $configPath = __DIR__ . '/../../../../.ddev/config.yaml';

  /**
   * Path to gitignore file.
   *
   * @var string
   */
  private static $gitIgnorePath = __DIR__ . '/../../../../.gitignore';

  /**
   * Path to gitignore file.
   *
   * @var string
   */
  private static $settingsLocalPath = 'sites/default/settings.local.php';

  /**
   * The ddev root.
   *
   * @var string
   */
  private static $ddevRoot = __DIR__ . '/../../../../.ddev/';

  /**
   * The docroot.
   *
   * @var string
   */
  private static $docRoot;

  /**
   * Entry point for the `ddev-setup` composer script.
   *
   * Honors an optional update flag (`-u`) to refresh in place without prompts;
   * see run() for the orchestration.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  public static function postPackageInstall(Event $event) {
    static::run($event, static::isUpdateMode($event), TRUE);
  }

  /**
   * Run on post-update-cmd.
   *
   * Auto-fired on `composer update`. Always runs in update mode (no prompts)
   * and skips the wkhtmltopdf migration, which performs its own composer
   * operations and must not run inside a composer update.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  public static function postUpdate(Event $event) {
    static::run($event, TRUE, FALSE);
  }

  /**
   * Shared setup/refresh routine.
   *
   * Thin orchestrator: each step is its own method (see syncConfig/cleanup).
   * On a fresh run the inputs (client code, docroot, Drupal/PHP version) are
   * gathered via prompts and threaded through the configure* steps; in update
   * mode they are inferred from the existing config so nothing is re-prompted.
   * The $config array is written once at the end.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   * @param bool $update
   *   When TRUE, skip prompts and refresh in place from the existing config.
   * @param bool $runWkhtmltopdf
   *   When TRUE, run the one-time wkhtmltopdf→dompdf migration.
   */
  protected static function run(Event $event, $update, $runWkhtmltopdf) {
    // postUpdate fires this on every `composer update`, so most runs are no-ops
    // that rewrite the scaffolding byte-for-byte. Fingerprint the managed files
    // up front (update mode only) so the restart prompt at the end fires only
    // when something that actually lands in the containers changed.
    $before = $update ? static::fingerprint() : NULL;

    static::syncConfig();
    static::cleanup();
    if ($runWkhtmltopdf) {
      static::cleanupWkhtmltopdf($event);
    }

    if (!(new Filesystem())->exists(static::$configPath)) {
      return;
    }

    $io = $event->getIO();
    $config = Yaml::parseFile(static::$configPath);

    if ($update) {
      // Update mode (`ddev composer ddev-setup -- -u`): keep all configured
      // values and skip the prompts. Infer prior choices from the existing
      // config so the generated scaffolding and hooks can be refreshed in
      // place — e.g. an existing Pantheon site has its add-on hook upgraded.
      static::$docRoot = $config['docroot'] ?? 'web';
      // Rename legacy Pantheon env vars before detection so sites configured
      // prior to the DDEV_ prefix switch are recognised and refreshed.
      $config = static::migratePantheonEnv($config);
      if (static::isPantheonSite($config)) {
        $config = static::applyPantheonHooks($config);
        static::downgradeTerminus($event, $config['php_version'] ?? '8.1');
      }
      $io->info('<info>Update mode: configuration left untouched; rebuilding scaffolding.</info>');
    }
    else {
      $clientCode = $io->ask('<info>Client code?</info>:' . "\n > ");
      $docRoot = static::$docRoot = $io->ask('<info>Document root?</info>  [<comment>web</comment>]:' . "\n > ", 'web') ?: '';
      $drupalVersion = static::selectDrupalVersion($event);
      $phpVersion = static::selectPhpVersion($event);

      $config = static::configureSite($config, $clientCode, $docRoot, $drupalVersion, $phpVersion);
      $config = static::configurePantheon($event, $config, $clientCode, $phpVersion);
      $config = static::configureSubdomains($event, $config, $clientCode);
    }

    // Strip noise before writing: keys left at their ddev default and env vars
    // already supplied by a config.*.yaml override are redundant clutter.
    $config = static::pruneDefaultKeys($config);
    $config = static::dedupeWebEnvironment($config);

    static::writeConfig($event, $config);
    static::writeSettingsLocal($event);
    static::appendGitignore($event);
    static::copyBrowsersync($event);
    static::copySeleniumChrome($event);

    static::installSolr($event, $update);

    if ($update && static::fingerprint() !== $before) {
      // Something changed. The add-on pull and container rebuilds happen on the
      // host at start, which this in-container script can't trigger, so prompt a
      // restart to re-run the post-start hooks (e.g. ddev add-on get). When the
      // refresh was a no-op (fingerprint unchanged) we stay silent.
      $io->write('');
      $io->write('<info>Scaffolding refreshed.</info> Run <comment>ddev restart</comment> to rebuild the containers and re-pull add-ons (e.g. ddev-pantheon-db).');
      $io->write('');
    }
  }

  /**
   * Determine whether ddev-setup was invoked in update mode.
   *
   * Update mode (`ddev composer ddev-setup -- -u`) refreshes the generated
   * scaffolding and hooks without re-prompting for, or rewriting, the
   * project's configuration values.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   *
   * @return bool
   *   TRUE when an update flag (-u, --update, or update) was passed.
   */
  protected static function isUpdateMode(Event $event) {
    foreach ($event->getArguments() as $arg) {
      if (in_array($arg, ['-u', '--update', 'update'], TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Prompt for the Drupal version.
   *
   * @return string
   *   The selected Drupal major version (e.g. "10").
   */
  protected static function selectDrupalVersion(Event $event) {
    $drupalVersions = [
      '7',
      '8',
      '9',
      '10',
      '11',
    ];
    $index = $event->getIO()->select('<info>Drupal version</info> [<comment>10</comment>]:', $drupalVersions, '10');
    return $drupalVersions[$index];
  }

  /**
   * Prompt for the PHP version.
   *
   * @return string
   *   The selected PHP version (e.g. "8.1").
   */
  protected static function selectPhpVersion(Event $event) {
    $phpVersions = [
      '7.4',
      '8.1',
      '8.2',
      '8.3',
    ];
    $index = $event->getIO()->select('<info>PHP version</info> [<comment>8.1</comment>]:', $phpVersions, '8.1');
    return $phpVersions[$index];
  }

  /**
   * Apply the core site settings.
   *
   * @return array
   *   The updated configuration.
   */
  protected static function configureSite(array $config, $clientCode, $docRoot, $drupalVersion, $phpVersion) {
    $config['name'] = $clientCode;
    $config['docroot'] = $docRoot;
    $config['type'] = 'drupal' . $drupalVersion;
    $config['php_version'] = $phpVersion;
    return $config;
  }

  /**
   * Optionally wire up Pantheon hosting.
   *
   * Pantheon hosting is optional. Only wire up the Pantheon DB pull (env vars,
   * post-start add-on/db hooks, Terminus) when the site is actually hosted
   * there. Non-Pantheon sites skip all of it.
   *
   * @return array
   *   The updated configuration.
   */
  protected static function configurePantheon(Event $event, array $config, $clientCode, $phpVersion) {
    $io = $event->getIO();

    if (!$io->askConfirmation('<info>Is this site hosted on Pantheon?</info> [<comment>Y/n</comment>] ', TRUE)) {
      // Non-Pantheon: drop any stale Terminus build artifact.
      (new Filesystem())->remove(static::$ddevRoot . 'web-build/Dockerfile.ddev-terminus');
      return $config;
    }

    $siteName = $io->ask('<info>Pantheon site name</info> [<comment>' . 'aai' . $clientCode . '</comment>]:' . "\n > ", 'aai' . $clientCode);
    $siteEnv = $io->ask('<info>Pantheon site environment (dev|test|live)</info> [<comment>live</comment>]:' . "\n > ", 'live');

    $config['web_environment'] = [
      'DDEV_PANTHEON_SITE=' . $siteName,
      'DDEV_PANTHEON_ENVIRONMENT=' . $siteEnv,
    ];

    $config = static::applyPantheonHooks($config);

    static::downgradeTerminus($event, $phpVersion);

    return $config;
  }

  /**
   * Rename legacy Pantheon env vars to their DDEV_-prefixed equivalents.
   *
   * Sites configured before the prefix switch carry PANTHEON_SITE= and
   * WORKING_ENVIRONMENT= in web_environment. Update mode skips the prompts that
   * would rewrite these, so without this step isPantheonSite() never matches
   * and the pantheon-db add-on hook is never asserted. Renaming in place lets
   * the existing detection path light up on the next `-u` run.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return array
   *   The configuration with any legacy Pantheon env vars renamed.
   */
  protected static function migratePantheonEnv(array $config) {
    if (empty($config['web_environment'])) {
      return $config;
    }
    // Legacy var name => current DDEV_-prefixed name.
    $renames = [
      'PANTHEON_SITE=' => 'DDEV_PANTHEON_SITE=',
      'WORKING_ENVIRONMENT=' => 'DDEV_PANTHEON_ENVIRONMENT=',
    ];
    foreach ($config['web_environment'] as &$var) {
      foreach ($renames as $legacy => $current) {
        // Match the legacy prefix only, leaving the value intact. The guard
        // skips vars already migrated (DDEV_PANTHEON_SITE= contains the legacy
        // PANTHEON_SITE= substring but not as a prefix).
        if (strpos($var, $legacy) === 0) {
          $var = $current . substr($var, strlen($legacy));
          break;
        }
      }
    }
    unset($var);
    return $config;
  }

  /**
   * Detect whether the existing config describes a Pantheon-hosted site.
   *
   * Used by update mode, where the "Is this site hosted on Pantheon?" prompt is
   * skipped: the answer is inferred from the Pantheon env var or an existing
   * add-on hook so the hook can be refreshed without re-prompting.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return bool
   *   TRUE when the site is configured for Pantheon.
   */
  protected static function isPantheonSite(array $config) {
    foreach ($config['web_environment'] ?? [] as $var) {
      if (strpos($var, 'DDEV_PANTHEON_SITE=') === 0) {
        return TRUE;
      }
    }
    foreach ($config['hooks']['post-start'] ?? [] as $hook) {
      if (isset($hook['exec-host']) && strpos($hook['exec-host'], 'ddev add-on get augustash/ddev-pantheon-db') === 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Assert the Pantheon DB post-start hooks, upgrading any prior version.
   *
   * Pulls the Pantheon DB on start via the add-on (tracking the develop branch
   * so it self-updates). Any pre-existing pantheon-db add-on hook is stripped
   * first so the hook is upgraded in place rather than duplicated; other
   * site-specific hooks (e.g. solrcollection) are preserved and de-duplicated.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return array
   *   The configuration with the Pantheon hooks asserted.
   */
  protected static function applyPantheonHooks(array $config) {
    if (!empty($config['hooks']['post-start'])) {
      $config['hooks']['post-start'] = array_values(array_filter(
        $config['hooks']['post-start'],
        function ($hook) {
          return !isset($hook['exec-host'])
            || strpos($hook['exec-host'], 'ddev add-on get augustash/ddev-pantheon-db') !== 0;
        }
      ));
    }

    $pantheonHooks = [
      'hooks' => [
        'post-start' => [
          ['exec-host' => 'ddev add-on get augustash/ddev-pantheon-db --version develop'],
          ['exec-host' => 'ddev db'],
        ],
      ],
    ];
    return static::mergePostStartHooks($config, $pantheonHooks);
  }

  /**
   * Prompt for and apply additional subdomain hostnames.
   *
   * @return array
   *   The updated configuration.
   */
  protected static function configureSubdomains(Event $event, array $config, $clientCode) {
    $subdomains = $event->getIO()->ask('<info>Subdomains? (space delimiter)</info> [<comment>no</comment>]:' . "\n > ", FALSE);
    if ($subdomains) {
      $config['additional_hostnames'] = [];
      foreach (explode(' ', $subdomains) as $subdomain) {
        $config['additional_hostnames'][] = $subdomain . '.' . $clientCode;
      }
    }
    return $config;
  }

  /**
   * Write the assembled configuration to config.yaml.
   */
  protected static function writeConfig(Event $event, array $config) {
    $io = $event->getIO();
    try {
      (new Filesystem())->dumpFile(static::$configPath, Yaml::dump($config, 2, 2));
      $io->info('<info>Config.yaml updated.</info>');
    }
    catch (\Error $e) {
      $io->error('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * Seed the local settings file when missing.
   */
  protected static function writeSettingsLocal(Event $event) {
    $fileSystem = new Filesystem();
    $settingsLocalPath = static::getWebRootPath() . static::$settingsLocalPath;
    if ($fileSystem->exists($settingsLocalPath)) {
      return;
    }
    try {
      $data = file_get_contents(__DIR__ . '/../assets/settings.local.php');
      $fileSystem->dumpFile($settingsLocalPath, $data);
    }
    catch (\Error $e) {
      $event->getIO()->error('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * Append the ddev ignore rules to .gitignore once.
   */
  protected static function appendGitignore(Event $event) {
    $fileSystem = new Filesystem();
    try {
      $gitignore = $fileSystem->exists(static::$gitIgnorePath) ? file_get_contents(static::$gitIgnorePath) : '';
      if (strpos($gitignore, '# Ignore ddev files') === FALSE) {
        $gitignore .= "\n" . file_get_contents(__DIR__ . '/../assets/.gitignore.append');
        $fileSystem->dumpFile(static::$gitIgnorePath, $gitignore);
      }
    }
    catch (\Error $e) {
      $event->getIO()->error('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * Add the BrowserSync docker-compose service.
   */
  protected static function copyBrowsersync(Event $event) {
    try {
      (new Filesystem())->copy(__DIR__ . '/../assets/docker-compose.browsersync.yaml', static::$ddevRoot . 'docker-compose.browsersync.yaml');
      $event->getIO()->info('<info>docker-compose.browsersync.yaml added.</info>');
    }
    catch (\Error $e) {
      $event->getIO()->error('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * Add Selenium Chrome for Drupal FunctionalJavascript / Nightwatch tests.
   *
   * Bundled rather than installed via `ddev add-on get` so every project gets
   * a consistent, versioned copy alongside the rest of ddev-drupal.
   */
  protected static function copySeleniumChrome(Event $event) {
    $fileSystem = new Filesystem();
    try {
      $fileSystem->copy(__DIR__ . '/../assets/docker-compose.selenium-chrome.yaml', static::$ddevRoot . 'docker-compose.selenium-chrome.yaml');
      $fileSystem->copy(__DIR__ . '/../assets/config.selenium-standalone-chrome.yaml', static::$ddevRoot . 'config.selenium-standalone-chrome.yaml');
      $event->getIO()->info('<info>Selenium Chrome (FunctionalJavascript / Nightwatch) added.</info>');
    }
    catch (\Error $e) {
      $event->getIO()->error('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * Sync missing config keys from asset config to site config.
   */
  protected static function syncConfig() {
    $fileSystem = new Filesystem();
    $assetConfigPath = __DIR__ . '/../assets/config.yaml';

    if (!$fileSystem->exists(static::$configPath) || !$fileSystem->exists($assetConfigPath)) {
      return;
    }

    $siteConfig = Yaml::parseFile(static::$configPath);
    $assetConfig = Yaml::parseFile($assetConfigPath);

    // Merge missing top-level keys.
    $missing = array_diff_key($assetConfig, $siteConfig);
    if (!empty($missing)) {
      $siteConfig = array_merge($siteConfig, $missing);
    }

    // Merge post-start exec-host hooks: asset hooks first, then any unique
    // local hooks appended. This ensures add-on installs run before commands
    // like ddev db, while preserving site-specific hooks like solrcollection.
    // Only runs when the asset actually defines hooks.
    if (!empty($assetConfig['hooks']['post-start'])) {
      $siteConfig = static::mergePostStartHooks($siteConfig, $assetConfig);
    }

    $fileSystem->dumpFile(static::$configPath, Yaml::dump($siteConfig, 2, 2));
  }

  /**
   * Merge post-start hooks from asset config into site config.
   *
   * Asset hooks are placed first, followed by any unique site-specific hooks.
   *
   * @param array $siteConfig
   *   The site configuration.
   * @param array $assetConfig
   *   The asset configuration.
   *
   * @return array
   *   The site configuration with merged hooks.
   */
  protected static function mergePostStartHooks(array $siteConfig, array $assetConfig) {
    $assetHooks = $assetConfig['hooks']['post-start'] ?? [];
    $siteHooks = $siteConfig['hooks']['post-start'] ?? [];

    // Collect asset exec-host values for deduplication.
    $assetValues = [];
    foreach ($assetHooks as $hook) {
      $assetValues[] = $hook['exec-host'];
    }

    // Start with asset hooks, then append unique site hooks.
    $merged = $assetHooks;
    foreach ($siteHooks as $hook) {
      if (isset($hook['exec-host']) && in_array($hook['exec-host'], $assetValues)) {
        continue;
      }
      $merged[] = $hook;
    }

    $siteConfig['hooks']['post-start'] = $merged;
    return $siteConfig;
  }

  /**
   * Drop top-level keys left at their ddev default.
   *
   * Carrying a key whose value already equals ddev's default is pure noise —
   * removing it changes nothing ddev does but keeps config.yaml legible. Only
   * an exact (strict) match is pruned, so an intentionally non-default value
   * (e.g. `xdebug_enabled: true`) is preserved.
   *
   * The map is a deliberately small allowlist of stable toggles/ports.
   * Version-sensitive keys (`php_version`, `database`, `type`) are omitted on
   * purpose: ddev shifts their defaults between releases (e.g. php 8.3 → 8.4),
   * and this code runs in the web container where ddev can't be queried, so a
   * captured baseline would rot. Leaving them out means we never touch a pin.
   * Validated against ddev v1.25.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return array
   *   The configuration with default-valued noise keys removed.
   */
  protected static function pruneDefaultKeys(array $config) {
    $ddevDefaults = [
      'webserver_type' => 'nginx-fpm',
      'xdebug_enabled' => FALSE,
      'additional_hostnames' => [],
      'additional_fqdns' => [],
      'use_dns_when_possible' => TRUE,
      'composer_version' => '2',
      'corepack_enable' => FALSE,
      'xhgui_https_port' => '8142',
      'xhgui_http_port' => '8143',
    ];
    foreach ($ddevDefaults as $key => $default) {
      if (array_key_exists($key, $config) && $config[$key] === $default) {
        unset($config[$key]);
      }
    }
    return $config;
  }

  /**
   * Drop web_environment vars already supplied by a config.*.yaml override.
   *
   * ddev merges every `.ddev/config.*.yaml` into the effective config, so any
   * var the main config repeats from an override (e.g. the Selenium/test vars
   * in config.selenium-standalone-chrome.yaml) is redundant. Removing the
   * duplicate keeps the main config focused on what's genuinely site-specific
   * (e.g. the Pantheon vars). A no-op when no override files exist — e.g. on
   * WordPress, which ships no such override.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return array
   *   The configuration with override-provided env vars removed.
   */
  protected static function dedupeWebEnvironment(array $config) {
    if (empty($config['web_environment'])) {
      return $config;
    }
    // Collect every var defined by a merged override file.
    $overrideVars = [];
    $ddevDir = dirname(static::$configPath);
    foreach (glob($ddevDir . '/config.*.yaml') ?: [] as $file) {
      $override = Yaml::parseFile($file);
      foreach ($override['web_environment'] ?? [] as $var) {
        $overrideVars[$var] = TRUE;
      }
    }
    if (!$overrideVars) {
      return $config;
    }
    $config['web_environment'] = array_values(array_filter(
      $config['web_environment'],
      function ($var) use ($overrideVars) {
        return !isset($overrideVars[$var]);
      }
    ));
    // Drop the key entirely if nothing site-specific is left.
    if (!$config['web_environment']) {
      unset($config['web_environment']);
    }
    return $config;
  }

  /**
   * Install Solr.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  protected static function installSolr(Event $event, $update = FALSE) {
    $fileSystem = new Filesystem();
    $io = $event->getIO();
    if ($update) {
      // Don't prompt during an update; preserve the current Solr state and
      // only rebuild the assets when it is already enabled.
      $status = $fileSystem->exists(static::$ddevRoot . 'docker-compose.solr.yaml');
    }
    else {
      $status = $io->askConfirmation('<info>Do you need Solr support?</info> [<comment>no</comment>]:' . "\n > ", FALSE);
    }
    if ($status) {
      try {
        $config = Yaml::parseFile(static::$configPath);
        $existingHooks = array_column($config['hooks']['post-start'] ?? [], 'exec-host');
        if (!in_array('ddev solrcollection', $existingHooks)) {
          $config['hooks']['post-start'][]['exec-host'] = 'ddev solrcollection';
        }
        $fileSystem->dumpFile(static::$configPath, Yaml::dump($config));
        $fileSystem->mirror(__DIR__ . '/../assets/solr', static::$ddevRoot . 'solr');
        $fileSystem->copy(__DIR__ . '/../assets/docker-compose.solr.yaml', static::$ddevRoot . 'docker-compose.solr.yaml');

        $io->info('[Enabled] Solr');
      }
      catch (\Error $e) {
        $io->error('<error>' . $e->getMessage() . '</error>');
      }

      // Update site.settings.php.
      $settingsLocalPath = static::getWebRootPath() . static::$settingsLocalPath;
      if ($fileSystem->exists($settingsLocalPath)) {
        try {
          $data = file_get_contents($settingsLocalPath);
          if (strpos($data, 'Search api local configuration overrides.') === FALSE) {
            $data .= "\n" . file_get_contents(__DIR__ . '/../assets/settings.local.solr.append');
            $fileSystem->dumpFile($settingsLocalPath, $data);
          }
        }
        catch (\Error $e) {
          $io->error('<error>' . $e->getMessage() . '</error>');
        }
      }
    }
    else {
      $fileSystem->remove(static::$ddevRoot . 'solr');
      $fileSystem->remove(static::$ddevRoot . 'docker-compose.solr.yaml');
    }
  }

  /**
   * Remove legacy wkhtmltopdf packages.
   *
   * Pantheon dropped wkhtmltopdf support in PHP runtime gen 2.
   */
  protected static function cleanupWkhtmltopdf(Event $event) {
    $io = $event->getIO();
    $composer = $event->getComposer();
    $repo = $composer->getRepositoryManager()->getLocalRepository();
    $target = 'mikehaertl/phpwkhtmltopdf';

    if (!$repo->findPackage($target, '*')) {
      return;
    }

    $fileSystem = new Filesystem();
    $fileSystem->remove(static::$ddevRoot . 'web-build/Dockerfile.ddev-wkhtmltox');

    $projectRoot = realpath(__DIR__ . '/../../../../');
    $cwd = getcwd();
    chdir($projectRoot);
    passthru('composer remove ' . escapeshellarg($target), $removeExit);
    if ($removeExit !== 0) {
      chdir($cwd);
      $io->error('<error>Failed to remove ' . $target . '. Run `composer remove ' . $target . '` manually.</error>');
      return;
    }

    passthru('composer require dompdf/dompdf', $requireExit);
    chdir($cwd);
    if ($requireExit !== 0) {
      $io->error('<error>Failed to install dompdf/dompdf. Run `composer require dompdf/dompdf` manually.</error>');
      return;
    }

    static::flipEntityPrintEngine($projectRoot, $io);

    $io->write('');
    $io->write('<info>Pantheon no longer supports wkhtmltopdf; dompdf substituted.</info>');
    $importNow = $io->askConfirmation('<info>Import configuration changes now?</info> [<comment>yes</comment>]:' . "\n > ", TRUE);
    if ($importNow) {
      chdir($projectRoot);
      passthru('drush cim -y', $cimExit);
      chdir($cwd);
      if ($cimExit !== 0) {
        $io->error('<error>drush cim failed. Run `drush cim` manually to apply the engine change.</error>');
      }
    }
    else {
      $io->write('<info>Run <comment>drush cim</comment> when ready to apply the engine change.</info>');
    }
    $io->write('<info>PDF-specific CSS may need rework — dompdf has no flexbox, limited transforms, and no JS.</info>');
    $io->write('<info>Run <comment>ddev restart</comment> to rebuild the container without wkhtmltopdf.</info>');
    static::upgradePantheonRuntime($projectRoot, $io);
    $io->write('');
  }

  /**
   * Drop php_runtime_generation: 1 from pantheon.yml if present.
   *
   * wkhtmltopdf was the sole blocker keeping sites on gen 1; removing the
   * explicit line lets Pantheon default to gen 2.
   */
  protected static function upgradePantheonRuntime($projectRoot, $io) {
    $path = $projectRoot . '/pantheon.yml';
    if (!file_exists($path)) {
      return;
    }
    $content = file_get_contents($path);
    $updated = preg_replace('/^php_runtime_generation:\s*1\s*\r?\n/m', '', $content);
    if ($updated !== NULL && $updated !== $content) {
      file_put_contents($path, $updated);
      $io->write('');
      $io->write('<info>wkhtmltopdf was the only dependency keeping this site on PHP runtime generation 1.</info>');
      $io->write('<info>pantheon.yml has been updated to drop the explicit gen 1 pin — commit and push to upgrade the remote.</info>');
    }
  }

  /**
   * Flip entity_print's exported pdf_engine from phpwkhtmltopdf to dompdf.
   *
   * Uses string replacement to preserve the file's existing formatting.
   */
  protected static function flipEntityPrintEngine($projectRoot, $io) {
    $path = $projectRoot . '/config/entity_print.settings.yml';
    if (!file_exists($path)) {
      $io->error('<error>Expected ' . $path . ' but it was not found; skip flipping engine.</error>');
      return;
    }
    $content = file_get_contents($path);
    $updated = str_replace('pdf_engine: phpwkhtmltopdf', 'pdf_engine: dompdf', $content);
    if ($updated !== $content) {
      file_put_contents($path, $updated);
    }
  }

  /**
   * Remove legacy commands that have moved to plugins.
   */
  protected static function cleanup() {
    $fileSystem = new Filesystem();
    $dbCommand = static::$ddevRoot . 'commands/host/db';

    if ($fileSystem->exists($dbCommand)) {
      $contents = file_get_contents($dbCommand);
      if (strpos($contents, '#ddev-generated') === FALSE) {
        $fileSystem->remove($dbCommand);
      }
    }
  }

  /**
   * Ddev installs its own version of Terminus.
   * Terminus 4 requires PHP 8.2+.
   *
   * If the site's PHP version is < 8.2, install Terminus 3.
   */
  protected static function downgradeTerminus(Event $event, $phpVersion) {
    $fileSystem = new Filesystem();
    $io = $event->getIO();

    if (version_compare($phpVersion, '8.2', '<')) {
      try {
        $fileSystem->copy(__DIR__ . '/../assets/web-build/Dockerfile.ddev-terminus', static::$ddevRoot . 'web-build/Dockerfile.ddev-terminus');
      }
      catch (\Error $e) {
        $io->error('<error>' . $e->getMessage() . '</error>');
      }
    }
    else {
      $fileSystem->remove(static::$ddevRoot . 'web-build/Dockerfile.ddev-terminus');
    }
  }

  /**
   * Get docroot.
   */
  protected static function getWebRootPath() {
    $root = __DIR__ . '/../../../../';
    if ($docroot = static::$docRoot) {
      $root .= $docroot . '/';
    }
    return $root;
  }

  /**
   * Hash the contents of every file the scaffolding run manages.
   *
   * Taken before and after run() in update mode to tell a real refresh from a
   * no-op: postUpdate fires on every `composer update`, and prompting for a
   * restart when nothing changed is just misleading noise. Hashing file
   * *contents* (not mtimes) means the unconditional copies/dumps the run
   * performs don't register as changes when the bytes are identical.
   *
   * The docroot is read from config.yaml rather than static::$docRoot, because
   * the "before" snapshot is taken before update mode populates that property.
   *
   * @return string
   *   A content hash of the managed file set, stable across no-op runs.
   */
  protected static function fingerprint() {
    $docRoot = static::$docRoot;
    if ($docRoot === NULL && is_file(static::$configPath)) {
      $docRoot = Yaml::parseFile(static::$configPath)['docroot'] ?? 'web';
    }
    $webRoot = __DIR__ . '/../../../../' . ($docRoot ? $docRoot . '/' : '');

    $paths = [
      static::$configPath,
      static::$gitIgnorePath,
      $webRoot . static::$settingsLocalPath,
      static::$ddevRoot . 'docker-compose.browsersync.yaml',
      static::$ddevRoot . 'docker-compose.selenium-chrome.yaml',
      static::$ddevRoot . 'config.selenium-standalone-chrome.yaml',
      static::$ddevRoot . 'docker-compose.solr.yaml',
      static::$ddevRoot . 'web-build/Dockerfile.ddev-terminus',
      static::$ddevRoot . 'commands/host/db',
      // Solr ships a directory of assets, hashed recursively below.
      static::$ddevRoot . 'solr',
    ];

    $parts = [];
    foreach ($paths as $path) {
      $parts[] = $path . ':' . static::hashPath($path);
    }
    return md5(implode('|', $parts));
  }

  /**
   * Content hash of a file, or of an entire directory tree, or '' if absent.
   *
   * Directory entries are sorted before hashing so the result is independent
   * of filesystem iteration order.
   *
   * @param string $path
   *   A file or directory path.
   *
   * @return string
   *   A stable hash of the path's contents, or '' when it does not exist.
   */
  protected static function hashPath($path) {
    if (is_file($path)) {
      return md5_file($path);
    }
    if (!is_dir($path)) {
      return '';
    }
    $hashes = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      if ($file->isFile()) {
        $hashes[$file->getPathname()] = md5_file($file->getPathname());
      }
    }
    ksort($hashes);
    $parts = [];
    foreach ($hashes as $file => $hash) {
      $parts[] = $file . ':' . $hash;
    }
    return md5(implode('|', $parts));
  }

}
