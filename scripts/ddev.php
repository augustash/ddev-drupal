<?php

namespace Augustash;

use Composer\Script\Event;

class Ddev {

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

    $config_path = __DIR__ . '/../../../../.ddev/config.yaml';
    if (file_exists($config_path) && is_writable($config_path)) {
      $config = file_get_contents($config_path);

      // Return if file already edited.
      if (strpos($config, '[client-code]') === FALSE) {
        return;
      }

      $clientCode = readline("Client code?\n");
      $siteName = readline("Host site name?\n");

      $config = str_replace('[client-code]', $clientCode, $config);
      $config = str_replace('[host-site-name]', $siteName, $config);

      if (file_put_contents($config_path, $config)) {
        echo "Config.yaml updated.\n";
      }
      else {
        echo "Config.yaml update failed. Likely config.yaml is not writable.\n";
      }

      static::installSolr($config, $config_path);
      static::installWkhtmltopdf();
    }
  }

  /**
   * Install Solr.
   *
   * @param string $config
   *   The config.yaml file contents.
   * @param string $config_path
   *   The config.yaml file path.
   */
  protected static function installSolr($config, $config_path) {
    $status = readline("Would in like Solr support?(y/n)\n");
    if ($status == 'yes' || $status == 'y') {
      $config = str_replace('# - exec-host: ddev solrcollection', '  - exec-host: ddev solrcollection', $config);
      if (file_put_contents($config_path, $config)) {
        // Add docker-compose.solr.yaml and solr directory from .ddev/assets.
        shell_exec('mv ' . __DIR__ . '/../assets/solr ' . __DIR__ . '/../../../../.ddev');
        shell_exec('mv ' . __DIR__ . '/../assets/docker-compose.solr.yaml ' . __DIR__ . '/../../../../.ddev');
        echo "Solr will be installed on ddev start.\n";
      }
    }
    else {
      echo "Solr disabled.\n";
    }
  }

  /**
   * Install wkhtmltopdf.
   */
  protected static function installWkhtmltopdf() {
    $status = readline("Would in like wkhtmltopdf support?(y/n)\n");
    if ($status == 'yes' || $status == 'y') {
      shell_exec('mv ' . __DIR__ . '/../assets/web-build/Dockerfile.ddev-wkhtmltox ' . __DIR__ . '/../../../../.ddev/web-build');
      echo "wkhtmltopdf will be installed on ddev start/restart.\n";
    }
    else {
      echo "wkhtmltopdf disabled.\n";
    }
  }

}
