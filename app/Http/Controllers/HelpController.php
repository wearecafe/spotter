<?php

namespace App\Http\Controllers;

use App\Help\HelpArticle;
use App\Help\HelpCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The public help center at /help: guides for coaches, gyms and the clients they train.
 */
class HelpController extends Controller
{
    public function __construct(private readonly HelpCenter $help) {}

    public function index(): View
    {
        $categories = [];

        foreach ($this->help->categories() as $slug => $category) {
            $articles = $this->help->inCategory($slug);

            $categories[] = [
                'slug' => $slug,
                ...$category,
                'count' => count($articles),
                'top' => array_slice($articles, 0, 3),
            ];
        }

        return view('help.index', [
            ...$this->shared(),
            'categories' => $categories,
            'popular' => $this->help->popular(8),
        ]);
    }

    public function category(string $category): View
    {
        $found = $this->help->category($category);
        abort_if($found === null, 404);

        return view('help.category', [
            ...$this->shared(),
            'current' => $category,
            'category' => ['slug' => $category, ...$found],
            'articles' => $this->help->inCategory($category),
        ]);
    }

    public function article(string $category, string $article): View
    {
        $found = $this->help->find($category, $article);
        abort_if($found === null, 404);

        $siblings = $this->help->inCategory($category);
        $paths = array_map(fn (HelpArticle $a) => $a->path(), $siblings);
        $index = array_search($found->path(), $paths, true);
        $index = $index === false ? 0 : $index;

        return view('help.article', [
            ...$this->shared(),
            'current' => $category,
            'category' => ['slug' => $category, ...(array) $this->help->category($category)],
            'article' => $found,
            'siblings' => $siblings,
            'previous' => $siblings[$index - 1] ?? null,
            'next' => $siblings[$index + 1] ?? null,
            'related' => $this->help->related($found),
        ]);
    }

    public function search(Request $request): View
    {
        $query = $this->query($request);

        return view('help.search', [
            ...$this->shared(),
            'query' => $query,
            'results' => $query === '' ? [] : $this->results($query, 20),
        ]);
    }

    /**
     * Results as you type in the search box: the top few, as JSON.
     */
    public function suggest(Request $request): JsonResponse
    {
        $query = $this->query($request);

        return response()->json([
            'query' => $query,
            'results' => $query === '' ? [] : $this->results($query, 6),
        ]);
    }

    private function query(Request $request): string
    {
        return Str::limit(trim((string) $request->string('q')), 100, '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function results(string $query, int $limit): array
    {
        return array_map(fn (array $row) => [
            ...$row['article']->summaryArray(),
            'url' => $row['article']->url().($row['section'] ? '#'.$row['section']['id'] : ''),
            'categoryTitle' => $this->help->category($row['article']->category)['title'] ?? '',
            'snippet' => $row['snippet'],
            'snippetParts' => $row['snippetParts'],
            'titleParts' => $row['titleParts'],
            'section' => $row['section'],
            'complete' => $row['complete'],
        ], $this->help->search($query, $limit));
    }

    /**
     * @return array{nav: array<string, array{title: string, summary: string, icon: string}>, supportEmail: string|null, current: string|null}
     */
    private function shared(): array
    {
        $email = config('services.support.email');

        return [
            'nav' => $this->help->categories(),
            'supportEmail' => is_string($email) && $email !== '' ? $email : null,
            'current' => null,
        ];
    }
}
