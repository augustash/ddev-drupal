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
   * Run on post-install-cmd.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  public static function postPackageInstall(Event $event) {
    // Return if on pantheon/platform servers.
    // Pantheon php is not compiled with readline.
    if (!function_exists('readline')) {
      return;
    }

    $fileSystem = new Filesystem();
    if ($fileSystem->exists(static::$configPath)) {
      $io = $event->getIO();
      $config = Yaml::parseFile(static::$configPath);

      // Return if file already edited.
      if (!empty($config['name'])) {
        return;
      }

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
        $fileSystem->dumpFile(static::$configPath, Yaml::dump($config));
        $io->info('<info>Config.yaml updated.</info>');
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
    $io = $event->getIO();
    $status = $io->askConfirmation('<info>Would in like Solr support?</info> [<comment>no</comment>]:' . "\n > ", FALSE);
    if ($status) {
      try {
        $fileSystem = new Filesystem();
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
    }
  }

  /**
   * Install wkhtmltopdf.
   */
  protected static function installWkhtmltopdf(Event $event) {
    $io = $event->getIO();
    $status = $io->askConfirmation('<info>Would in like wkhtmltopdf support?</info> [<comment>no</comment>]:' . "\n > ", FALSE);
    if ($status) {
      $fileSystem = new Filesystem();
      try {
        $fileSystem = new Filesystem();
        $config = Yaml::parseFile(static::$configPath);
        $config['hooks']['post-start'][] = '"exec-host: ddev solrcollection"';
        $fileSystem->copy(__DIR__ . '/../assets/web-build/Dockerfile.ddev-wkhtmltox', __DIR__ . '/../../../../.ddev/web-build/Dockerfile.ddev-wkhtmltox');
        $io->info('[Enabled] wkhtmltopdf');
      }
      catch (\Error $e) {
        $io->error('<error>' . $e->getMessage() . '</error>');
      }
    }
  }

}
