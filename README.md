# Setup

### Single line

```bash
ddev composer config --json --merge extra.drupal-scaffold.allowed-packages '["augustash/ddev-drupal"]' && ddev composer config scripts.ddev-setup "Augustash\\Ddev::postPackageInstall" && ddev composer require --dev augustash/ddev-drupal && ddev composer ddev-setup
```

# Updating

The scaffolding refreshes **automatically** in update mode (no prompts,
ddev-only) via two `composer.json` hooks — keeping your `config.yaml` values and
rebuilding only managed files (Selenium, BrowserSync, Solr, Terminus, the
Pantheon add-on hook).

**≥ 1.1.62** — automatic; just update:
```bash
ddev composer update augustash/ddev-drupal   # or `ddev composer install` after a pull
```

**< 1.1.62** — wire the hooks in once, then it's automatic:
```bash
ddev composer ddev-setup update
```

### Changing configuration values

Since refreshes are automatic, you only run setup by hand when you actually need
to **change** a value (a typo'd client code, a PHP bump, …):
```bash
ddev composer ddev-setup
```

### Zookeeper image swap (Solr add-on)
The `zoo` service moved from the legacy Bitnami image to the official multi-arch
`zookeeper:3.9` image (fixes the `linux/amd64` platform warning on Apple Silicon).
The data path changed from `/bitnami/zookeeper` to `/data`, so the existing volume
must be recreated. After updating the add-on, run:
```bash
ddev stop && docker volume rm "ddev-$(ddev describe -j | jq -r .raw.name)_zoo" && ddev start
```
The `post-start` `ddev solrcollection` hook will repopulate the configset and
recreate the collection in the fresh ZK store.

### Solr version (8 or 9)
When Solr is enabled, `ddev-setup` asks which major version to scaffold, matching
Pantheon Search's offering. The chosen file is stamped to
`.ddev/docker-compose.solr.yaml`; update runs preserve the choice (and re-pin the
image), so a `composer update` never reverts your version.

  - **8** — the legacy 3-node SolrCloud cluster with external ZooKeeper
    (`docker-compose.solr.yaml`), pinned to Pantheon's `solr:8.11.4`.
  - **9** — the official single-node
    [ddev-solr](https://github.com/ddev/ddev-solr) build with embedded ZooKeeper
    (`docker-compose.solr9.yaml`), pinned to Pantheon's `solr:9.10.1`.

**Deploying Solr 9 to Pantheon.** The prompt only scaffolds local ddev —
Pantheon's Solr 9 is provisioned separately, and one step isn't discoverable:

1. `pantheon.yml`: add a `search:` block with `version: 9`, then commit + push —
   Pantheon provisions Solr 9 on deploy.
2. Pin `solr_version` to `9` on each search server in **settings.php** — unset,
   Search API Solr emits a Solr 8 configset that won't load on Solr 9:
   ```php
   $config['search_api.server.pantheon_search']['backend_config']['connector_config']['solr_version'] = '9';
   $config['search_api.server.local']['backend_config']['connector_config']['solr_version'] = '9';
   ```
3. Upload the v9 configset — **nobody guesses this one; without it search is
   dead** — then reindex:
   ```bash
   terminus remote:drush <site>.<env> -- search-api-pantheon:postSchema
   terminus remote:drush <site>.<env> -- search-api:index <index>
   ```

> Reindex erroring with *“pending server tasks could not be executed”* over a
> phantom index (e.g. a leftover `removeIndex` from the old Solr)? Clear the
> stale task, then reindex:
> ```bash
> terminus remote:drush <site>.<env> -- sqlq "DELETE FROM search_api_task WHERE server_id='pantheon_search'"
> ```

# Configuration

Running `ddev composer ddev-setup` (first-time setup, or to change a value) prompts
for:
  - Client code
  - Document root (defaults to `web`)
  - Drupal version
  - PHP version
  - Is this site hosted on Pantheon? — if yes:
    - Pantheon site name
    - Pantheon site environment
  - Subdomains (optional)
  - Solr support (and, if enabled, Solr version 8 or 9)

These are used to set config.yaml ddev configuration. On a re-run each prompt is
pre-filled with the project's current value, so pressing enter through them keeps
the existing configuration unchanged.

# Database

Database pull is handled by the [ddev-pantheon-db](https://github.com/augustash/ddev-pantheon-db) add-on, which is automatically installed on `ddev start`.

Will not download if there is more than one table in the existing local db.

To force a fresh pull:
  `ddev db -f`

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

#### Solr version is 8.11.4 in docker-compose.solr, but does not match server version.
  - Build version was changed to 8.11.4 to match Pantheon hosts exact version.
    - Recreate your collection.
      - Navigate in your browser to http://[site-name].ddev.site:8983/solr/#/~collections.
        - Delete existing collection.
        - Run ddev solrcollection.
    - Reload your collection.
      - Navigate to http://[site-name].ddev.site/admin/config/search/search-api/server/[server-name].
        - Click 'Reload Collection'.
        - Server version should now be 8.11.4.

#### TypeError: Drupal\search_api_solr\Utility\SolrCommandHelper::__construct(): Argument #4 ($configset_controller) must be of type Drupal\search_api_solr\Controller\SolrConfigSetController.
  - Update drupal/search_api_pantheon.
