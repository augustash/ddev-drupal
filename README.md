# Troubleshooting

#### Configset upload failed with error code 405: Solr HTTP error: OK (405).<br />Solr HTTP error: OK (405).
  - Rerun ddev start.

#### Drush was unable to query the database.
  - The key part is 'Drush was unable to query the database'.
    - Make sure you do not have database credentials in settings.local.
    - You have an empty database, partial import, something is wrong with it.
    - Download a fresh database.

#### Failed to execute command drush en search_api_solr_admin -y.<br />Failed to execute command drush sapi-sl --field=id:.<br />Failed to execute command drush solr-upload-conf.
  - Run composer require drupal/search_api_solr_admin -W.
  - Run ddev solrcollection.

#### Server [server-name] is not a Solr server.
  - An existing solr server is configured as a database server.
    - Remove this server and create a new solr cloud server.
    - Ensure your settings.local overrides are correct.

#### (The) server with ID 'local' could not be retrieved for index 'Global'.
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

#### Solr version is 8.8.2 in docker-compose.solr, but does not match server version.
  - Build version was changed to 8.8.2 to match Pantheon hosts exact version.
    - Recreate your collection.
      - Navigate in your browser to http://[site-name].ddev.site:8983/solr/#/~collections.
        - Delete existing collection.
        - Run ddev solrcollection.
    - Reload your collection.
      - Navigate to http://[site-name].ddev.site/admin/config/search/search-api/server/[server-name].
        - Click 'Reload Collection'.
        - Server version should now be 8.8.2.

#### TypeError: Drupal\search_api_solr\Utility\SolrCommandHelper::__construct(): Argument #4 ($configset_controller) must be of type Drupal\search_api_solr\Controller\SolrConfigSetController.
  - Update drupal/search_api_pantheon.


# Setup

### Single line

```bash
composer config --json --merge extra.drupal-scaffold.allowed-packages '["augustash/ddev-drupal"]' && composer config scripts.ddev-setup "Augustash\\Ddev::postPackageInstall" && composer require --dev augustash/ddev-drupal && composer ddev-setup
```


### Manual

Set the following to root composer.json:

Root level:
```
"scripts": {
    "ddev-setup": "Augustash\\Ddev::postPackageInstall"
}
```

extra -> drupal-scaffold -> allowed-packages:
```
"augustash/ddev-drupal"
```

Run:
```
composer require --dev augustash/ddev-drupal && composer ddev-setup
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

Db command is:
  ddev auth ssh && ddev pull pantheon --skip-files -y

# Solr

You will be prompted to install solr.

If installed, collection/core will be automatically created, collection/core is aliased to 'search'.

settings.local search api configuration overrides will be set automatically.
```

# TODO:

Nothing currently.
