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
          echo "Solr installed\n";
        } else {
          $content = str_replace('-exec-host: ddev solrcollection', '#  -exec-host: ddev solrcollection', $content);
          if (file_put_contents($configuration, $content)) {
            // Remove docker-compose.solr.yaml and solr directory from .ddev.
            unlink(__DIR__ . '/../../.ddev/docker-compose.solr.yaml');
            shell_exec('rm -rf ' . __DIR__ . '/../../.ddev/solr');

            echo "Solr not installed.\n";
          }
        }

      }

    }
}
