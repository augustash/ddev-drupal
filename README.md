# Troubleshooting

Server [server-name] is not a Solr server.
  An existing solr server is configured as a database server.
    Ensure your settings.local overrides are correct.

Configset upload failed with error code 405: Solr HTTP error: OK (405)
Solr HTTP error: OK (405)
  Rerun ddev start.

Failed to execute command drush en search_api_solr_admin -y
Failed to execute command drush sapi-sl --field=id:
Failed to execute command drush solr-upload-conf
  Run composer require drupal/search_api_solr_admin
  Run ddev solrcollection

# Setup

Set the following to root composer.json:

Root level:
```
"scripts": {
    "ddev-setup": "Augustash\\Ddev::postPackageInstall"
}
```

extra -> allowed-packages:
```
"augustash/ddev-drupal"
```

Run:
```
composer require augustash/ddev-drupal && composer ddev-setup
```

Composer install will trigger configuration script, follow prompts.

# Configuration

On ddev-setup, you will be prompted for:
  - Client code
  - Pantheon site name
  - Pantheon site environment
  - Drupal version
  - PHP version
  - Solr support
  - wkhtmltopdf support

These are used to set config.yaml ddev configuration.

# Database

Database will be downloaded automatically, this is handled in /.ddev/commands/host/db.
  Will not download if there are tables in the existing local db.

# Solr

You will be prompted to install solr.

If installed, collection/core will be automatically created, collection/core is aliased to 'search'.

Create/assign server/index names and configuration overrides accordingly.
Augustash setups are usually an index of global and server of local.

Verify the below code has been adding to the site settings.local.php.

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
