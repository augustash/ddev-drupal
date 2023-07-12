<?php

namespace Augustash;

use Composer\Script\Event;

class Ddev {
    public static function postPackageInstall(Event $event) {
      // Return if on pantheon/platform servers.
      if (defined('PANTHEON_ENVIRONMENT') || defined('PLATFORM_ENVIRONMENT')) {
        return;
      }
      
      $configuration = __DIR__ . '/../../.ddev/config.yaml';
      if (file_exists($configuration) && is_writeable($configuration)) {
        $content = file_get_contents($configuration);

        // Return if file already edited.
        if (strpos($content, '[client-code]') === FALSE) {
          return;
        }

        $clientCode = readline("Client code?\n");
        $siteName = readline("Host project name?\n");

        $content = str_replace('[client-code]', $clientCode, $content);
        $content = str_replace('[host-project-name]', $siteName, $content);

        if (file_put_contents($configuration, $content)) {
          echo "Config.yaml updated.\n";
        } else {
          echo "Config.yaml update failed. Likely config.yaml is not writable.\n";
        }

        $solr = readline("Would in like solr installed?(y/n)\n");
        if ($solr == 'yes' || $solr == 'y') {
          $content = str_replace('# - exec-host: ddev solrcollection', '  - exec-host: ddev solrcollection', $content);
          if (file_put_contents($configuration, $content)) {
            // Add docker-compose.solr.yaml and solr directory from .ddev/assets.
            shell_exec('mv ' . __DIR__ . '/../../.ddev/assets/solr ' . __DIR__ . '/../../.ddev');
            shell_exec('mv ' . __DIR__ . '/../../.ddev/assets/docker-compose.solr.yaml ' . __DIR__ . '/../../.ddev');

            echo "Solr will be installed on ddev start.\n";
          }
        } else {
          echo "Solr not installed.\n";
        }

      }

    }
}
