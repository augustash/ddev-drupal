# Setup

### Single line

```bash
ddev composer config --json --merge extra.drupal-scaffold.allowed-packages '["augustash/ddev-drupal"]' && ddev composer config scripts.ddev-setup "Augustash\\Ddev::postPackageInstall" && ddev composer require --dev augustash/ddev-drupal && ddev composer ddev-setup
```

# Updating

The generated scaffolding refreshes **automatically** — nobody has to remember a
setup command. The initial `ddev composer ddev-setup` wires two composer hooks
into `composer.json` (`post-install-cmd` → `Augustash\Ddev::postInstall`,
`post-update-cmd` → `postUpdate`), merged alongside any hooks already there. From
then on they re-run setup in update mode — no prompts — on every composer install
or update:
```bash
ddev composer update augustash/ddev-drupal   # or just `ddev composer install` after a pull
```

> **Upgrading a project installed before 1.1.62?** The auto-refresh hooks have to
> be wired into your `composer.json` once — run `ddev composer ddev-setup update`
> (no prompts). After that you never run it again; the scaffolding is kept
> up-to-date automatically on every install/update.

Update mode keeps your existing `config.yaml` values (client code, docroot,
Drupal/PHP version, subdomains, Pantheon env) and only rebuilds what may have
changed — Selenium, BrowserSync, Solr (if already enabled), the Terminus image,
and the Pantheon add-on hook (upgraded in place to track `develop`).

The hooks run **only inside ddev** (guarded on `IS_DDEV_PROJECT`), so a Pantheon
build, CI, or host `composer install` never touches the `.ddev` scaffolding.

When a run changes a managed file it ends with *“Scaffolding refreshed — run
`ddev restart` to acquire the changes”*; a no-op ends with *“Everything
up-to-date.”* The script runs inside the web container and can't invoke the
host's `ddev`, so it tells you to restart rather than doing it for you (ddev also
warns natively when `config.yaml` changes).

### Changing configuration values

Since refreshes are automatic, you only run setup by hand when you actually need
to **change** a value (a typo'd client code, a PHP bump, …):
```bash
ddev composer ddev-setup
```
It walks the config prompts pre-filled with your current answers — press enter to
keep each, or type a new value for the one you're changing. The one-time
wkhtmltopdf→dompdf migration also runs from here (the auto hooks skip it).

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
  - Solr support

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
