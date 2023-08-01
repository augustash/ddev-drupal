# Troubleshooting

#### Failed to execute command drush en search_api_solr_admin -y -q: exit status 1<br />Command sapi-sl was not found. Drush was unable to query the database.
  - The key part is 'Drush was unable to query the database'.
    - Make sure you do not have database credentials in settings.local.
    - You have an empty database, partial import, something is wrong with it.
    - Download a fresh database.

#### The server with ID 'local' could not be retrieved for index 'Global'.
  - The server 'local' does not exist.
  - Comment out configuration overrides in settings.local.php.
    - Start ddev, create the server [name].
      - Assign the following values:
        - Server name: local
        - Backend: solr
        - Configure Solr Backend: Solr Cloud with Basic Auth
        - Default Solr Collection: search
        - Username: solr
        - Password: SolrRocks
    - Uncomment configuration values.
    - Make sure [name] matches the configuration overrides, in all respective lines.
      - Ex. $config['search_api.index.global']['server'] = [name];
      - Ex. $config['search_api.server.[name]']['backend_config']['connector'] = 'solr_cloud_basic_auth';
    - Run: ddev solrcollection

#### Server [server-name] is not a Solr server.
  - An existing solr server is configured as a database server.
    - Remove this server and create a new solr cloud server.
    - Ensure your settings.local overrides are correct.

#### Configset upload failed with error code 405: Solr HTTP error: OK (405).<br />Solr HTTP error: OK (405).
  - Rerun ddev start.

#### Failed to execute command drush en search_api_solr_admin -y.<br />Failed to execute command drush sapi-sl --field=id:.<br />Failed to execute command drush solr-upload-conf.
  - Run composer require drupal/search_api_solr_admin -W.
  - Run ddev solrcollection.

#### TypeError: Drupal\search_api_solr\Utility\SolrCommandHelper::__construct(): Argument #4 ($configset_controller) must be of type Drupal\search_api_solr\Controller\SolrConfigSetController.
  - Update drupal/search_api_pantheon.

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
