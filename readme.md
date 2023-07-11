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

Copy solr settings from [client-code].settings.local.php to settings.local.php. They are commented as 'Search api local configuration overrides.'.
  todo: automatically write settings to settings.local.php.

[configuration-options]: https://ddev.readthedocs.io/en/latest/users/configuration/config/