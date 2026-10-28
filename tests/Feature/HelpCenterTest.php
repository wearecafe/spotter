<?php

namespace Tests\Feature;

use App\Help\HelpCenter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * The public help center at /help.
 */
class HelpCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_help_center_is_public(): void
    {
        $response = $this->get('/help')->assertOk()->assertSee('How can we help?');

        foreach (HelpCenter::CATEGORIES as $category) {
            $response->assertSee($category['title']);
        }
    }

    public function test_every_category_and_article_renders(): void
    {
        $help = new HelpCenter;

        foreach (array_keys(HelpCenter::CATEGORIES) as $category) {
            $this->assertNotEmpty($help->inCategory($category), "The {$category} category has no articles.");
            $this->get("/help/{$category}")->assertOk();
        }

        foreach ($help->all() as $article) {
            $this->get($article->url())->assertOk()->assertSee($article->title);
        }
    }

    public function test_every_help_link_and_related_article_exists(): void
    {
        $help = new HelpCenter;
        $paths = array_keys($help->all());

        foreach ($help->all() as $article) {
            preg_match_all('#href="/help/([^"\#]*)(?:\#([^"]*))?"#', $article->html, $links, PREG_SET_ORDER);

            foreach ($links as $link) {
                $target = rtrim($link[1], '/');
                $this->assertTrue(
                    in_array($target, $paths, true) || isset(HelpCenter::CATEGORIES[$target]),
                    "{$article->path()} links to /help/{$link[1]}, which doesn't exist.",
                );
            }

            foreach ($article->related as $related) {
                $this->assertContains($related, $paths, "{$article->path()} lists {$related} as related, which doesn't exist.");
            }

            $this->assertNotSame('', $article->summary, "{$article->path()} has no summary.");
        }
    }

    public function test_links_from_the_app_point_at_real_articles(): void
    {
        $help = new HelpCenter;

        foreach (['in-the-gym/running-a-session', 'switching/importing-programs', 'programming/progression-rules'] as $path) {
            $this->assertArrayHasKey($path, $help->all(), "The app links to /help/{$path}.");
        }
        foreach (['for-clients', 'account'] as $category) {
            $this->assertNotEmpty($help->inCategory($category));
        }
    }

    public function test_unknown_pages_are_not_found(): void
    {
        $this->get('/help/nope')->assertNotFound();
        $this->get('/help/programming/nope')->assertNotFound();
        $this->get('/help/nope/progression-rules')->assertNotFound();
    }

    public function test_search_finds_articles_by_title_first(): void
    {
        $this->get('/help/search?q=progression+rules')->assertOk()->assertSee('results for')->assertSee('/help/programming/progression-rules', false);
        $this->assertSame('progression-rules', $this->top('progression rules'));

        $this->get('/help/search?q=zzzqqq')->assertOk()->assertSee('No results for');
    }

    public function test_search_understands_how_coaches_ask(): void
    {
        $this->assertSame('progression-rules', $this->top('progresion'));
        $this->assertSame('group-sessions', $this->top('semi private'));
        $this->assertSame('moving-to-spotter', $this->top('switch from trainerize'));
        $this->assertSame('two-factor', $this->top('2fa'));
        $this->assertSame('what-clients-can-import', $this->top('myfitnesspal'));
        $this->assertSame('in-person-clients', $this->top('client without the app'));
        $this->assertSame('signing-in', $this->top('forgot password'));
    }

    public function test_suggestions_as_you_type(): void
    {
        $this->getJson('/help/suggest?q=progr')
            ->assertOk()
            ->assertJsonPath('query', 'progr')
            ->assertJsonStructure(['results' => [['category', 'slug', 'title', 'url', 'categoryTitle', 'titleParts', 'snippetParts', 'section', 'complete']]]);

        $this->assertLessThanOrEqual(6, count($this->getJson('/help/suggest?q=client')->json('results')));
        $this->getJson('/help/suggest?q=')->assertOk()->assertJsonPath('results', []);
    }

    public function test_articles_get_heading_anchors_and_callouts(): void
    {
        $dir = storage_path('framework/testing/help-'.uniqid());
        File::ensureDirectoryExists("{$dir}/programming");
        File::put("{$dir}/programming/sample.md", <<<'MD'
            ---
            title: Sample
            summary: A sample.
            ---

            ## First step

            > **Tip** Do it this way.

            ## First step

            <script>alert(1)</script>
            MD);

        try {
            $article = (new HelpCenter($dir))->find('programming', 'sample');

            $this->assertNotNull($article);
            $this->assertStringContainsString('<h2 id="first-step">', $article->html);
            $this->assertStringContainsString('<h2 id="first-step-2">', $article->html);
            $this->assertStringContainsString('class="callout callout-tip"', $article->html);
            $this->assertStringNotContainsString('<script>', $article->html);
            $this->assertCount(2, $article->toc);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_an_article_without_a_title_is_an_error(): void
    {
        $dir = storage_path('framework/testing/help-'.uniqid());
        File::ensureDirectoryExists("{$dir}/programming");
        File::put("{$dir}/programming/untitled.md", "---\nsummary: No title.\n---\n\nBody.\n");

        try {
            $this->expectException(RuntimeException::class);
            (new HelpCenter($dir))->all();
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_support_email_shows_when_set(): void
    {
        config(['services.support.email' => 'help@spotter.test']);
        $this->get('/help')->assertSee('mailto:help@spotter.test', false);

        config(['services.support.email' => null]);
        $this->get('/help')->assertDontSee('Email support');
    }

    public function test_the_app_links_to_help(): void
    {
        $coach = User::factory()->create();
        $client = User::factory()->clientOf($coach)->create();

        $this->actingAs($coach)->get(route('coach.dashboard'))->assertSee(route('help.index'));
        $this->actingAs($client)->get(route('training.index'))->assertSee(route('help.category', 'for-clients'));
        $this->get(route('home'))->assertSee(route('help.index'));
    }

    private function top(string $query): ?string
    {
        return $this->getJson('/help/suggest?q='.urlencode($query))->json('results.0.slug');
    }
}
