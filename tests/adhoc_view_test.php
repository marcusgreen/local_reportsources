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

use local_reportsources\reportbuilder\local\entities\adhoc_view;

/**
 * Tests for the ad-hoc view Report Builder entity, focused on date formatting.
 *
 * @package   local_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_reportsources\reportbuilder\local\entities\adhoc_view
 */
final class adhoc_view_test extends \advanced_testcase {
    /**
     * Invoke the private static strftime_format() mapper.
     *
     * @param string $neutral
     * @return string
     */
    private function map(string $neutral): string {
        $method = new \ReflectionMethod(adhoc_view::class, 'strftime_format');
        $method->setAccessible(true);
        return $method->invoke(null, $neutral);
    }

    /**
     * A neutral display format is translated to the strftime codes userdate() expects, with
     * longest-token precedence and separators passed through untouched.
     */
    public function test_strftime_format_translates_neutral_tokens(): void {
        $this->assertSame('%d/%m/%Y', $this->map('dd/mm/yyyy'));
        $this->assertSame('%a %d %b %Y', $this->map('ddd dd Mon yyyy'));
        $this->assertSame('%d-%b-%y', $this->map('dd-Mon-yy'));
        $this->assertSame('%B %Y', $this->map('Month yyyy'));
        $this->assertSame('%H:%M:%S', $this->map('hh:mi:ss'));
        // An empty format yields the dd-mmm-yyyy default.
        $this->assertSame('%d-%b-%Y', $this->map(''));
        $this->assertSame('%d-%b-%Y', $this->map('   '));
    }

    /**
     * The format is case-insensitive (DD/MM/YYYY behaves like dd/mm/yyyy).
     */
    public function test_strftime_format_is_case_insensitive(): void {
        $this->assertSame('%d/%m/%Y', $this->map('DD/MM/YYYY'));
    }
}
