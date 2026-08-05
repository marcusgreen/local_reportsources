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

namespace local_reportsources;

use local_reportsources\local\query;
use local_reportsources\local\repository;

/**
 * Tests for the remote shared-repository fetch/annotate/import path.
 *
 * The network-bound download is not exercised here; instead the MUC cache is primed with parsed
 * sources so the annotate ({@see repository::remote_sources()}) and import
 * ({@see repository::import_remote()}) logic is tested deterministically, offline. The private
 * URL→API translation is checked via reflection.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_reportsources\local\repository
 */
final class repository_test extends \advanced_testcase {
    /** @var string A repo URL used across tests. */
    private const URL = 'https://github.com/marcusgreen/moodle-reportsources_repository';

    /**
     * Prime the repositorysources cache for a URL with the given parsed (unannotated) sources.
     *
     * @param string $url Repository URL the sources are keyed under.
     * @param array $sources Parsed sources as {@see \local_reportsources\local\transfer::parse()} yields.
     */
    private function prime_cache(string $url, array $sources): void {
        \cache::make('local_reportsources', 'repositorysources')->set(sha1($url), $sources);
    }

    /**
     * Build a minimal parsed source descriptor.
     *
     * @param string $name Source name.
     * @param string $sql Backing SQL.
     * @return array
     */
    private function source(string $name, string $sql = 'SELECT id FROM {user}'): array {
        return [
            'name'        => $name,
            'description' => '',
            'querysql'    => $sql,
            'courseid'    => 0,
            'visible'     => 1,
            'chartmeta'   => null,
        ];
    }

    /**
     * With no repository configured, remote_sources returns an empty list (no network, no fatal).
     */
    public function test_unconfigured_returns_empty(): void {
        $this->resetAfterTest();
        set_config('sharedrepository', '', 'local_reportsources');

        $this->assertSame([], repository::remote_sources());
        $this->assertSame('', repository::configured_url());
    }

    /**
     * Primed sources come back annotated with a stable index and a duplicate flag.
     */
    public function test_remote_sources_annotates_index_and_duplicate(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('sharedrepository', self::URL, 'local_reportsources');

        // An existing query whose name collides with the second remote source.
        $DB->insert_record(query::TABLE, (object) [
            'name'         => 'Already here',
            'querysql'     => 'SELECT id FROM {user}',
            'ownerid'      => 2,
            'status'       => query::STATUS_DRAFT,
            'courseid'     => 0,
            'visible'      => 1,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);

        $this->prime_cache(self::URL, [
            $this->source('Fresh one'),
            $this->source('Already here'),
        ]);

        $sources = repository::remote_sources();

        $this->assertCount(2, $sources);
        $this->assertSame(0, $sources[0]['index']);
        $this->assertFalse($sources[0]['duplicate']);
        $this->assertSame(1, $sources[1]['index']);
        $this->assertTrue($sources[1]['duplicate'], 'Name collision must be flagged as duplicate.');
    }

    /**
     * import_remote delegates to transfer::import: chosen sources land as drafts owned by the user.
     */
    public function test_import_remote_creates_drafts(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sharedrepository', self::URL, 'local_reportsources');

        $this->prime_cache(self::URL, [
            $this->source('Repo report A'),
            $this->source('Repo report B'),
        ]);

        $result = repository::import_remote([0, 1]);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, $DB->count_records(query::TABLE));
        $rec = $DB->get_record(query::TABLE, ['name' => 'Repo report A']);
        $this->assertSame(query::STATUS_DRAFT, $rec->status);
        $this->assertEquals($USER->id, $rec->ownerid);
    }

    /**
     * The primed cache is served on a non-refresh read (no download, so no network in CI).
     */
    public function test_cache_is_served_without_download(): void {
        $this->resetAfterTest();
        set_config('sharedrepository', self::URL, 'local_reportsources');
        $this->prime_cache(self::URL, [$this->source('Cached')]);

        $sources = repository::remote_sources(false);
        $this->assertCount(1, $sources);
        $this->assertSame('Cached', $sources[0]['name']);
    }

    /**
     * contents_api_url maps a github.com repo URL to its contents API, and rejects other hosts.
     *
     * @dataProvider api_url_provider
     * @param string $input Repository URL.
     * @param string|null $expected Expected API URL, or null if it must be rejected.
     */
    public function test_contents_api_url(string $input, ?string $expected): void {
        $method = new \ReflectionMethod(repository::class, 'contents_api_url');
        $method->setAccessible(true);
        $this->assertSame($expected, $method->invoke(null, $input));
    }

    /**
     * @return array<string, array{0:string,1:?string}>
     */
    public static function api_url_provider(): array {
        return [
            'plain repo' => [
                'https://github.com/marcusgreen/moodle-reportsources_repository',
                'https://api.github.com/repos/marcusgreen/moodle-reportsources_repository/contents/',
            ],
            'trailing slash' => [
                'https://github.com/owner/repo/',
                'https://api.github.com/repos/owner/repo/contents/',
            ],
            '.git suffix' => [
                'https://github.com/owner/repo.git',
                'https://api.github.com/repos/owner/repo/contents/',
            ],
            'non-github host rejected' => [
                'https://gitlab.com/owner/repo',
                null,
            ],
            'missing repo rejected' => [
                'https://github.com/owner',
                null,
            ],
        ];
    }
}
