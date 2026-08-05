<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace local_reportsources\local;

/**
 * Fetch shared report sources from a remote GitHub repository.
 *
 * A site admin configures {@see SETTING} to a GitHub repository URL (default: the plugin's own
 * demonstration repo). That repo holds one or more RS-format export JSON files — the exact shape
 * {@see transfer::parse()} reads. This class lists the repo's JSON files via the GitHub contents
 * API, downloads each, parses it, and aggregates the sources so the browse page
 * ({@see /local/reportsources/repository.php}) can present them for import.
 *
 * Import itself is delegated to {@see transfer::import()}, so every remote source is re-validated
 * against the local denylist and lands as a fresh draft owned by the importer — a hostile repo
 * cannot smuggle disallowed SQL past the local rules, and nothing is auto-published.
 *
 * Security: all HTTP is done through Moodle's {@see \curl} with the security helper enabled
 * (default), which blocks localhost / RFC1918 targets, and the host is additionally pinned to
 * GitHub domains — so a malicious setting cannot turn this into an SSRF probe. Results are MUC
 * cached to stay well under GitHub's unauthenticated rate limit.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository {
    /** Config key (in the local_reportsources plugin) holding the repository URL. */
    public const SETTING = 'sharedrepository';

    /** Companion on/off switch. Off by default — the whole feature is dark until an admin opts in. */
    public const SETTING_ENABLED = 'sharedrepositoryenabled';

    /** MUC cache area (see db/caches.php) for the parsed remote sources. */
    private const CACHE = 'repositorysources';

    /** Per-request HTTP timeout, seconds. Short — this is an interactive page load. */
    private const HTTP_TIMEOUT = 10;

    /** Hard cap on repo files examined, so a huge repo cannot stall the page. */
    private const MAX_FILES = 100;

    /** Hard cap on a single downloaded file, bytes. */
    private const MAX_BYTES = 1048576;

    /** Hosts we will fetch from. Defence in depth on top of the curl security helper. */
    private const ALLOWED_HOSTS = ['github.com', 'api.github.com', 'raw.githubusercontent.com'];

    /**
     * Whether the shared repository feature is switched on.
     *
     * The companion checkbox ({@see SETTING_ENABLED}) gates the whole feature and defaults off, so a
     * fresh install never reaches out to the remote repo until an admin opts in.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (bool) get_config('local_reportsources', self::SETTING_ENABLED);
    }

    /**
     * The configured repository URL, trimmed. Empty string when unset or the feature is disabled.
     *
     * Returning '' while disabled makes the whole feature degrade cleanly: no HTTP is attempted and
     * the browse page falls through to its "unconfigured" notice.
     *
     * @return string
     */
    public static function configured_url(): string {
        if (!self::enabled()) {
            return '';
        }
        return trim((string) get_config('local_reportsources', self::SETTING));
    }

    /**
     * The remote report sources, parsed and annotated for browsing.
     *
     * Mirrors {@see transfer::bundled_samples()}: returns an empty array on any failure (setting
     * unset, repo unreachable, rate-limited, malformed) so the browse page degrades to a notice
     * instead of fataling. Each returned source carries its 0-based `index` (stable import
     * selector) and a `duplicate` flag — true when a query of the same name already exists here.
     *
     * The parsed source list is MUC cached per URL; the `duplicate` flag is recomputed on every
     * call (it depends on live DB state, not the remote file).
     *
     * @param bool $refresh Bypass and rebuild the cache (the browse page's Refresh action).
     * @return array<int, array<string, mixed>> Parsed sources, each with added `index`/`duplicate`.
     */
    public static function remote_sources(bool $refresh = false): array {
        global $DB;

        $sources = self::fetch_sources($refresh);

        foreach ($sources as $index => &$source) {
            $name = (string) ($source['name'] ?? '');
            $source['index'] = $index;
            $source['duplicate'] = $name !== '' && $DB->record_exists(query::TABLE, ['name' => $name]);
        }
        unset($source);

        return $sources;
    }

    /**
     * Import the chosen remote sources as fresh drafts owned by the current user.
     *
     * Thin wrapper over {@see transfer::import()} — the selected indexes are positions in the list
     * returned by {@see remote_sources()}. All validation, courseid demotion and draft creation
     * happen there.
     *
     * @param int[] $selected Indexes into {@see remote_sources()} to import.
     * @return array{imported:int,skipped:array<string,string>,demoted:array<string,int>}
     */
    public static function import_remote(array $selected): array {
        return transfer::import(self::remote_sources(), $selected);
    }

    /**
     * Fetch and parse every RS-format JSON file in the configured repo (cached, unannotated).
     *
     * @param bool $refresh Rebuild the cache.
     * @return array<int, array<string, mixed>> Parsed sources across all files (may be empty).
     */
    private static function fetch_sources(bool $refresh): array {
        $url = self::configured_url();
        if ($url === '') {
            return [];
        }

        $cache = \cache::make('local_reportsources', self::CACHE);
        $key = sha1($url);
        if (!$refresh) {
            $cached = $cache->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $sources = self::download_sources($url);
        $cache->set($key, $sources);
        return $sources;
    }

    /**
     * List the repo's JSON files via the GitHub contents API and parse each into sources.
     *
     * Never throws: a network error, non-GitHub URL, unparseable listing or unparseable file all
     * yield an empty (or partial) list. This is advisory browse data, not a gate.
     *
     * @param string $url The configured repository URL.
     * @return array<int, array<string, mixed>>
     */
    private static function download_sources(string $url): array {
        $apiurl = self::contents_api_url($url);
        if ($apiurl === null) {
            return [];
        }

        $listing = self::http_get($apiurl);
        if ($listing === null) {
            return [];
        }
        $entries = json_decode($listing, true);
        if (!is_array($entries)) {
            return [];
        }

        $sources = [];
        $files = 0;
        foreach ($entries as $entry) {
            if (!is_array($entry) || ($entry['type'] ?? '') !== 'file') {
                continue;
            }
            $name = (string) ($entry['name'] ?? '');
            if (substr(strtolower($name), -5) !== '.json') {
                continue;
            }
            if (++$files > self::MAX_FILES) {
                break;
            }
            $downloadurl = (string) ($entry['download_url'] ?? '');
            if ($downloadurl === '') {
                continue;
            }
            $body = self::http_get($downloadurl);
            if ($body === null) {
                continue;
            }
            try {
                foreach (transfer::parse($body) as $source) {
                    $sources[] = $source;
                }
            } catch (\moodle_exception $e) {
                // Not an RS export — skip this file, keep the rest.
                continue;
            }
        }

        return $sources;
    }

    /**
     * Translate a GitHub repository URL into its contents-API endpoint.
     *
     * `https://github.com/owner/repo` → `https://api.github.com/repos/owner/repo/contents/`.
     * Returns null for anything that is not a github.com repo URL.
     *
     * @param string $url
     * @return string|null
     */
    private static function contents_api_url(string $url): ?string {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== 'github.com') {
            return null;
        }
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $parts = explode('/', $path);
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        $owner = $parts[0];
        $repo = preg_replace('/\.git$/', '', $parts[1]);
        return 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents/';
    }

    /**
     * SSRF-safe HTTP GET of a GitHub URL. Returns the body, or null on any error.
     *
     * The host is pinned to {@see ALLOWED_HOSTS}, and the fetch runs through Moodle's {@see \curl}
     * with the security helper enabled — so internal hosts are blocked even if a redirect or a
     * future setting change points elsewhere. Non-200, oversized and errored responses return null.
     *
     * @param string $url
     * @return string|null
     */
    private static function http_get(string $url): ?string {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            return null;
        }

        // ignoresecurity defaults to false, so \curl applies the security helper (blocks
        // localhost / private ranges). GitHub's API rejects requests without a User-Agent.
        $curl = new \curl();
        $curl->setHeader('User-Agent: Moodle-local_reportsources');
        $curl->setHeader('Accept: application/vnd.github+json');
        $body = $curl->get($url, [], [
            'CURLOPT_TIMEOUT'        => self::HTTP_TIMEOUT,
            'CURLOPT_CONNECTTIMEOUT' => self::HTTP_TIMEOUT,
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_MAXREDIRS'      => 0,
        ]);

        if ($curl->get_errno() || (int) ($curl->info['http_code'] ?? 0) !== 200) {
            return null;
        }
        if (!is_string($body) || strlen($body) > self::MAX_BYTES) {
            return null;
        }
        return $body;
    }
}
