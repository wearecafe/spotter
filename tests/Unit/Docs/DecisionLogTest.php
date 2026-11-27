<?php

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Each decision file in docs/claude/decisions/ carries the header lines the
 * decision log's README describes, so proposed ones can be found and none
 * goes without a status.
 */
class DecisionLogTest extends TestCase
{
    private const NOT_DECISIONS = ['README.md', 'TEMPLATE.md', 'pending.md'];

    private const STATUSES = ['proposed', 'confirmed', 'reversed', 'superseded'];

    /**
     * @return array<string, string>
     */
    private function decisions(): array
    {
        $files = [];

        foreach (glob(dirname(__DIR__, 3).'/docs/claude/decisions/*.md') ?: [] as $file) {
            if (! in_array(basename($file), self::NOT_DECISIONS, true)) {
                $files[basename($file)] = (string) file_get_contents($file);
            }
        }

        return $files;
    }

    public function test_decision_files_are_named_by_date_and_slug()
    {
        $this->assertNotEmpty($this->decisions());

        foreach (array_keys($this->decisions()) as $name) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}-[a-z0-9-]+\.md$/', $name, "{$name} should be named YYYY-MM-DD-short-slug.md.");
        }
    }

    public function test_decision_files_have_a_title_status_date_area_and_decider()
    {
        foreach ($this->decisions() as $name => $text) {
            $this->assertMatchesRegularExpression('/\A# \S/', $text, "{$name} should start with a # title.");
            $this->assertMatchesRegularExpression('/^Status: ('.implode('|', self::STATUSES).')$/m', $text, "{$name} needs a Status line: ".implode(', ', self::STATUSES).'.');
            $this->assertMatchesRegularExpression('/^Date: \d{4}-\d{2}-\d{2}$/m', $text, "{$name} needs a Date line (YYYY-MM-DD).");
            $this->assertMatchesRegularExpression('/^Area: \S/m', $text, "{$name} needs an Area line.");
            $this->assertMatchesRegularExpression('/^Decided by: \S/m', $text, "{$name} needs a Decided by line.");
            $this->assertStringContainsString('**Decision:**', $text, "{$name} needs a **Decision:** section.");
        }
    }
}
