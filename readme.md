# Setup

Set the following to root composer.json:

Root level:
```
"scripts": {
    "post-install-cmd": "Augustash\\Ddev::postPackageInstall"
},
```

Repositories:
```
{
    "url": "https://github.com/augustash/ddev.git",
    "type": "git"
}
```

extra -> allowed-packages:
```
"augustash/ddev"
```

```
extra -> installer-paths
".ddev": ["type:augustash-ddev"]
```

Root .gitignore:
```
# Ddev is now composer installed.
/.ddev/
/scripts/ddev/
```

Run:
```
composer require --dev augustash/ddev -W
```
The --dev is needed so that the package will not be built into non-local environment sites.

Once installed, add/edit to root composer.json:
```
"autoload": {
    "classmap": [
        "scripts/composer/ScriptHandler.php"
        "scripts/ddev/ddev.php"
    ]
}
```

Run composer install.
Follow prompts.

# Configuration

On composer install, you will be prompted for client-code and host-project-name. These are used to set the config.yaml name and project environment variable.

# Database

Database will be downloaded automatically, this is handled in /.ddev/commands/host/db.
  Will not download if there are tables in the db.

# Solr

You will be prompted to install solr. If you select no, solr will be removed from the project.

If installed, collection/core will be automatically created. Collection/core is aliased to 'search'.

Add below code to [client-code].settings.local.php(example file), and settings.local.php.
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

Possibly change script from post-install-cmd to post-package-install-cmd.
Possibly add autoloader automatically on post-package-install-cmd.
Cover post-package-uninstall to remove ddev.php from /scripts. 

Write Solr settings above to [client-code].settings.local.php and settings.local.php.

Write 
```
/.ddev/
/scripts/ddev/
```
to gitignore.

# Troubleshooting

Class Augustash\Ddev is not autoloadable, can not call post-install-cmd script.
  Add "scripts/ddev/ddev.php" to composer.json autoload. Bottom of 'Setup' section.

[configuration-options]: https://ddev.readthedocs.io/en/latest/users/configuration/config/
