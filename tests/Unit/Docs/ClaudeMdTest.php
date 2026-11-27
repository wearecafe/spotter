<?php

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * CLAUDE.md is loaded into every session, so it stays short and points to
 * the module notes in docs/claude/modules/ rather than holding them.
 */
class ClaudeMdTest extends TestCase
{
    private const MAX_BYTES = 16384;

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function claudeMd(): string
    {
        return (string) file_get_contents($this->root().'/CLAUDE.md');
    }

    public function test_claude_md_stays_under_its_size_budget()
    {
        $size = strlen($this->claudeMd());

        $this->assertLessThanOrEqual(
            self::MAX_BYTES,
            $size,
            "CLAUDE.md is {$size} bytes (budget ".self::MAX_BYTES.'). Move module detail into docs/claude/modules/ and link it from the Module notes table.',
        );
    }

    public function test_every_docs_path_in_claude_md_exists()
    {
        preg_match_all('#docs/claude/[A-Za-z0-9_./-]+\.md#', $this->claudeMd(), $matches);

        $this->assertNotEmpty($matches[0]);

        foreach (array_unique($matches[0]) as $path) {
            $this->assertFileExists($this->root().'/'.$path, "CLAUDE.md links {$path}, which doesn't exist.");
        }
    }

    public function test_every_module_notes_file_is_linked_from_claude_md()
    {
        $claudeMd = $this->claudeMd();

        foreach (glob($this->root().'/docs/claude/modules/*.md') ?: [] as $file) {
            // The folder's own README explains module notes; it isn't one (as in scripts/docs_check.py).
            if (basename($file) === 'README.md') {
                continue;
            }

            $path = 'docs/claude/modules/'.basename($file);

            $this->assertStringContainsString($path, $claudeMd, "{$path} isn't in CLAUDE.md's Module notes table.");
        }
    }
}
