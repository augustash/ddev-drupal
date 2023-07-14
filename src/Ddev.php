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
  private static $settingsLocalPath = __DIR__ . '/../../../../sites/default/settings.local.php';

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
      $siteName = $io->ask('<info>Pantheon site name</info> [<comment>' . 'aai' . $clientCode . '</comment>]:' . "\n > ", 'aai' . $clientCode);
      $siteEnv = $io->ask('<info>Pantheon site environment (dev|test|live)</info> [<comment>live</comment>]:' . "\n > ", 'live');
      $drupalVersion = $io->select('<info>Drupal version</info> [<comment>10</comment>]:', [
        '7' => '7',
        '8' => '8',
        '9' => '9',
        '10' => '10',
      ], '10');
      $phpVersion = $io->select('<info>PHP version</info> [<comment>8.1</comment>]:', [
        '7.4' => '7.4',
        '8.1' => '8.1',
        '8.2' => '8.2',
      ], '8.1');

      $config['name'] = $clientCode;
      $config['type'] = 'drupal' . $drupalVersion;
      $config['php_version'] = $phpVersion;
      $config['web_environment'] = [
        'project=' . $siteName . '.' . $siteEnv,
      ];

      try {
        $fileSystem->dumpFile(static::$configPath, Yaml::dump($config, 2, 2));
        $io->info('<info>Config.yaml updated.</info>');
      }
      catch (\Error $e) {
        $io->error('<error>' . $e->getMessage() . '</error>');
      }

      // Update .gitignore.
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
        $fileSystem->mirror(__DIR__ . '/../assets/solr', __DIR__ . '/../../../../.ddev/solr');
        $fileSystem->copy(__DIR__ . '/../assets/docker-compose.solr.yaml', __DIR__ . '/../../../../.ddev/docker-compose.solr.yaml');
        $io->info('[Enabled] Solr');
      }
      catch (\Error $e) {
        $io->error('<error>' . $e->getMessage() . '</error>');
      }

      // Update site.settings.php.
      if ($fileSystem->exists(static::$settingsLocalPath)) {
        try {
          $data = file_get_contents(static::$settingsLocalPath);
          if (strpos($data, 'Search api local configuration overrides.') === FALSE) {
            $data .= "\n" . file_get_contents(__DIR__ . '/../assets/settings.local.solr.append');
            $fileSystem->dumpFile(static::$settingsLocalPath, $data);
          }
        }
        catch (\Error $e) {
          $io->error('<error>' . $e->getMessage() . '</error>');
        }
      }
    }
    else {
      $fileSystem->remove(__DIR__ . '/../../../../.ddev/solr');
      $fileSystem->remove(__DIR__ . '/../../../../.ddev/docker-compose.solr.yaml');
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
        $fileSystem->copy(__DIR__ . '/../assets/web-build/Dockerfile.ddev-wkhtmltox', __DIR__ . '/../../../../.ddev/web-build/Dockerfile.ddev-wkhtmltox');
        $io->info('[Enabled] wkhtmltopdf');
      }
      catch (\Error $e) {
        $io->error('<error>' . $e->getMessage() . '</error>');
      }
    }
    else {
      $fileSystem->remove(__DIR__ . '/../../../../.ddev/web-build/Dockerfile.ddev-wkhtmltox');
    }
  }

}
