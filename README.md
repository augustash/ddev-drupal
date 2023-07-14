# Setup

Set the following to root composer.json:

Root level:
```
"scripts": {
    "ddev-setup": "Augustash\\Ddev::postPackageInstall"
}
```

Run:
```
composer require augustash/ddev-drupal && composer run-script ddev-setup
```

Compose install will trigger configuration script, follow prompts.

# Configuration

On ddev-setup, you will be prompted for:
  - client code
  - Pantheon site name
  - Pantheon site environment
  - Drupal version
  - PHP version
  - Solr support
  - wkhtmltopdf support

These are used to set the config.yaml name and project environment variables.

# Database

Database will be downloaded automatically, this is handled in /.ddev/commands/host/db.
  Will not download if there are tables in the existing local db.

# Solr

You will be prompted to install solr.

If installed, collection/core will be automatically created. Collection/core is aliased to 'search'.

Verify the below code has been adding to the site settings.local.php.
Create/assign server/index names and configuration overrides accordingly.
Our setups are usually an index of global and server of local.

```
/**
 * Search api local configuration overrides.
 */
$config['search_api.index.global']['server'] = 'local';
$config['search_api.server.local']['status'] = TRUE;
$config['search_api.server.local']['backend_config']['connector'] = 'solr_cloud_basic_auth';
$config['search_api.server.local']['backend_config']['connector_config']['scheme'] = 'http';
$config['search_api.server.local']['backend_config']['connector_config']['host'] = $_ENV['DDEV_HOSTNAME'];
$config['search_api.server.local']['backend_config']['connector_config']['core'] = 'search';
$config['search_api.server.local']['backend_config']['connector_config']['username'] = 'solr';
$config['search_api.server.local']['backend_config']['connector_config']['password'] = 'SolrRocks';
$config['search_api.server.local']['backend_config']['connector_config']['port'] = 8983;
```

# TODO:

Nothing currently.

# Troubleshooting

Unable to install modules search_api_solr_admin due to missing modules search_api_solr_admin.
Command "solr-upload-conf" is not defined.
  Solr is attempting to install. Did you run composer install and answer no/n?
    Delete .ddev entirely and composer install, follow prompts.
    Or, does your config.yaml contain '[client-code]' still?
      Yes, run composer install.
      No, comment out '- exec-host: ddev solrcollection' in config.yaml.
      Remove docker-compose.solr.yaml and solr directory.

[configuration-options]: https://ddev.readthedocs.io/en/latest/users/configuration/config/
