<?php

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * UAT rows carry feature IDs (<CODE>-<n>, see docs/claude/demo-and-uat.md →
 * UAT row IDs): never a bare number, and never the same ID twice.
 */
class UatIdsTest extends TestCase
{
    /**
     * Every UAT row's first cell, with the doc it's in. A UAT table is one
     * whose header starts with "#", "ID" or "Row" and has a Result or Check
     * column. Rows that only point to another doc ("HM-18 to HM-22",
     * "(P-2)") are left out.
     *
     * @return list<array{string, string}>
     */
    private function rows(): array
    {
        $rows = [];

        foreach (glob(dirname(__DIR__, 3).'/docs/claude/*.md') ?: [] as $file) {
            $lines = explode("\n", (string) file_get_contents($file));
            $header = null;

            foreach ($lines as $i => $line) {
                if (! str_starts_with($line, '|')) {
                    $header = null;

                    continue;
                }

                if ($header === null) {
                    $header = $line;

                    continue;
                }

                if (preg_match('/^\|\s*:?-/', $line)) {
                    continue;
                }

                $isUat = preg_match('/^\|\s*(#|ID|Row)\s*\|/', $header)
                    && (str_contains($header, 'Result') || stripos($header, 'check') !== false);

                if (! $isUat) {
                    continue;
                }

                $cell = trim(explode('|', $line)[1] ?? '');

                if (str_starts_with($cell, '(') || str_contains($cell, ' to ')) {
                    continue;
                }

                $rows[] = [$cell, basename($file)];
            }
        }

        return $rows;
    }

    public function test_uat_rows_use_feature_ids()
    {
        $rows = $this->rows();

        $this->assertNotEmpty($rows);

        foreach ($rows as [$cell, $doc]) {
            $this->assertMatchesRegularExpression(
                '/^[A-Z]{1,3}-[A-Z0-9]+[a-z]?( \(was (\d+|[A-Z]{1,3}-\d+)\))?$/',
                $cell,
                "UAT row \"{$cell}\" in {$doc} needs a feature ID like SU-12 (codes in demo-and-uat.md → UAT row IDs).",
            );
        }
    }

    public function test_uat_ids_are_unique()
    {
        $seen = [];

        foreach ($this->rows() as [$cell, $doc]) {
            $id = explode(' ', $cell)[0];

            $this->assertArrayNotHasKey($id, $seen, "UAT {$id} is in both {$doc} and ".($seen[$id] ?? '').'.');

            $seen[$id] = $doc;
        }
    }
}
