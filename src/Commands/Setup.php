<?php

declare(strict_types=1);

namespace TotpMfa\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Publisher\Publisher;
use CodeIgniter\Shield\Models\UserIdentityModel;
use Throwable;

/**
 * php spark totp-mfa:setup
 *
 * Publishes this package's Config and Language files into the host
 * application - the same technique (CodeIgniter's Publisher class)
 * Shield's own `shield:setup` command uses.
 *
 * Deliberately does NOT try to auto-edit app/Config/Auth.php or
 * app/Config/Routes.php. Both are short, security-relevant edits where
 * a blind text search-and-replace could silently do nothing (if the
 * file's already been customized) or, worse, leave the file in a
 * broken state. Printing the exact lines to paste in is slower but
 * never wrong.
 *
 * Deliberately does NOT publish (copy) the migration into the host
 * app either, unlike an earlier version of this command. CodeIgniter's
 * migration locator already auto-discovers migrations directly from
 * every registered namespace - exactly how Shield's own migrations get
 * found, with no copying involved. Copying this package's migration on
 * top of that created two migrations that both try to create the same
 * table: harmless until anything runs migrations across all namespaces
 * at once (`--all`, `-n TotpMfa`, or this package's own test suite via
 * `$namespace = null`), at which point the second one fails with
 * "table already exists" - and, on top of that, an earlier version of
 * this command's "give the copy a fresh timestamp" step didn't account
 * for a stale copy from a previous run still being present either,
 * which could cause a *class* collision instead. Not publishing it at
 * all sidesteps both problems.
 */
class Setup extends BaseCommand
{
    protected $group       = 'TotpMfa';
    protected $name        = 'totp-mfa:setup';
    protected $description = 'Publishes TOTP MFA config and language file into your app.';
    protected $usage       = 'totp-mfa:setup [--force]';
    protected $options     = [
        '--force' => 'Overwrite files that were already published by a previous run.',
    ];

    public function run(array $params)
    {
        if (! class_exists(UserIdentityModel::class)) {
            CLI::error('CodeIgniter Shield does not appear to be installed.');
            CLI::write('Install it first: composer require codeigniter4/shield');

            return;
        }

        $namespaces = service('autoloader')->getNamespace('TotpMfa');

        if ($namespaces === []) {
            CLI::error('Could not resolve the "TotpMfa" namespace.');
            CLI::write('Make sure it is registered in composer.json or app/Config/Autoload.php.');

            return;
        }

        $force  = (bool) CLI::getOption('force');
        $source = rtrim($namespaces[0], '/\\');

        $publisher = new Publisher($source, APPPATH);

        try {
            $publisher->addPaths(['Config', 'Language'])->merge($force);
        } catch (Throwable $e) {
            CLI::error('Publishing failed: ' . $e->getMessage());
            $this->printPublisherErrors($publisher);

            return;
        }

        $published = $publisher->getPublished();

        if ($published === []) {
            CLI::write('Nothing to publish - files already exist. Re-run with --force to overwrite.', 'yellow');
        } else {
            foreach ($published as $file) {
                CLI::write('  Published: ' . str_replace(APPPATH, 'app/', $file), 'green');
            }
        }

        $this->printPublisherErrors($publisher);
        $this->warnAboutStaleCopy();
        $this->printRemainingSteps();
    }

    private function printPublisherErrors(Publisher $publisher): void
    {
        foreach ($publisher->getErrors() as $file => $error) {
            CLI::error('  ' . $file . ': ' . $error->getMessage());
        }
    }

    /**
     * If an earlier version of this command (or a manual copy) left a
     * migration file behind in the host app, flag it clearly - it's
     * not just unnecessary now, it actively conflicts with the
     * auto-discovered copy the moment both get migrated together.
     */
    private function warnAboutStaleCopy(): void
    {
        $matches = glob(APPPATH . 'Database/Migrations/*_CreateAuthRememberedDevices.php') ?: [];

        if ($matches === []) {
            return;
        }

        CLI::newLine();
        CLI::write('Found a copy of the migration already in your app:', 'yellow');

        foreach ($matches as $file) {
            CLI::write('  ' . str_replace(APPPATH, 'app/', $file), 'yellow');
        }

        CLI::write(
            'This is no longer needed (the migration is auto-discovered directly ' .
            'from this package) and will conflict with it - "table already ' .
            'exists" - the moment anything migrates across all namespaces at ' .
            'once. Safe to delete: it won\'t affect a table already created by it.'
        );
    }

    private function printRemainingSteps(): void
    {
        CLI::newLine();
        CLI::write('A few manual steps left:', 'yellow');

        CLI::newLine();
        CLI::write('1) Run the migration - auto-discovered from this package, nothing to');
        CLI::write('   copy first (same as Shield\'s own migrations):');
        CLI::write('   php spark migrate --all');
        CLI::write('   (or: php spark migrate -n TotpMfa)');

        CLI::newLine();
        CLI::write('2) Make sure an encryption key is set (TOTP secrets are encrypted at rest):');
        CLI::write('   php spark key:generate');

        CLI::newLine();
        CLI::write('3) Register the action(s) in app/Config/Auth.php. Login verification');
        CLI::write('   is required; registration-time setup (TotpActivator) is optional:');
        CLI::write('   public array $actions = [');
        CLI::write("       'register' => \\TotpMfa\\Authentication\\Actions\\TotpActivator::class, // optional");
        CLI::write("       'login'    => \\TotpMfa\\Authentication\\Actions\\TotpMfa::class,");
        CLI::write('   ];');
        CLI::write('   If you don\'t want a TOTP step at signup, leave \'register\' => null instead.');

        CLI::newLine();
        CLI::write('4) Add whichever routes you need from routes-snippet.php to');
        CLI::write('   app/Config/Routes.php - it covers several optional pieces (the');
        CLI::write('   TotpActivator skip link, remembered-devices management, the');
        CLI::write('   standalone settings page, and the step-up challenge), so only add');
        CLI::write('   what you\'re actually using. See the README\'s Installation section.');

        CLI::newLine();
        CLI::write('5) Set your app name as the issuer, either in app/Config/TotpMfa.php');
        CLI::write('   or via totpMfa.issuer in .env - this is what shows up inside the');
        CLI::write('   user\'s authenticator app.');
    }
}
