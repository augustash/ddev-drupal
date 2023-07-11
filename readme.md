# Setup

Add the following to root composer.json:

Root level:
```
"autoload": {
    "classmap": [
        "scripts/composer/ScriptHandler.php"
    ]
}

"scripts": {
    "post-install-cmd": "chmod +x .ddev/scripts/setBaseConfiguration.sh && .ddev/scripts/setBaseConfiguration.sh"
}
```

extra -> allowed-packages:
```
"augustash/ddev"
```

extra -> installer-paths
```
".ddev": ["augustash/ddev"],
```

Run composer install, follow prompts.

# Configuration

On composer install, you will be prompted for client-code and pantheon-site-name. These are used to set the config.yaml name and project environment variable.

# Database

Database will be downloaded automatically, this is handled in /.ddev/commands/host/db.
  There must be no tables in the existing db.

# Solr

You will be prompted to install solr. If you select no, solr will be removed from the project.

Collection/core will be automatically created. Collection/core is aliased to 'search'.

Add below code to [client-code].settings.local.php(example file), and settings.local.php.
  Create/assign server/index names and configuration overrides accordingly.
  Our setups are usually an index of global and server of local.


  todo: automatically write settings to settings.local.php.

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

[configuration-options]: https://ddev.readthedocs.io/en/latest/users/configuration/config/
