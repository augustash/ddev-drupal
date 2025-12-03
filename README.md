# Setup

### Single line

```bash
composer config --json --merge extra.drupal-scaffold.allowed-packages '["augustash/ddev-drupal"]' && composer config scripts.ddev-setup "Augustash\\Ddev::postPackageInstall" && composer require --dev augustash/ddev-drupal && composer ddev-setup
```

# Updating
There are required changes in ddev 1.24.10.
```bash
composer require --dev augustash/ddev-drupal && composer ddev-setup
```

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
  ddev db -f
