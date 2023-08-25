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
      ];
      $drupalVersion = $io->select('<info>Drupal version</info> [<comment>10</comment>]:', $drupalVersions, '10');

      $phpVersions = [
        '7.4',
        '8.1',
        '8.2',
      ];
      $phpVersion = $io->select('<info>PHP version</info> [<comment>8.1</comment>]:', $phpVersions, '8.1');

      $config['name'] = $clientCode;
      $config['docroot'] = $docRoot;
      $config['type'] = 'drupal' . $drupalVersions[$drupalVersion];
      $config['php_version'] = $phpVersions[$phpVersion];
      $config['web_environment'] = [
        'project=' . $siteName . '.' . $siteEnv,
      ];

      // Subdomain configuration handling.
      $subdomains = explode(' ', $io->askConfirmation('<info>Subdomains? (space delimiter) [<comment>no</comment>]</info>:' . "\n > ", FALSE));
      if ($subdomains) {
        $config['additional_hostnames'] = [];

        foreach ($subdomains as $subdomain) {
          $config['additional_hostnames'][] = $subdomain. '.' . $clientCode;
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
        $io->error('<error>'. $e->getMessage() .'</error>');
      }

      static::installSolr($event);
      static::installWkhtmltopdf($event);
    }
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
        $config['hooks']['post-start'][]['exec-host'] = 'ddev solrcollection';
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
   * Install wkhtmltopdf.
   */
  protected static function installWkhtmltopdf(Event $event) {
    $fileSystem = new Filesystem();
    $io = $event->getIO();
    $status = $io->askConfirmation('<info>Do you need wkhtmltopdf support?</info> [<comment>no</comment>]:' . "\n > ", FALSE);
    if ($status) {
      try {
        $fileSystem = new Filesystem();
        $fileSystem->copy(__DIR__ . '/../assets/web-build/Dockerfile.ddev-wkhtmltox', static::$ddevRoot . 'web-build/Dockerfile.ddev-wkhtmltox');
        $io->info('[Enabled] wkhtmltopdf');
      }
      catch (\Error $e) {
        $io->error('<error>' . $e->getMessage() . '</error>');
      }
    }
    else {
      $fileSystem->remove(static::$ddevRoot . 'web-build/Dockerfile.ddev-wkhtmltox');
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
