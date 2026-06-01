# Setup

### Single line

```bash
ddev composer config --json --merge extra.drupal-scaffold.allowed-packages '["augustash/ddev-drupal"]' && ddev composer config scripts.ddev-setup "Augustash\\Ddev::postPackageInstall" && ddev composer config scripts.post-update-cmd "Augustash\\Ddev::postUpdate" && ddev composer require --dev augustash/ddev-drupal && ddev composer ddev-setup
```

> **Why scalar `composer config`, not `--json`?** The two script values contain
> backslashes (the `Augustash\Ddev` namespace separator). Passing them as inline
> JSON through `ddev composer config --json '[...]'` lets the double shell (host →
> container) eat the backslashes, so Composer stores the value as a quoted *string*
> — `"[\"Augustash\\Ddev::postUpdate\"]"` — instead of an array. The next
> `composer update` then fails with `Class "[\"Augustash\Ddev ... is not autoloadable`.
> The scalar form above sidesteps it: a single hook is a valid scalar script value.
> (The `allowed-packages` line keeps `--json` safely — it has no backslashes.)
>
> **Already have a `post-update-cmd`?** (e.g. a Pantheon
> `DrupalComposerManaged\ComposerScripts::postUpdate` hook) the scalar command
> *replaces* it. Skip that one segment and instead add `Augustash\Ddev::postUpdate`
> to the existing array by editing `composer.json` directly, so both hooks run:
> ```json
> "post-update-cmd": [
>     "DrupalComposerManaged\\ComposerScripts::postUpdate",
>     "Augustash\\Ddev::postUpdate"
> ]
> ```

# Updating

The generated scaffolding and hooks refresh **automatically** on every
`composer update`: the `post-update-cmd` hook (`Augustash\Ddev::postUpdate`)
re-runs setup in update mode without re-prompting. So pulling the latest
`ddev-drupal` is normally all you need:
```bash
ddev composer update augustash/ddev-drupal
```
Update mode keeps your existing `config.yaml` values (client code, docroot,
Drupal/PHP version, subdomains) and only rebuilds what may have changed —
Selenium, BrowserSync, Solr (if already enabled), the Terminus image, and the
Pantheon add-on hook (upgraded in place to track `develop`). Run `ddev restart`
afterward to rebuild the containers and re-pull add-ons.

To force a refresh **without** updating the package — or to run the one-time
wkhtmltopdf→dompdf migration, which the automatic hook skips — re-run setup
manually in update mode (`-u`):
```bash
ddev composer ddev-setup -- -u
```

Omit `-u` to be re-prompted for the configuration values (the original setup
flow):
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

# Configuration

On ddev-setup, you will be prompted for:
  - Client code
  - Document root (defaults to `web`)
  - Drupal version
  - PHP version
  - Is this site hosted on Pantheon? — if yes:
    - Pantheon site name
    - Pantheon site environment
  - Subdomains (optional)
  - Solr support

These are used to set config.yaml ddev configuration.

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

