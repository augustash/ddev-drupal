# Basic setup

Drop into root of drupal site.

Edit config.yaml:
* Name should match client code.
* Replace [project-name] with pantheon site-name.
* Adjust settings as needed.
  - Ddev [configuration options][configuration-options].

# Solr setup

If you need solr, leave as-is.

# Remove Solr
Remove /solr, and /.ddev/docker-compose.solr.yaml.
Search .ddev for '[solrRemove]', and remove code.

Solr collection/core will be automatically created, named 'search'.
Settings should be taken from /web/sites/[site-name].settings.local.php 'Search api local configuration overrides.' section, and placed within /web/sites/default/settings.local.php.

# Database

Database will be downloaded automatically, this is handled in /.ddev/commands/host/db.
  config.yml web_environment project variable must be correctly set to the pantheon site-name.
  There must be no tables in the existing db.

After database is downloaded, solr collection/core will be automatically created. This is handled within /.ddev/commands/host/solrcollection.

[configuration-options]: https://ddev.readthedocs.io/en/latest/users/configuration/config/