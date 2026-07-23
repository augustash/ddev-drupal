<?php

namespace Augustash;

use Composer\Json\JsonManipulator;
use Composer\Script\Event;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Ddev console class.
 */
class Ddev {

  /**
   * Path to config file.
   *
   * @var string
   */
  private static $configPath = __DIR__ . '/../../../../.ddev/config.yaml';

  /**
   * Path to the project composer.json.
   *
   * @var string
   */
  private static $composerPath = __DIR__ . '/../../../../composer.json';

  /**
   * Path to gitignore file.
   *
   * @var string
   */
  private static $gitIgnorePath = __DIR__ . '/../../../../.gitignore';

  /**
   * Path to gitignore file.
   *
   * @var string
   */
  private static $settingsLocalPath = 'sites/default/settings.local.php';

  /**
   * The ddev root.
   *
   * @var string
   */
  private static $ddevRoot = __DIR__ . '/../../../../.ddev/';

  /**
   * The docroot.
   *
   * @var string
   */
  private static $docRoot;

  /**
   * Entry point for the `ddev-setup` composer script.
   *
   * Runs the interactive config flow, each prompt seeded from any existing value
   * so pressing enter preserves it (which also makes a non-interactive run
   * non-destructive — it re-affirms the current config rather than clobbering
   * it). Routine scaffolding refreshes happen automatically via the
   * install/update hooks, so a manual run means "I want to (re)configure". The
   * unadvertised `update` argument forces a no-prompt refresh instead. See run()
   * for the orchestration.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  public static function postPackageInstall(Event $event) {
    // Wire the hooks first so the one-time bootstrap lands even if the config
    // prompts below are aborted — the wiring is independent of them.
    static::ensureComposerHooks($event);
    static::run($event, static::isUpdateMode($event), TRUE);
  }

  /**
   * Auto-fired on `composer install` (post-install-cmd).
   *
   * Catches teammates who pull the project and `composer install` without
   * knowing setup exists — the scaffolding refreshes for them, no command to
   * remember. See autoRefresh() for the ddev guard.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  public static function postInstall(Event $event) {
    static::autoRefresh($event);
  }

  /**
   * Auto-fired on `composer update` (post-update-cmd).
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  public static function postUpdate(Event $event) {
    static::autoRefresh($event);
  }

  /**
   * Shared auto-refresh for the install/update hooks — ddev context only.
   *
   * Runs in update mode (no prompts) and skips the wkhtmltopdf migration, which
   * performs its own composer operations and must not run inside a composer
   * install/update. Guarded to the ddev web container: `composer install` also
   * runs during Pantheon's build, CI, and host tooling, where rewriting the
   * .ddev scaffolding would be wrong or destructive — those are a silent no-op.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  private static function autoRefresh(Event $event) {
    if (!static::isDdevContext()) {
      return;
    }
    static::run($event, TRUE, FALSE);
  }

  /**
   * Whether we're running inside the ddev web container.
   *
   * DDEV sets IS_DDEV_PROJECT=true in the web container and nowhere else, so it
   * cleanly separates a ddev run from a Pantheon build / CI / host composer run.
   *
   * @return bool
   *   TRUE when running inside ddev.
   */
  protected static function isDdevContext() {
    return getenv('IS_DDEV_PROJECT') === 'true';
  }

  /**
   * Wire the install/update auto-refresh hooks into composer.json in place.
   *
   * Runs on the manual `ddev composer ddev-setup` path so the hooks are laid
   * down the first time setup is run; thereafter they fire on their own. The two
   * lifecycle events may already hold another handler (e.g. a Pantheon
   * DrupalComposerManaged hook), so ours is merged in, not overwritten —
   * `composer config` can't: `--merge --json` stringifies the array and clobbers
   * a scalar, and the `Augustash\Ddev` backslashes are eaten through the host→
   * container double shell. Doing it here keeps a backslash a backslash, and
   * JsonManipulator preserves the file's existing formatting rather than
   * reflowing it. `ddev-setup` itself isn't touched — it's a scalar nobody else
   * defines, wired by the installer directly.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  protected static function ensureComposerHooks(Event $event) {
    if (!file_exists(static::$composerPath)) {
      return;
    }
    $contents = file_get_contents(static::$composerPath);
    $config = json_decode($contents, TRUE);
    if (!is_array($config)) {
      return;
    }
    $scripts = $config['scripts'] ?? [];
    $handlers = [
      'post-install-cmd' => 'Augustash\\Ddev::postInstall',
      'post-update-cmd' => 'Augustash\\Ddev::postUpdate',
    ];

    $manipulator = new JsonManipulator($contents);
    $wired = [];
    foreach ($handlers as $name => $handler) {
      $current = $scripts[$name] ?? NULL;
      $merged = static::mergeHook($current, $handler);
      if ($merged !== $current) {
        $manipulator->addSubNode('scripts', $name, $merged);
        $wired[] = $name;
      }
    }

    if ($wired) {
      file_put_contents(static::$composerPath, $manipulator->getContents());
      $event->getIO()->info('<info>Wired composer auto-refresh hooks: ' . implode(', ', $wired) . '.</info>');
    }
  }

  /**
   * Merge our handler into an existing composer script value.
   *
   * Missing → create as a scalar; an existing scalar → [theirs, ours]; an
   * existing array → append ours. Already present → returned unchanged (strict
   * `===` compare against the input), so a re-run never duplicates and writes
   * nothing.
   *
   * @param string|array|null $current
   *   The current value of the script hook.
   * @param string $handler
   *   Our handler, e.g. 'Augustash\Ddev::postUpdate'.
   *
   * @return string|array
   *   The merged value.
   */
  protected static function mergeHook($current, $handler) {
    if ($current === NULL) {
      return $handler;
    }
    $list = is_array($current) ? $current : [$current];
    if (in_array($handler, $list, TRUE)) {
      return $current;
    }
    $list[] = $handler;
    return $list;
  }

  /**
   * Shared setup/refresh routine.
   *
   * Thin orchestrator: each step is its own method (see syncConfig/cleanup).
   * On a fresh run the inputs (client code, docroot, Drupal/PHP version) are
   * gathered via prompts and threaded through the configure* steps; in update
   * mode they are inferred from the existing config so nothing is re-prompted.
   * The $config array is written once at the end.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   * @param bool $update
   *   When TRUE, skip prompts and refresh in place from the existing config.
   * @param bool $runWkhtmltopdf
   *   When TRUE, run the one-time wkhtmltopdf→dompdf migration.
   */
  protected static function run(Event $event, $update, $runWkhtmltopdf) {
    // postUpdate fires this on every `composer update`, so most runs are no-ops
    // that rewrite the scaffolding byte-for-byte. Fingerprint the managed files
    // up front (update mode only) so the closing message reflects whether
    // anything that actually lands in the containers changed.
    $before = $update ? static::fingerprint() : NULL;

    static::syncConfig();
    static::cleanup();
    if ($runWkhtmltopdf) {
      static::cleanupWkhtmltopdf($event);
    }

    if (!(new Filesystem())->exists(static::$configPath)) {
      return;
    }

    $io = $event->getIO();
    $config = Yaml::parseFile(static::$configPath);

    if ($update) {
      // Update mode: keep all configured values and skip the prompts. Infer
      // prior choices from the existing config so the generated scaffolding and
      // hooks can be refreshed in place — e.g. an existing Pantheon site has its
      // add-on hook upgraded.
      static::$docRoot = $config['docroot'] ?? 'web';
      // Rename legacy Pantheon env vars before detection so sites configured
      // prior to the DDEV_ prefix switch are recognised and refreshed.
      $config = static::migratePantheonEnv($config);
      if (static::isPantheonSite($config)) {
        $config = static::applyPantheonHooks($config);
        static::downgradeTerminus($event, $config['php_version'] ?? '8.1');
      }
      $io->info('<info>Update mode: configuration left untouched; rebuilding scaffolding.</info>');
    }
    else {
      // Seed each prompt's default from the existing config so re-running to
      // change one value never silently drops the rest — pressing enter keeps
      // the current value. On a first-time setup these fall back to empties/ddev
      // defaults.
      $nameDefault = $config['name'] ?? NULL;
      $docRootDefault = $config['docroot'] ?? 'web';
      $clientCode = $io->ask('<info>Client code?</info>' . ($nameDefault ? '  [<comment>' . $nameDefault . '</comment>]' : '') . ':' . "\n > ", $nameDefault);
      $docRoot = static::$docRoot = $io->ask('<info>Document root?</info>  [<comment>' . $docRootDefault . '</comment>]:' . "\n > ", $docRootDefault) ?: '';
      $drupalVersion = static::selectDrupalVersion($event, static::currentDrupalVersion($config));
      $phpVersion = static::selectPhpVersion($event, $config['php_version'] ?? '8.1');

      $config = static::configureSite($config, $clientCode, $docRoot, $drupalVersion, $phpVersion);
      $config = static::configurePantheon($event, $config, $clientCode, $phpVersion);
      $config = static::configureSubdomains($event, $config, $clientCode);
    }

    // Strip noise before writing: keys left at their ddev default and env vars
    // already supplied by a config.*.yaml override are redundant clutter.
    $config = static::pruneDefaultKeys($config);
    $config = static::dedupeWebEnvironment($config);

    static::writeConfig($event, $config);
    static::writeSettingsLocal($event);
    static::appendGitignore($event);
    static::copyBrowsersync($event);
    static::copySeleniumChrome($event);

    static::installSolr($event, $update);
    static::installRedis($event, $update);

    // Close with an honest status. This runs in the web container and can't
    // trigger `ddev restart` itself (ddev is a host binary, and the in-container
    // ddev is a no-op stub), so when a managed file changed we tell the dev to
    // restart; when nothing did, we say so. A fresh setup always counts as
    // changed; update mode only when the fingerprint actually moved.
    $io->write('');
    if (!$update || static::fingerprint() !== $before) {
      $io->write('<info>Scaffolding refreshed — run</info> <comment>ddev restart</comment> <info>to acquire the changes (rebuild containers, re-pull add-ons).</info>');
    }
    else {
      $io->write('<info>Everything up-to-date.</info>');
    }
    $io->write('');
  }

  /**
   * Whether config.yaml already describes a configured project.
   *
   * "Configured" means a non-empty `name`: the asset config.yaml that a fresh
   * scaffold lands ships an empty name. Used to seed the Pantheon prompt default
   * (a brand-new site defaults to yes; an existing one matches its current
   * state).
   *
   * @return bool
   *   TRUE when config.yaml exists and carries a non-empty name.
   */
  protected static function isConfigured() {
    if (!(new Filesystem())->exists(static::$configPath)) {
      return FALSE;
    }
    $config = Yaml::parseFile(static::$configPath);
    return !empty($config['name']);
  }

  /**
   * Determine whether an explicit update flag was passed.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   *
   * @return bool
   *   TRUE when -u, --update, or update is present.
   */
  protected static function isUpdateMode(Event $event) {
    return static::argsRequestUpdate($event->getArguments());
  }

  /**
   * Whether a raw argument list requests update mode.
   *
   * Split from isUpdateMode() so the flag parsing is unit-testable without a
   * Composer Event.
   *
   * @param array $args
   *   The script arguments.
   *
   * @return bool
   *   TRUE when -u, --update, or update is present.
   */
  protected static function argsRequestUpdate(array $args) {
    foreach ($args as $arg) {
      if (in_array($arg, ['-u', '--update', 'update'], TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Prompt for the Drupal version.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   * @param string $default
   *   The pre-selected version (seeded from the existing config on a re-run);
   *   falls back to "10" when not one of the offered versions.
   *
   * @return string
   *   The selected Drupal major version (e.g. "10").
   */
  protected static function selectDrupalVersion(Event $event, $default = '10') {
    $drupalVersions = [
      '7',
      '8',
      '9',
      '10',
      '11',
    ];
    if (!in_array($default, $drupalVersions, TRUE)) {
      $default = '10';
    }
    $index = $event->getIO()->select('<info>Drupal version</info> [<comment>' . $default . '</comment>]:', $drupalVersions, $default);
    return $drupalVersions[$index];
  }

  /**
   * Prompt for the PHP version.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   * @param string $default
   *   The pre-selected version (seeded from the existing config on a re-run);
   *   falls back to "8.1" when not one of the offered versions.
   *
   * @return string
   *   The selected PHP version (e.g. "8.1").
   */
  protected static function selectPhpVersion(Event $event, $default = '8.1') {
    $phpVersions = [
      '7.4',
      '8.1',
      '8.2',
      '8.3',
    ];
    if (!in_array($default, $phpVersions, TRUE)) {
      $default = '8.1';
    }
    $index = $event->getIO()->select('<info>PHP version</info> [<comment>' . $default . '</comment>]:', $phpVersions, $default);
    return $phpVersions[$index];
  }

  /**
   * Extract the Drupal major version from an existing config's type.
   *
   * Seeds the version prompt so a re-run defaults to the site's current Drupal
   * version rather than the generic fallback.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return string
   *   The major version (e.g. "10"), or "10" when type is absent/unparseable.
   */
  protected static function currentDrupalVersion(array $config) {
    if (!empty($config['type']) && preg_match('/(\d+)/', $config['type'], $m)) {
      return $m[1];
    }
    return '10';
  }

  /**
   * Apply the core site settings.
   *
   * @return array
   *   The updated configuration.
   */
  protected static function configureSite(array $config, $clientCode, $docRoot, $drupalVersion, $phpVersion) {
    // Guard the name: an empty client code (e.g. a prompt answered with enter,
    // or a non-interactive run) must never overwrite an existing name. A
    // first-time setup legitimately starts empty and gets its name set here.
    if ($clientCode !== NULL && $clientCode !== '') {
      $config['name'] = $clientCode;
    }
    $config['docroot'] = $docRoot;
    $config['type'] = 'drupal' . $drupalVersion;
    $config['php_version'] = $phpVersion;
    return $config;
  }

  /**
   * Optionally wire up Pantheon hosting.
   *
   * Pantheon hosting is optional. Only wire up the Pantheon DB pull (env vars,
   * post-start add-on/db hooks, Terminus) when the site is actually hosted
   * there. Non-Pantheon sites skip all of it.
   *
   * @return array
   *   The updated configuration.
   */
  protected static function configurePantheon(Event $event, array $config, $clientCode, $phpVersion) {
    $io = $event->getIO();
    [$existingSite, $existingEnv] = static::pantheonEnvValues($config);

    // Default the confirmation from the site's current state: an already-Pantheon
    // site defaults to yes, a configured non-Pantheon one to no. A brand-new
    // setup has neither, so default to yes (the common case for these sites).
    $default = static::isConfigured() ? static::isPantheonSite($config) : TRUE;
    $hint = $default ? '[<comment>Y/n</comment>]' : '[<comment>y/N</comment>]';
    if (!$io->askConfirmation('<info>Is this site hosted on Pantheon?</info> ' . $hint . ' ', $default)) {
      // Non-Pantheon: drop any stale Terminus build artifact.
      (new Filesystem())->remove(static::$ddevRoot . 'web-build/Dockerfile.ddev-terminus');
      return $config;
    }

    // Seed from the existing env vars on a re-run; otherwise derive the usual
    // 'aai'<client-code> guess for a first-time setup.
    $siteDefault = $existingSite ?: 'aai' . $clientCode;
    $envDefault = $existingEnv ?: 'live';
    $siteName = $io->ask('<info>Pantheon site name</info> [<comment>' . $siteDefault . '</comment>]:' . "\n > ", $siteDefault);
    $siteEnv = $io->ask('<info>Pantheon site environment (dev|test|live)</info> [<comment>' . $envDefault . '</comment>]:' . "\n > ", $envDefault);

    $config['web_environment'] = [
      'DDEV_PANTHEON_SITE=' . $siteName,
      'DDEV_PANTHEON_ENVIRONMENT=' . $siteEnv,
    ];

    $config = static::applyPantheonHooks($config);

    static::downgradeTerminus($event, $phpVersion);

    return $config;
  }

  /**
   * Pull the current Pantheon site/environment out of web_environment.
   *
   * Seeds the Pantheon prompts so a re-run defaults to the existing values
   * rather than re-deriving a guess from the client code.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return array
   *   A [site, environment] pair; either element is NULL when not present.
   */
  protected static function pantheonEnvValues(array $config) {
    $site = NULL;
    $env = NULL;
    foreach ($config['web_environment'] ?? [] as $var) {
      if (strpos($var, 'DDEV_PANTHEON_SITE=') === 0) {
        $site = substr($var, strlen('DDEV_PANTHEON_SITE='));
      }
      elseif (strpos($var, 'DDEV_PANTHEON_ENVIRONMENT=') === 0) {
        $env = substr($var, strlen('DDEV_PANTHEON_ENVIRONMENT='));
      }
    }
    return [$site, $env];
  }

  /**
   * Rename legacy Pantheon env vars to their DDEV_-prefixed equivalents.
   *
   * Sites configured before the prefix switch carry PANTHEON_SITE= and
   * WORKING_ENVIRONMENT= in web_environment. Update mode skips the prompts that
   * would rewrite these, so without this step isPantheonSite() never matches
   * and the pantheon-db add-on hook is never asserted. Renaming in place lets
   * the existing detection path light up on the next `-u` run.
   *
   * The very oldest sites instead carry a single `project=<site>.<env>` var
   * (e.g. `project=mysite.live`) that packs both values into one dot-separated
   * string — the original pantheon.yaml provider split it with `IFS='.'`. That
   * one var is expanded into the two DDEV_-prefixed vars here so those sites
   * migrate forward the same as the PANTHEON_SITE= generation.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return array
   *   The configuration with any legacy Pantheon env vars renamed.
   */
  protected static function migratePantheonEnv(array $config) {
    if (empty($config['web_environment'])) {
      return $config;
    }
    // Oldest format: a single `project=<site>.<env>` var. Split it into the two
    // DDEV_-prefixed vars in place before the prefix renames below. Only the
    // first such var is meaningful; a missing environment defaults to 'live',
    // matching configurePantheon()'s default.
    foreach ($config['web_environment'] as $i => $var) {
      if (strpos($var, 'project=') === 0) {
        $parts = explode('.', substr($var, strlen('project=')));
        array_splice($config['web_environment'], $i, 1, [
          'DDEV_PANTHEON_SITE=' . $parts[0],
          'DDEV_PANTHEON_ENVIRONMENT=' . ($parts[1] ?? 'live'),
        ]);
        break;
      }
    }
    // Legacy var name => current DDEV_-prefixed name.
    $renames = [
      'PANTHEON_SITE=' => 'DDEV_PANTHEON_SITE=',
      'WORKING_ENVIRONMENT=' => 'DDEV_PANTHEON_ENVIRONMENT=',
    ];
    foreach ($config['web_environment'] as &$var) {
      foreach ($renames as $legacy => $current) {
        // Match the legacy prefix only, leaving the value intact. The guard
        // skips vars already migrated (DDEV_PANTHEON_SITE= contains the legacy
        // PANTHEON_SITE= substring but not as a prefix).
        if (strpos($var, $legacy) === 0) {
          $var = $current . substr($var, strlen($legacy));
          break;
        }
      }
    }
    unset($var);
    return $config;
  }

  /**
   * Detect whether the existing config describes a Pantheon-hosted site.
   *
   * Used by update mode, where the "Is this site hosted on Pantheon?" prompt is
   * skipped: the answer is inferred from the Pantheon env var or an existing
   * add-on hook so the hook can be refreshed without re-prompting.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return bool
   *   TRUE when the site is configured for Pantheon.
   */
  protected static function isPantheonSite(array $config) {
    foreach ($config['web_environment'] ?? [] as $var) {
      if (strpos($var, 'DDEV_PANTHEON_SITE=') === 0) {
        return TRUE;
      }
    }
    foreach ($config['hooks']['post-start'] ?? [] as $hook) {
      if (isset($hook['exec-host']) && strpos($hook['exec-host'], 'ddev add-on get augustash/ddev-pantheon-db') === 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Assert the Pantheon DB post-start hooks, upgrading any prior version.
   *
   * Pulls the Pantheon DB on start via the add-on (tracking the develop branch
   * so it self-updates). Any pre-existing pantheon-db add-on hook is stripped
   * first so the hook is upgraded in place rather than duplicated; other
   * site-specific hooks (e.g. solrcollection) are preserved and de-duplicated.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return array
   *   The configuration with the Pantheon hooks asserted.
   */
  protected static function applyPantheonHooks(array $config) {
    if (!empty($config['hooks']['post-start'])) {
      $config['hooks']['post-start'] = array_values(array_filter(
        $config['hooks']['post-start'],
        function ($hook) {
          return !isset($hook['exec-host'])
            || strpos($hook['exec-host'], 'ddev add-on get augustash/ddev-pantheon-db') !== 0;
        }
      ));
    }

    $pantheonHooks = [
      'hooks' => [
        'post-start' => [
          // Redirect output: the add-on re-fetches on every start, so its
          // ddev-core "Use ddev restart to enable" notice and the add-on's own
          // install message would otherwise print on every `ddev start`. The
          // dedup/upgrade matching below keys on the command prefix, so the
          // trailing redirect does not affect detection.
          ['exec-host' => 'ddev add-on get augustash/ddev-pantheon-db --version develop >/dev/null 2>&1'],
          ['exec-host' => 'ddev db'],
        ],
      ],
    ];
    return static::mergePostStartHooks($config, $pantheonHooks);
  }

  /**
   * Prompt for and apply additional subdomain hostnames.
   *
   * @return array
   *   The updated configuration.
   */
  protected static function configureSubdomains(Event $event, array $config, $clientCode) {
    $subdomains = $event->getIO()->ask('<info>Subdomains? (space delimiter)</info> [<comment>no</comment>]:' . "\n > ", FALSE);
    if ($subdomains) {
      $config['additional_hostnames'] = [];
      foreach (explode(' ', $subdomains) as $subdomain) {
        $config['additional_hostnames'][] = $subdomain . '.' . $clientCode;
      }
    }
    return $config;
  }

  /**
   * Write the assembled configuration to config.yaml.
   */
  protected static function writeConfig(Event $event, array $config) {
    $io = $event->getIO();
    try {
      (new Filesystem())->dumpFile(static::$configPath, Yaml::dump($config, 2, 2));
      $io->info('<info>Config.yaml updated.</info>');
    }
    catch (\Error $e) {
      $io->error('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * Seed the local settings file when missing.
   */
  protected static function writeSettingsLocal(Event $event) {
    $fileSystem = new Filesystem();
    $settingsLocalPath = static::getWebRootPath() . static::$settingsLocalPath;
    if ($fileSystem->exists($settingsLocalPath)) {
      return;
    }
    try {
      $data = file_get_contents(__DIR__ . '/../assets/settings.local.php');
      $fileSystem->dumpFile($settingsLocalPath, $data);
    }
    catch (\Error $e) {
      $event->getIO()->error('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * Append the ddev ignore rules to .gitignore once.
   */
  protected static function appendGitignore(Event $event) {
    $fileSystem = new Filesystem();
    try {
      $gitignore = $fileSystem->exists(static::$gitIgnorePath) ? file_get_contents(static::$gitIgnorePath) : '';
      if (strpos($gitignore, '# Ignore ddev files') === FALSE) {
        $gitignore .= "\n" . file_get_contents(__DIR__ . '/../assets/.gitignore.append');
        $fileSystem->dumpFile(static::$gitIgnorePath, $gitignore);
      }
    }
    catch (\Error $e) {
      $event->getIO()->error('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * Copy a package asset into the project, always overwriting the target.
   *
   * Symfony's Filesystem::copy() is mtime-gated: with the default
   * $overwriteNewerFiles = FALSE it copies only when filemtime(source) >
   * filemtime(target), so an existing target that is not older than the asset is
   * silently skipped. Composer-installed assets carry install-time mtimes, so
   * against an already-deployed .ddev file a package update never lands. These
   * files are package-owned scaffolding, so force the overwrite — `-u` must drop
   * the latest copy every time, regardless of mtime.
   */
  protected static function copyAsset($source, $target) {
    (new Filesystem())->copy($source, $target, TRUE);
  }

  /**
   * Mirror a package asset directory into the project, always overwriting.
   *
   * Same mtime-gating as copyAsset(): mirror() delegates to copy() with the
   * 'override' option defaulting FALSE. Force override so every file in the
   * directory refreshes on `-u`.
   */
  protected static function mirrorAsset($source, $target) {
    (new Filesystem())->mirror($source, $target, NULL, ['override' => TRUE]);
  }

  /**
   * Add the BrowserSync docker-compose service.
   */
  protected static function copyBrowsersync(Event $event) {
    try {
      static::copyAsset(__DIR__ . '/../assets/docker-compose.browsersync.yaml', static::$ddevRoot . 'docker-compose.browsersync.yaml');
      $event->getIO()->info('<info>docker-compose.browsersync.yaml added.</info>');
    }
    catch (\Error $e) {
      $event->getIO()->error('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * Add Selenium Chrome for Drupal FunctionalJavascript / Nightwatch tests.
   *
   * Bundled rather than installed via `ddev add-on get` so every project gets
   * a consistent, versioned copy alongside the rest of ddev-drupal.
   */
  protected static function copySeleniumChrome(Event $event) {
    try {
      static::copyAsset(__DIR__ . '/../assets/docker-compose.selenium-chrome.yaml', static::$ddevRoot . 'docker-compose.selenium-chrome.yaml');
      static::copyAsset(__DIR__ . '/../assets/config.selenium-standalone-chrome.yaml', static::$ddevRoot . 'config.selenium-standalone-chrome.yaml');
      $event->getIO()->info('<info>Selenium Chrome (FunctionalJavascript / Nightwatch) added.</info>');
    }
    catch (\Error $e) {
      $event->getIO()->error('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * Sync missing config keys from asset config to site config.
   */
  protected static function syncConfig() {
    $fileSystem = new Filesystem();
    $assetConfigPath = __DIR__ . '/../assets/config.yaml';

    if (!$fileSystem->exists(static::$configPath) || !$fileSystem->exists($assetConfigPath)) {
      return;
    }

    $siteConfig = Yaml::parseFile(static::$configPath);
    $assetConfig = Yaml::parseFile($assetConfigPath);

    // Merge missing top-level keys.
    $missing = array_diff_key($assetConfig, $siteConfig);
    if (!empty($missing)) {
      $siteConfig = array_merge($siteConfig, $missing);
    }

    // Merge post-start exec-host hooks: asset hooks first, then any unique
    // local hooks appended. This ensures add-on installs run before commands
    // like ddev db, while preserving site-specific hooks like solrcollection.
    // Only runs when the asset actually defines hooks.
    if (!empty($assetConfig['hooks']['post-start'])) {
      $siteConfig = static::mergePostStartHooks($siteConfig, $assetConfig);
    }

    $fileSystem->dumpFile(static::$configPath, Yaml::dump($siteConfig, 2, 2));
  }

  /**
   * Merge post-start hooks from asset config into site config.
   *
   * Asset hooks are placed first, followed by any unique site-specific hooks.
   *
   * @param array $siteConfig
   *   The site configuration.
   * @param array $assetConfig
   *   The asset configuration.
   *
   * @return array
   *   The site configuration with merged hooks.
   */
  protected static function mergePostStartHooks(array $siteConfig, array $assetConfig) {
    $assetHooks = $assetConfig['hooks']['post-start'] ?? [];
    $siteHooks = $siteConfig['hooks']['post-start'] ?? [];

    // Collect asset exec-host values for deduplication.
    $assetValues = [];
    foreach ($assetHooks as $hook) {
      $assetValues[] = $hook['exec-host'];
    }

    // Start with asset hooks, then append unique site hooks.
    $merged = $assetHooks;
    foreach ($siteHooks as $hook) {
      if (isset($hook['exec-host']) && in_array($hook['exec-host'], $assetValues)) {
        continue;
      }
      $merged[] = $hook;
    }

    $siteConfig['hooks']['post-start'] = $merged;
    return $siteConfig;
  }

  /**
   * Drop top-level keys left at their ddev default.
   *
   * Carrying a key whose value already equals ddev's default is pure noise —
   * removing it changes nothing ddev does but keeps config.yaml legible. Only
   * an exact (strict) match is pruned, so an intentionally non-default value
   * (e.g. `xdebug_enabled: true`) is preserved.
   *
   * The map is a deliberately small allowlist of stable toggles/ports.
   * Version-sensitive keys (`php_version`, `database`, `type`) are omitted on
   * purpose: ddev shifts their defaults between releases (e.g. php 8.3 → 8.4),
   * and this code runs in the web container where ddev can't be queried, so a
   * captured baseline would rot. Leaving them out means we never touch a pin.
   * Validated against ddev v1.25.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return array
   *   The configuration with default-valued noise keys removed.
   */
  protected static function pruneDefaultKeys(array $config) {
    $ddevDefaults = [
      'webserver_type' => 'nginx-fpm',
      'xdebug_enabled' => FALSE,
      'additional_hostnames' => [],
      'additional_fqdns' => [],
      'use_dns_when_possible' => TRUE,
      'composer_version' => '2',
      'corepack_enable' => FALSE,
      'xhgui_https_port' => '8142',
      'xhgui_http_port' => '8143',
    ];
    foreach ($ddevDefaults as $key => $default) {
      if (array_key_exists($key, $config) && $config[$key] === $default) {
        unset($config[$key]);
      }
    }
    return $config;
  }

  /**
   * Drop web_environment vars already supplied by a config.*.yaml override.
   *
   * ddev merges every `.ddev/config.*.yaml` into the effective config, so any
   * var the main config repeats from an override (e.g. the Selenium/test vars
   * in config.selenium-standalone-chrome.yaml) is redundant. Removing the
   * duplicate keeps the main config focused on what's genuinely site-specific
   * (e.g. the Pantheon vars). A no-op when no override files exist — e.g. on
   * WordPress, which ships no such override.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return array
   *   The configuration with override-provided env vars removed.
   */
  protected static function dedupeWebEnvironment(array $config) {
    if (empty($config['web_environment'])) {
      return $config;
    }
    // Collect every var defined by a merged override file.
    $overrideVars = [];
    $ddevDir = dirname(static::$configPath);
    foreach (glob($ddevDir . '/config.*.yaml') ?: [] as $file) {
      $override = Yaml::parseFile($file);
      foreach ($override['web_environment'] ?? [] as $var) {
        $overrideVars[$var] = TRUE;
      }
    }
    if (!$overrideVars) {
      return $config;
    }
    $config['web_environment'] = array_values(array_filter(
      $config['web_environment'],
      function ($var) use ($overrideVars) {
        return !isset($overrideVars[$var]);
      }
    ));
    // Drop the key entirely if nothing site-specific is left.
    if (!$config['web_environment']) {
      unset($config['web_environment']);
    }
    return $config;
  }

  /**
   * Install Solr.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  protected static function installSolr(Event $event, $update = FALSE) {
    $fileSystem = new Filesystem();
    $io = $event->getIO();
    if ($update) {
      // Don't prompt during an update; preserve the current Solr state and
      // only rebuild the assets when it is already enabled.
      $status = $fileSystem->exists(static::$ddevRoot . 'docker-compose.solr.yaml');
    }
    else {
      $status = $io->askConfirmation('<info>Do you need Solr support?</info> [<comment>no</comment>]:' . "\n > ", FALSE);
    }
    if ($status) {
      try {
        $config = Yaml::parseFile(static::$configPath);
        $existingHooks = array_column($config['hooks']['post-start'] ?? [], 'exec-host');
        if (!in_array('ddev solrcollection', $existingHooks)) {
          $config['hooks']['post-start'][]['exec-host'] = 'ddev solrcollection';
        }
        $fileSystem->dumpFile(static::$configPath, Yaml::dump($config));
        static::mirrorAsset(__DIR__ . '/../assets/solr', static::$ddevRoot . 'solr');
        static::copyAsset(__DIR__ . '/../assets/docker-compose.solr.yaml', static::$ddevRoot . 'docker-compose.solr.yaml');

        $io->info('[Enabled] Solr');
      }
      catch (\Error $e) {
        $io->error('<error>' . $e->getMessage() . '</error>');
      }

      // Update site.settings.php.
      $settingsLocalPath = static::getWebRootPath() . static::$settingsLocalPath;
      if ($fileSystem->exists($settingsLocalPath)) {
        try {
          $data = file_get_contents($settingsLocalPath);
          if (strpos($data, 'Search api local configuration overrides.') === FALSE) {
            $data .= "\n" . file_get_contents(__DIR__ . '/../assets/settings.local.solr.append');
            $fileSystem->dumpFile($settingsLocalPath, $data);
          }
        }
        catch (\Error $e) {
          $io->error('<error>' . $e->getMessage() . '</error>');
        }
      }
    }
    else {
      $fileSystem->remove(static::$ddevRoot . 'solr');
      $fileSystem->remove(static::$ddevRoot . 'docker-compose.solr.yaml');
    }
  }

  /**
   * Optionally enable Redis for prod-parity local caching.
   *
   * Modeled on installSolr(). Redis is the production cache backend; enabling it
   * locally lets cache behavior (compression threshold, lock/checksum services,
   * contention under load) match live — important for representative profiling
   * and load testing.
   *
   * Follows DDEV's own convention (and the existing Solr/Selenium pattern): the
   * service is a bundled, committed docker-compose.redis.yaml + redis/redis.conf
   * copied from assets — not a re-fetching `ddev add-on get` post-start hook,
   * which would re-run on every start and re-assert its own settings management.
   * The Drupal-side connection lives in settings.local.php (appended from
   * assets) and mirrors live exactly (compress_length, prefix, ttl offset,
   * invalidate-as-delete, lock/flood/checksum services, bootstrap container).
   *
   * On a "no" the service files are removed (Solr-style); the settings.local.php
   * block, being a local override, is left in place — a no-op once the service
   * is gone, and harmless to keep.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   * @param bool $update
   *   When TRUE, skip the prompt and preserve the current Redis state.
   */
  protected static function installRedis(Event $event, $update = FALSE) {
    $fileSystem = new Filesystem();
    $io = $event->getIO();
    $composePath = static::$ddevRoot . 'docker-compose.redis.yaml';
    $settingsLocalPath = static::getWebRootPath() . static::$settingsLocalPath;

    if ($update) {
      // Don't prompt during an update; preserve the current Redis state and
      // only rebuild the assets when it is already enabled.
      $status = $fileSystem->exists($composePath);
    }
    else {
      $status = $io->askConfirmation('<info>Do you need Redis support (prod-parity local caching)?</info> [<comment>no</comment>]:' . "\n > ", FALSE);
    }

    if (!$status) {
      $fileSystem->remove($composePath);
      $fileSystem->remove(static::$ddevRoot . 'redis');
      return;
    }

    try {
      static::copyAsset(__DIR__ . '/../assets/docker-compose.redis.yaml', $composePath);
      static::mirrorAsset(__DIR__ . '/../assets/redis', static::$ddevRoot . 'redis');
      $io->info('[Enabled] Redis');
    }
    catch (\Error $e) {
      $io->error('<error>' . $e->getMessage() . '</error>');
    }

    // Append the Redis settings to settings.local.php (once).
    if ($fileSystem->exists($settingsLocalPath)) {
      try {
        $data = file_get_contents($settingsLocalPath);
        if (strpos($data, 'Redis local configuration overrides.') === FALSE) {
          $data .= "\n" . file_get_contents(__DIR__ . '/../assets/settings.local.redis.append');
          $fileSystem->dumpFile($settingsLocalPath, $data);
        }
      }
      catch (\Error $e) {
        $io->error('<error>' . $e->getMessage() . '</error>');
      }
    }
  }

  /**
   * Remove legacy wkhtmltopdf packages.
   *
   * Pantheon dropped wkhtmltopdf support in PHP runtime gen 2.
   */
  protected static function cleanupWkhtmltopdf(Event $event) {
    $io = $event->getIO();
    $composer = $event->getComposer();
    $repo = $composer->getRepositoryManager()->getLocalRepository();
    $target = 'mikehaertl/phpwkhtmltopdf';

    if (!$repo->findPackage($target, '*')) {
      return;
    }

    $fileSystem = new Filesystem();
    $fileSystem->remove(static::$ddevRoot . 'web-build/Dockerfile.ddev-wkhtmltox');

    $projectRoot = realpath(__DIR__ . '/../../../../');
    $cwd = getcwd();
    chdir($projectRoot);
    passthru('composer remove ' . escapeshellarg($target), $removeExit);
    if ($removeExit !== 0) {
      chdir($cwd);
      $io->error('<error>Failed to remove ' . $target . '. Run `composer remove ' . $target . '` manually.</error>');
      return;
    }

    passthru('composer require dompdf/dompdf', $requireExit);
    chdir($cwd);
    if ($requireExit !== 0) {
      $io->error('<error>Failed to install dompdf/dompdf. Run `composer require dompdf/dompdf` manually.</error>');
      return;
    }

    static::flipEntityPrintEngine($projectRoot, $io);

    $io->write('');
    $io->write('<info>Pantheon no longer supports wkhtmltopdf; dompdf substituted.</info>');
    $importNow = $io->askConfirmation('<info>Import configuration changes now?</info> [<comment>yes</comment>]:' . "\n > ", TRUE);
    if ($importNow) {
      chdir($projectRoot);
      passthru('drush cim -y', $cimExit);
      chdir($cwd);
      if ($cimExit !== 0) {
        $io->error('<error>drush cim failed. Run `drush cim` manually to apply the engine change.</error>');
      }
    }
    else {
      $io->write('<info>Run <comment>drush cim</comment> when ready to apply the engine change.</info>');
    }
    $io->write('<info>PDF-specific CSS may need rework — dompdf has no flexbox, limited transforms, and no JS.</info>');
    $io->write('<info>Run <comment>ddev restart</comment> to rebuild the container without wkhtmltopdf.</info>');
    static::upgradePantheonRuntime($projectRoot, $io);
    $io->write('');
  }

  /**
   * Drop php_runtime_generation: 1 from pantheon.yml if present.
   *
   * wkhtmltopdf was the sole blocker keeping sites on gen 1; removing the
   * explicit line lets Pantheon default to gen 2.
   */
  protected static function upgradePantheonRuntime($projectRoot, $io) {
    $path = $projectRoot . '/pantheon.yml';
    if (!file_exists($path)) {
      return;
    }
    $content = file_get_contents($path);
    $updated = preg_replace('/^php_runtime_generation:\s*1\s*\r?\n/m', '', $content);
    if ($updated !== NULL && $updated !== $content) {
      file_put_contents($path, $updated);
      $io->write('');
      $io->write('<info>wkhtmltopdf was the only dependency keeping this site on PHP runtime generation 1.</info>');
      $io->write('<info>pantheon.yml has been updated to drop the explicit gen 1 pin — commit and push to upgrade the remote.</info>');
    }
  }

  /**
   * Flip entity_print's exported pdf_engine from phpwkhtmltopdf to dompdf.
   *
   * Uses string replacement to preserve the file's existing formatting.
   */
  protected static function flipEntityPrintEngine($projectRoot, $io) {
    $path = $projectRoot . '/config/entity_print.settings.yml';
    if (!file_exists($path)) {
      $io->error('<error>Expected ' . $path . ' but it was not found; skip flipping engine.</error>');
      return;
    }
    $content = file_get_contents($path);
    $updated = str_replace('pdf_engine: phpwkhtmltopdf', 'pdf_engine: dompdf', $content);
    if ($updated !== $content) {
      file_put_contents($path, $updated);
    }
  }

  /**
   * Remove legacy commands that have moved to plugins.
   */
  protected static function cleanup() {
    $fileSystem = new Filesystem();
    $dbCommand = static::$ddevRoot . 'commands/host/db';

    if ($fileSystem->exists($dbCommand)) {
      $contents = file_get_contents($dbCommand);
      if (strpos($contents, '#ddev-generated') === FALSE) {
        $fileSystem->remove($dbCommand);
      }
    }
  }

  /**
   * Ddev installs its own version of Terminus.
   * Terminus 4 requires PHP 8.2+.
   *
   * If the site's PHP version is < 8.2, install Terminus 3.
   */
  protected static function downgradeTerminus(Event $event, $phpVersion) {
    $fileSystem = new Filesystem();
    $io = $event->getIO();

    if (version_compare($phpVersion, '8.2', '<')) {
      try {
        static::copyAsset(__DIR__ . '/../assets/web-build/Dockerfile.ddev-terminus', static::$ddevRoot . 'web-build/Dockerfile.ddev-terminus');
      }
      catch (\Error $e) {
        $io->error('<error>' . $e->getMessage() . '</error>');
      }
    }
    else {
      $fileSystem->remove(static::$ddevRoot . 'web-build/Dockerfile.ddev-terminus');
    }
  }

  /**
   * Get docroot.
   */
  protected static function getWebRootPath() {
    $root = __DIR__ . '/../../../../';
    if ($docroot = static::$docRoot) {
      $root .= $docroot . '/';
    }
    return $root;
  }

  /**
   * Hash the contents of every file the scaffolding run manages.
   *
   * Taken before and after run() in update mode to tell a real refresh from a
   * no-op: postUpdate fires on every `composer update`, and prompting for a
   * restart when nothing changed is just misleading noise. Hashing file
   * *contents* (not mtimes) means the unconditional copies/dumps the run
   * performs don't register as changes when the bytes are identical.
   *
   * The docroot is read from config.yaml rather than static::$docRoot, because
   * the "before" snapshot is taken before update mode populates that property.
   *
   * @return string
   *   A content hash of the managed file set, stable across no-op runs.
   */
  protected static function fingerprint() {
    $docRoot = static::$docRoot;
    if ($docRoot === NULL && is_file(static::$configPath)) {
      $docRoot = Yaml::parseFile(static::$configPath)['docroot'] ?? 'web';
    }
    $webRoot = __DIR__ . '/../../../../' . ($docRoot ? $docRoot . '/' : '');

    $paths = [
      static::$configPath,
      static::$gitIgnorePath,
      $webRoot . static::$settingsLocalPath,
      static::$ddevRoot . 'docker-compose.browsersync.yaml',
      static::$ddevRoot . 'docker-compose.selenium-chrome.yaml',
      static::$ddevRoot . 'config.selenium-standalone-chrome.yaml',
      static::$ddevRoot . 'docker-compose.solr.yaml',
      static::$ddevRoot . 'web-build/Dockerfile.ddev-terminus',
      static::$ddevRoot . 'commands/host/db',
      // Solr ships a directory of assets, hashed recursively below.
      static::$ddevRoot . 'solr',
      // Redis ships a compose file + a config dir (hashed recursively).
      static::$ddevRoot . 'docker-compose.redis.yaml',
      static::$ddevRoot . 'redis',
    ];

    $parts = [];
    foreach ($paths as $path) {
      $parts[] = $path . ':' . static::hashPath($path);
    }
    return md5(implode('|', $parts));
  }

  /**
   * Content hash of a file, or of an entire directory tree, or '' if absent.
   *
   * Directory entries are sorted before hashing so the result is independent
   * of filesystem iteration order.
   *
   * @param string $path
   *   A file or directory path.
   *
   * @return string
   *   A stable hash of the path's contents, or '' when it does not exist.
   */
  protected static function hashPath($path) {
    if (is_file($path)) {
      return md5_file($path);
    }
    if (!is_dir($path)) {
      return '';
    }
    $hashes = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      if ($file->isFile()) {
        $hashes[$file->getPathname()] = md5_file($file->getPathname());
      }
    }
    ksort($hashes);
    $parts = [];
    foreach ($hashes as $file => $hash) {
      $parts[] = $file . ':' . $hash;
    }
    return md5(implode('|', $parts));
  }

}
