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
   * Run on post-install-cmd.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  public static function postPackageInstall(Event $event) {

    static::syncConfig();
    static::cleanup();
    static::cleanupWkhtmltopdf($event);

    $fileSystem = new Filesystem();
    if ($fileSystem->exists(static::$configPath)) {
      $io = $event->getIO();
      $config = Yaml::parseFile(static::$configPath);

      $clientCode = $io->ask('<info>Client code?</info>:' . "\n > ");
      $docRoot = static::$docRoot = $io->ask('<info>Document root?</info>  [<comment>web</comment>]:' . "\n > ", 'web') ?: '';
      $siteName = $io->ask('<info>Pantheon site name</info> [<comment>' . 'aai' . $clientCode . '</comment>]:' . "\n > ", 'aai' . $clientCode);
      $siteEnv = $io->ask('<info>Pantheon site environment (dev|test|live)</info> [<comment>live</comment>]:' . "\n > ", 'live');

      $drupalVersions = [
        '7',
        '8',
        '9',
        '10',
        '11',
      ];
      $drupalVersion = $io->select('<info>Drupal version</info> [<comment>10</comment>]:', $drupalVersions, '10');

      $phpVersions = [
        '7.4',
        '8.1',
        '8.2',
        '8.3',
      ];
      $phpVersion = $io->select('<info>PHP version</info> [<comment>8.1</comment>]:', $phpVersions, '8.1');

      static::downgradeTerminus($event, $phpVersion);

      $config['name'] = $clientCode;
      $config['docroot'] = $docRoot;
      $config['type'] = 'drupal' . $drupalVersions[$drupalVersion];
      $config['php_version'] = $phpVersions[$phpVersion];
      $config['web_environment'] = [
        'DDEV_PANTHEON_SITE=' . $siteName,
        'DDEV_PANTHEON_ENVIRONMENT=' . $siteEnv
      ];

      // Subdomain configuration handling.
      $subdomains = $io->ask('<info>Subdomains? (space delimiter)</info> [<comment>no</comment>]:' . "\n > ", FALSE);
      if ($subdomains) {
        $subdomains = explode(' ', $subdomains);
        $config['additional_hostnames'] = [];

        foreach ($subdomains as $subdomain) {
          $config['additional_hostnames'][] = $subdomain . '.' . $clientCode;
        }
      }

      // config.yaml.
      try {
        $fileSystem->dumpFile(static::$configPath, Yaml::dump($config, 2, 2));
        $io->info('<info>Config.yaml updated.</info>');
      }
      catch (\Error $e) {
        $io->error('<error>' . $e->getMessage() . '</error>');
      }

      // settings.local.php.
      $settingsLocalPath = static::getWebRootPath() . static::$settingsLocalPath;
      if (!$fileSystem->exists($settingsLocalPath)) {
        try {
          $data = file_get_contents(__DIR__ . '/../assets/settings.local.php');
          $fileSystem->dumpFile($settingsLocalPath, $data);
        }
        catch (\Error $e) {
          $io->error('<error>' . $e->getMessage() . '</error>');
        }
      }

      // .gitignore.
      try {
        $gitignore = $fileSystem->exists(static::$gitIgnorePath) ? file_get_contents(static::$gitIgnorePath) : '';
        if (strpos($gitignore, '# Ignore ddev files') === FALSE) {
          $gitignore .= "\n" . file_get_contents(__DIR__ . '/../assets/.gitignore.append');
          $fileSystem->dumpFile(static::$gitIgnorePath, $gitignore);
        }
      }
      catch (\Error $e) {
        $io->error('<error>' . $e->getMessage() . '</error>');
      }

      // docker-compose.browsersync.yaml.
      try {
        $fileSystem->copy(__DIR__ . '/../assets/docker-compose.browsersync.yaml', static::$ddevRoot . 'docker-compose.browsersync.yaml');
        $io->info('<info>docker-compose.browsersync.yaml added.</info>');
      }
      catch (\Error $e) {
        $io->error('<error>' . $e->getMessage() . '</error>');
      }

      // Selenium Chrome for Drupal FunctionalJavascript / Nightwatch tests.
      // Bundled rather than installed via `ddev add-on get` so every project
      // gets a consistent, versioned copy alongside the rest of ddev-drupal.
      try {
        $fileSystem->copy(__DIR__ . '/../assets/docker-compose.selenium-chrome.yaml', static::$ddevRoot . 'docker-compose.selenium-chrome.yaml');
        $fileSystem->copy(__DIR__ . '/../assets/config.selenium-standalone-chrome.yaml', static::$ddevRoot . 'config.selenium-standalone-chrome.yaml');
        $io->info('<info>Selenium Chrome (FunctionalJavascript / Nightwatch) added.</info>');
      }
      catch (\Error $e) {
        $io->error('<error>' . $e->getMessage() . '</error>');
      }

      static::installSolr($event);
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
    $siteConfig = static::mergePostStartHooks($siteConfig, $assetConfig);

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
    $assetHooks = $assetConfig['hooks']['post-start'];
    $siteHooks = $siteConfig['hooks']['post-start'];

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
   * Install Solr.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  protected static function installSolr(Event $event) {
    $fileSystem = new Filesystem();
    $io = $event->getIO();
    $status = $io->askConfirmation('<info>Do you need Solr support?</info> [<comment>no</comment>]:' . "\n > ", FALSE);
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
   * Terminus 4 requires php 8.2.
   *
   * If sites php version is < 8.2, install Terminus 3.
   */
  protected static function downgradeTerminus(Event $event, $phpVersion) {
    $fileSystem = new Filesystem();
    $io = $event->getIO();

    // Phpversion is option selection number, not actual version.
    // All future options will be greater than 2.
    if ($phpVersion < 2) {
      try {
        $fileSystem = new Filesystem();
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

}
