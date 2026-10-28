<?php

namespace App\Help;

/**
 * One help article, read from resources/help/{category}/{slug}.md.
 */
final readonly class HelpArticle
{
    /**
     * @param  list<array{id: string, title: string}>  $toc
     * @param  list<string>  $related  "category/slug" paths
     */
    public function __construct(
        public string $category,
        public string $slug,
        public string $title,
        public string $summary,
        public int $order,
        public bool $popular,
        public string $html,
        public string $text,
        public array $toc,
        public array $related,
    ) {}

    public function path(): string
    {
        return "{$this->category}/{$this->slug}";
    }

    public function url(): string
    {
        return '/help/'.$this->path();
    }

    /**
     * @return array{category: string, slug: string, title: string, summary: string}
     */
    public function summaryArray(): array
    {
        return [
            'category' => $this->category,
            'slug' => $this->slug,
            'title' => $this->title,
            'summary' => $this->summary,
        ];
    }
}
