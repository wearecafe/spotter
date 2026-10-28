<?php

namespace Tests\Unit;

use App\Help\HelpArticle;
use App\Help\HelpSearchIndex;
use PHPUnit\Framework\TestCase;

/**
 * Help search: stems, typos, prefixes as you type, coaching synonyms, and
 * pointing at the section that matched.
 */
class HelpSearchIndexTest extends TestCase
{
    private function index(): HelpSearchIndex
    {
        return new HelpSearchIndex([
            $this->article('programming', 'progression-rules', 'Progression rules', 'How the load steps up each week.', <<<'HTML'
                <p>Rules plan next week's load from what the client logged.</p>
                <h2 id="deloads">Deloads</h2><p>A lighter week you set by hand resumes at the planned load.</p>
                HTML),
            $this->article('inbox-messages', 'messages', 'Messaging clients', 'Chat and photos.', <<<'HTML'
                <h2 id="sending">Sending</h2><p>Add up to 4 photos at a time.</p>
                <h2 id="email-reminders">Email reminders</h2><p>Unread messages are emailed after 10 minutes.</p>
                HTML),
            $this->article('teams', 'roles', 'Owners, admins and coaches', 'Who sees what.', <<<'HTML'
                <p>Admins manage every client.</p>
                <table><tr><td>Owner</td><td>Reassigns</td></tr></table>
                HTML),
        ], ['programming' => 'Programs and workouts', 'inbox-messages' => 'Inbox and messages', 'teams' => 'Gyms and teams']);
    }

    private function article(string $category, string $slug, string $title, string $summary, string $html): HelpArticle
    {
        return new HelpArticle($category, $slug, $title, $summary, 1, false, $html, strip_tags($html), [], []);
    }

    /**
     * @return list<string>
     */
    private function slugs(string $query): array
    {
        return array_map(fn (array $row) => $row['article']->slug, $this->index()->search($query));
    }

    public function test_plurals_and_endings_match(): void
    {
        $this->assertSame('progression-rules', $this->slugs('rule')[0]);
        $this->assertSame(HelpSearchIndex::stem('invited'), HelpSearchIndex::stem('invites'));
        $this->assertSame('log', HelpSearchIndex::stem('logging'));
        $this->assertSame('photo', HelpSearchIndex::stem('photos'));
    }

    public function test_typos_still_find_the_article(): void
    {
        $this->assertSame('progression-rules', $this->slugs('progresion ruels')[0]);
    }

    public function test_the_last_word_matches_as_you_type(): void
    {
        $this->assertSame('progression-rules', $this->slugs('progr')[0]);
    }

    public function test_coaching_synonyms_match(): void
    {
        $this->assertSame('messages', $this->slugs('chat')[0]);
        $this->assertSame('progression-rules', $this->slugs('progressive overload')[0]);
        $this->assertSame('roles', $this->slugs('permission')[0]);
    }

    public function test_a_rare_synonym_does_not_outrank_the_word_typed(): void
    {
        $index = new HelpSearchIndex([
            $this->article('teams', 'adding-coaches', 'Adding coaches to your team', 'Add a coach.', '<p>Every coach shares the library. A coach sees their clients.</p>'),
            $this->article('for-clients', 'trainer-sessions', 'Sessions your trainer logs', 'Your coach logs it.', '<p>Your coach can add notes.</p>'),
            $this->article('inbox-messages', 'inbox', 'The inbox', 'For each coach.', '<p>Each coach has one.</p>'),
        ]);

        $this->assertSame('adding-coaches', $index->search('add a coach')[0]['article']->slug);
    }

    public function test_results_point_at_the_section_and_mark_the_words(): void
    {
        $result = $this->index()->search('unread email')[0];

        $this->assertSame('messages', $result['article']->slug);
        $this->assertSame(['id' => 'email-reminders', 'title' => 'Email reminders'], $result['section']);
        $this->assertTrue($result['complete']);
        $this->assertContains(['text' => 'Unread', 'hit' => true], $result['snippetParts']);
    }

    public function test_articles_with_every_word_come_before_close_matches(): void
    {
        $results = $this->index()->search('deload photos');

        $this->assertSame([], array_values(array_filter($results, fn (array $r) => $r['complete'])));
        $this->assertCount(2, $results);
    }

    public function test_table_cells_are_separate_words(): void
    {
        $this->assertSame(['roles'], $this->slugs('reassigns'));
    }

    public function test_nothing_found(): void
    {
        $this->assertSame([], $this->slugs('zzzqqq'));
        $this->assertSame([], $this->slugs('   '));
    }
}
