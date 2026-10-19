<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Update;

use Quissly\PrestaShop\Logger;
use Quissly\PrestaShop\Platform\Http;
use Quissly\PrestaShop\Platform\Registry;
use Quissly\PrestaShop\Sync\DbSyncStore;

/**
 * Automatic updates from the module's public GitHub repository.
 *
 * Every "Update check frequency" (Configuration > Updates: 5 minutes for testing, 30 minutes by
 * default, up to a day, or Never) - from the module's cron, or at the end of an admin page when
 * the cron is not set up - the module reads the version in the repository's quissly.php on
 * GitHub's CDN (raw.githubusercontent.com, no API rate limit), and asks GitHub's API for the
 * latest release when that names a newer version than the one installed and known - and at
 * least once a day. When the API refuses the server (60 requests an hour per IP address, shared
 * by every site on a shared host), the release is found at the address the release workflow
 * publishes it to, from the CDN's version. A newer release is installed straight away:
 *
 *  1. the release zip is downloaded and checked: signed with Quissly's release key
 *     (Release::PUBLIC_KEY - an unsigned or wrongly signed zip is never installed, even if
 *     someone could publish to the repository), only the module's own folder, its quissly.php
 *     naming the release's version;
 *  2. modules/quissly is moved to a backup and the new folder put in its place - a failure
 *     moves the old one back;
 *  3. on the next request, with the new code loaded (this one still runs the old), the module
 *     does what PrestaShop's Module Manager "Upgrade" does (Quissly::completeUpdate): its
 *     upgrade/ scripts, then the new version's hooks, tables, settings and menu, and its
 *     recorded version.
 *
 * Settings, credentials and the sync tables are never touched. A version that failed to install
 * is not tried again; the next release is. A shop or module folder that is a git checkout is
 * never written to: whoever deploys it updates it. Every update, and why one is not installing,
 * goes to the sync log (the same problem once a day).
 */
final class Updater
{
    public const REPOSITORY = 'quissly/quissly-for-prestashop';
    public const ASSET = 'quissly.zip';
    public const STATE = 'update';
    public const PROBLEM_STATE = 'update_problem';
    public const CHECK_EVERY = 86400;
    public const RETRY_AFTER = 3600;

    /** The repository's quissly.php on GitHub's CDN: its version is the latest one pushed. */
    public const HINT_URL = 'https://raw.githubusercontent.com/' . self::REPOSITORY . '/main/quissly.php';

    /** Why the last GitHub request failed ('' = it did not). */
    private static $githubError = '';

    /** Why the last GitHub release was not offered (Release::parseProblem), '' when it was. */
    private static $releaseProblem = '';

    /** Check (and install) when the merchant's frequency is up. */
    public static function runIfDue(DbSyncStore $store, int $now): void
    {
        $every = Release::interval(Registry::get('addons.quissly_search.update_check_every'));
        if ($every === Release::NEVER) {
            return;
        }
        $state = $store->getState(self::STATE) ?? [];
        // A frequency changed since the last check applies at once.
        $changed = (int) ($state['every'] ?? 0) !== $every;
        if (!$changed && (int) ($state['next_at'] ?? 0) > $now) {
            return;
        }
        // Claimed before the network: a second request in the meantime finds it not due.
        $state['every'] = $every;
        $state['next_at'] = $now + min(self::RETRY_AFTER, $every);
        $store->setState(self::STATE, $state);

        // The files' own version: what is really running (PrestaShop's recorded one lags until
        // the upgrade step has run).
        $installed = Release::versionIn((string) @file_get_contents(self::moduleDir() . 'quissly.php')) ?? '0.0.0';

        // The cheap check first: GitHub's CDN copy of quissly.php. Only news (or a day since the
        // last full check) asks GitHub's API for the release.
        $hinted = null;
        if ($now - (int) ($state['checked_at'] ?? 0) < self::CHECK_EVERY) {
            $hinted = self::hintedVersion();
            if ($hinted === null) {
                self::problem($store, sprintf('Quissly could not check GitHub for a new version (%s).', self::$githubError), $now);
            }
            if ($hinted === null || !Release::isNews($hinted, $installed, (string) ($state['latest'] ?? ''))) {
                $state['next_at'] = $now + $every;
                $state['hinted_at'] = $now;
                $store->setState(self::STATE, $state);

                return;
            }
        }

        $release = self::latest($hinted);
        if ($release === null) {
            if (self::$githubError !== '') {
                self::problem($store, sprintf('Quissly could not get the latest release from GitHub (%s).', self::$githubError), $now);
            } elseif (self::$releaseProblem !== '') {
                self::problem($store, sprintf('Quissly did not install the latest release on GitHub: %s.', self::$releaseProblem), $now);
            }

            return; // tried again soon
        }
        $state['next_at'] = $now + $every;
        $state['checked_at'] = $now;
        $state['latest'] = $release['version'];
        if (self::isCheckout()) {
            $state['skipped'] = 'git_checkout';
        } elseif (version_compare($release['version'], $installed, '>') && ($state['failed'] ?? '') !== $release['version']) {
            $blocker = self::blocker();
            if ($blocker !== '') {
                self::problem($store, sprintf('Quissly %s is out, but it cannot be installed by itself: %s. Upload it in Modules > Module Manager.', $release['version'], $blocker), $now);
            } else {
                $error = self::install($release);
                $state['last'] = ['from' => $installed, 'to' => $release['version'], 'at' => $now, 'error' => $error];
                if ($error === null) {
                    unset($state['failed']);
                    $state['pending'] = $release['version'];
                    Logger::notice('Quissly update installed', ['from' => $installed, 'to' => $release['version']]);
                    $store->log(sprintf('Quissly updated from %s to %s.', $installed, $release['version']), $now);
                } else {
                    $state['failed'] = $release['version'];
                    Logger::error('Quissly update failed', ['version' => $release['version'], 'reason' => $error]);
                    $store->log(sprintf('Quissly %s could not be installed (%s); %s is still running.', $release['version'], $error, $installed), $now);
                }
            }
        }
        $store->setState(self::STATE, $state);
    }

    /**
     * After the files of a new version are in place, with that version's code loaded (the
     * module's own upgrade step, Quissly::completeUpdate, says how it went).
     */
    public static function completed(DbSyncStore $store, string $from, string $to, bool $ok, int $now): void
    {
        $state = $store->getState(self::STATE) ?? [];
        unset($state['pending']);
        $store->setState(self::STATE, $state);
        if (!$ok) {
            Logger::error('Quissly upgrade step failed', ['from' => $from, 'to' => $to]);
            $store->log(sprintf('Quissly %s is in place, but its upgrade step failed (from %s); PrestaShop may have switched the module off - see Modules > Module Manager.', $to, $from), $now);
        }
    }

    /**
     * What stops the module installing an update by itself, '' when nothing does: PHP without
     * zip or sodium, or a modules/ folder (or the module's own) the web server cannot write.
     */
    public static function blocker(): string
    {
        if (!class_exists('ZipArchive')) {
            return "PHP's zip extension is missing";
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return "PHP's sodium extension is missing, so the release's signature cannot be checked";
        }
        $modules = rtrim((string) Registry::get('config.dir.addons'), '/');
        if (!is_writable($modules) || !is_writable(self::moduleDir())) {
            return 'the web server cannot write the modules folder';
        }

        return '';
    }

    /**
     * Whether the shop or the module's folder is a git checkout: its files are managed by
     * whoever deploys it (and a developer's copy may be the repository itself), so it is never
     * written to - as WordPress skips automatic updates on a git-managed site.
     */
    private static function isCheckout(): bool
    {
        return file_exists(self::root() . '.git') || file_exists(self::moduleDir() . '.git');
    }

    /**
     * The latest release from GitHub's API; when the API refuses the server, the release of the
     * CDN's version at its known addresses, once published (the workflow takes a minute or two).
     *
     * @return array{version: string, package: string, signature: string}|null
     */
    private static function latest(?string $hinted): ?array
    {
        self::$releaseProblem = '';
        $body = Http::get(self::releaseUrl(), [], [
            'headers'            => ['Accept: application/vnd.github+json'],
            'timeout'            => 10,
            'connection_timeout' => 10,
        ]);
        self::$githubError = Release::requestError($body !== false, Http::getStatus(), Http::getError());
        if (self::$githubError === '') {
            $json = json_decode((string) $body, true);
            self::$releaseProblem = Release::parseProblem($json, self::ASSET, self::packagePrefix());

            return Release::parse($json, self::ASSET, self::packagePrefix());
        }
        if ((string) Registry::get('config.quissly_update_url') !== '') {
            return null;
        }
        $apiError = self::$githubError;
        $hinted = $hinted ?? self::hintedVersion();
        self::$githubError = $apiError;
        if ($hinted === null) {
            return null;
        }
        $release = Release::forVersion($hinted, self::ASSET, self::packagePrefix());
        $signature = Http::get($release['signature'], [], ['timeout' => 15]);

        return $signature !== false && Http::getStatus() === 200 ? $release : null;
    }

    /**
     * Download, check and put the release in place. Null on success, else why not.
     *
     * @param array{version: string, package: string, signature: string} $release
     */
    private static function install(array $release): ?string
    {
        $work = rtrim((string) Registry::get('config.dir.var'), '/') . '/quissly_update/';
        if (!is_dir($work) && !@mkdir($work, 0755, true)) {
            return 'no_work_folder';
        }
        $lock = @fopen($work . 'lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 'busy';
        }
        // What runs after the swap is this (old) version's code: loaded now, so nothing of the
        // new version is mixed in from the folder that replaced it.
        class_exists(Logger::class);
        class_exists(DbSyncStore::class);
        try {
            self::remove($work . 'new');
            $zipPath = $work . 'package.zip';
            $zipBytes = Http::get($release['package'], [], ['timeout' => 120, 'connection_timeout' => 15]);
            if ($zipBytes === false || Http::getStatus() !== 200 || $zipBytes === '' || @file_put_contents($zipPath, $zipBytes) === false) {
                return 'download_failed';
            }
            $signature = Http::get($release['signature'], [], ['timeout' => 15]);
            if ($signature === false || Http::getStatus() !== 200) {
                return 'no_signature';
            }
            if (!Release::signatureValid($zipBytes, (string) $signature, Release::PUBLIC_KEY)) {
                return 'bad_signature';
            }

            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return 'bad_zip';
            }
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = (string) $zip->getNameIndex($i);
            }
            $problem = Release::problem($names);
            if ($problem !== null) {
                $zip->close();

                return 'bad_package:' . $problem;
            }
            $extracted = $zip->extractTo($work . 'new');
            $zip->close();
            @unlink($zipPath);
            if (!$extracted) {
                return 'extract_failed';
            }
            if (Release::versionIn((string) @file_get_contents($work . 'new/' . Release::MAIN)) !== $release['version']) {
                return 'version_mismatch';
            }

            $swap = self::swap($work . 'new/' . Release::FOLDER, $work . 'backup/' . Release::FOLDER);
            if ($swap !== null) {
                return $swap;
            }
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            return null;
        } finally {
            self::remove($work . 'new');
            @unlink($work . 'package.zip');
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** The module's folder to the backup, the new one in its place; on a failure, the old one back. */
    private static function swap(string $new, string $backup): ?string
    {
        $current = rtrim(self::moduleDir(), '/');
        self::remove($backup);
        if (!self::move($current, $backup)) {
            return 'swap_failed';
        }
        if (!self::move($new, $current)) {
            self::remove($current);
            self::move($backup, $current);

            return 'swap_failed';
        }

        return null;
    }

    /**
     * Move a folder: a rename, or - across filesystems (var/ can be its own mount, where
     * rename() fails) - a copy, then the original removed.
     */
    private static function move(string $from, string $to): bool
    {
        if (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0755, true)) {
            return false;
        }
        if (@rename($from, $to)) {
            return true;
        }
        self::remove($to); // whatever a failed rename left
        if (!self::copy($from, $to)) {
            self::remove($to);

            return false;
        }
        self::remove($from);

        return !file_exists($from);
    }

    private static function copy(string $from, string $to): bool
    {
        if (is_file($from)) {
            return @copy($from, $to);
        }
        if (!is_dir($to) && !@mkdir($to, 0755, true)) {
            return false;
        }
        foreach ((array) @scandir($from) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (!self::copy($from . '/' . $name, $to . '/' . $name)) {
                return false;
            }
        }

        return true;
    }

    /** Delete a file or folder (only ever under var/quissly_update/ or modules/quissly). */
    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach ((array) @scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                self::remove($path . '/' . $name);
            }
        }
        @rmdir($path);
    }

    /** Uninstall: the work folder holds the last version's backup. */
    public static function removeWorkFolder(): void
    {
        self::remove(rtrim((string) Registry::get('config.dir.var'), '/') . '/quissly_update');
    }

    /** Write an update problem to the sync log - the same one at most once a day. */
    private static function problem(DbSyncStore $store, string $message, int $now): void
    {
        $last = (array) ($store->getState(self::PROBLEM_STATE) ?? []);
        if (!Release::problemDue($last, $message, $now)) {
            return;
        }
        $store->log($message, $now);
        $store->setState(self::PROBLEM_STATE, ['message' => $message, 'at' => $now]);
    }

    private static function root(): string
    {
        return (string) Registry::get('config.dir.root');
    }

    private static function moduleDir(): string
    {
        return (string) Registry::get('config.dir.addons') . Release::FOLDER . '/';
    }

    /**
     * The version GitHub's CDN copy of the repository's quissly.php names; null when it cannot be
     * read. QUISSLY_UPDATE_HINT_URL (config/defines_custom.inc.php) replaces the address on a dev store.
     */
    private static function hintedVersion(): ?string
    {
        $override = (string) Registry::get('config.quissly_update_hint_url');
        $body = Http::get($override !== '' ? $override : self::HINT_URL, [], ['timeout' => 10, 'connection_timeout' => 5]);
        self::$githubError = Release::requestError($body !== false, Http::getStatus(), Http::getError());
        if (self::$githubError !== '') {
            return null;
        }
        $version = Release::versionIn((string) $body);
        if ($version === null) {
            self::$githubError = 'no version in its quissly.php';
        }

        return $version;
    }

    /** GitHub's "latest release" address; QUISSLY_UPDATE_URL replaces it on a dev store. */
    private static function releaseUrl(): string
    {
        $override = (string) Registry::get('config.quissly_update_url');

        return $override !== '' ? $override : 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest';
    }

    /** Where a package may come from: this repository's release downloads (or the dev override's host). */
    private static function packagePrefix(): string
    {
        $override = (string) Registry::get('config.quissly_update_url');
        if ($override !== '') {
            $parts = parse_url($override);

            return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/';
        }

        return 'https://github.com/' . self::REPOSITORY . '/releases/download/';
    }

    private function __construct()
    {
    }
}
