<?php

namespace App\Help;

/**
 * Search over the help articles, built in memory from the Markdown (there
 * are only a few dozen articles, so no search server is needed).
 *
 * - Words are matched on their stem (program, programs), as a prefix while
 *   someone is still typing (progr → program), and with a typo or two (progam,
 *   recieve).
 * - Words coaches use for the same thing count as a match (trainer and
 *   coach, macros and nutrition, colour and color).
 * - Words in the title count most, then headings, the summary, the
 *   category and the body. Rare words count for more than common ones.
 * - Articles that match every word come first. When none do, the ones that
 *   match most of the words are shown instead.
 * - Each result points at the section that matched best, with the matched
 *   words marked in the title and snippet.
 *
 * Plain PHP with no framework calls, so it's quick to test on its own.
 */
final class HelpSearchIndex
{
    /** How much a match in each field counts. */
    private const WEIGHTS = [
        'title' => 10.0,
        'heading' => 5.0,
        'summary' => 4.0,
        'category' => 2.0,
        'body' => 1.0,
    ];

    /** How much each kind of word match counts. */
    private const EXACT = 1.0;

    private const SYNONYM = 0.9;

    private const PREFIX = 0.8;

    private const TYPO = 0.6;

    private const STOPWORDS = [
        'a', 'about', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'by', 'can', 'could', 'do', 'doe', 'does',
        'for', 'from', 'get', 'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'me', 'my', 'of', 'on', 'or',
        'our', 'should', 'so', 'that', 'the', 'their', 'them', 'there', 'this', 'to', 'up', 'we', 'what',
        'when', 'where', 'which', 'who', 'why', 'will', 'with', 'would', 'you', 'your',
    ];

    /**
     * Words and phrases coaches and clients use for the same thing. A query
     * that contains any of them also matches the others.
     */
    private const SYNONYMS = [
        ['client', 'athlete', 'trainee', 'member', 'customer'],
        ['coach', 'trainer', 'pt', 'personal trainer'],
        ['program', 'programme', 'plan', 'block', 'training block'],
        ['workout', 'session', 'training'],
        ['template', 'saved workout'],
        ['exercise', 'movement', 'lift'],
        ['progression', 'overload', 'progressive overload'],
        ['load', 'weight', 'kg', 'lb', 'pounds', 'kilos'],
        ['rep', 'repetition'],
        ['rpe', 'effort', 'intensity', 'rate of perceived exertion'],
        ['deload', 'lighter week'],
        ['skip', 'skipped', 'skip a week'],
        ['missed', 'miss', 'no show', 'didnt train'],
        ['in person', 'in gym', 'face to face', 'one on one', '1 on 1', 'semi private'],
        ['group session', 'small group', 'semi private', 'partner training'],
        ['rest timer', 'rest', 'timer'],
        ['check in', 'checkin', 'weekly check in', 'progress report'],
        ['form check', 'video', 'technique', 'film'],
        ['inbox', 'coach inbox', 'to do', 'queue'],
        ['draft', 'drafted reply', 'ai', 'claude', 'suggested reply'],
        ['message', 'chat', 'dm', 'text'],
        ['notification', 'email', 'alert', 'notify'],
        ['nutrition', 'macros', 'calories', 'diet', 'food'],
        ['macrofactor', 'myfitnesspal', 'mfp', 'lose it', 'loseit', 'cronometer'],
        ['import', 'migrate', 'migration', 'switch', 'switching', 'move', 'moving', 'move over', 'transfer'],
        ['truecoach', 'trainerize', 'spreadsheet', 'excel', 'google sheets', 'csv'],
        ['invite', 'invitation', 'join', 'onboard'],
        ['team', 'gym', 'studio', 'staff'],
        ['admin', 'owner', 'manager', 'role', 'permission', 'access'],
        ['reassign', 'hand over', 'hand off', 'transfer client'],
        ['sign in', 'log in', 'login', 'signin'],
        ['password', 'forgot password', 'reset password'],
        ['two factor', '2fa', 'authenticator', 'mfa'],
        ['french', 'francais', 'language', 'langue'],
        ['dark mode', 'appearance', 'theme'],
        ['plan', 'pricing', 'price', 'billing', 'subscription', 'cost'],
        ['brand', 'logo', 'colour', 'color', 'branding'],
        ['compliance', 'adherence', 'consistency'],
        ['photo', 'picture', 'progress photo'],
        ['library', 'exercise library', 'database'],
        ['custom exercise', 'own exercise', 'new exercise'],
        ['delete', 'remove'],
    ];

    /** @var list<array{article: HelpArticle, category: list<string>, fields: array<string, list<string>>, sections: list<array{id: string|null, title: string|null, text: string, tokens: list<string>, heading: list<string>}>}> */
    private array $docs = [];

    /**
     * Stem → how many articles use it.
     *
     * @var array<string, int>
     */
    private array $df = [];

    /** @var array<string, list<list<list<string>>>> first stem of a synonym phrase → groups it belongs to */
    private array $synonymsByStem = [];

    /**
     * @param  iterable<HelpArticle>  $articles
     * @param  array<string, string>  $categoryTitles  category slug → title
     */
    public function __construct(iterable $articles, array $categoryTitles = [])
    {
        foreach ($articles as $article) {
            $this->add($article, $categoryTitles[$article->category] ?? '');
        }

        foreach (self::SYNONYMS as $group) {
            $phrases = array_values(array_filter(array_map(fn (string $p) => self::stems($p, keepStopwords: true), $group)));

            foreach ($phrases as $phrase) {
                $this->synonymsByStem[implode(' ', $phrase)][] = $phrases;
            }
        }
    }

    /**
     * @return list<array{
     *     article: HelpArticle,
     *     snippet: string,
     *     snippetParts: list<array{text: string, hit: bool}>,
     *     titleParts: list<array{text: string, hit: bool}>,
     *     section: array{id: string, title: string}|null,
     *     complete: bool,
     * }>
     */
    public function search(string $query, int $limit = 20): array
    {
        $concepts = $this->concepts($query);

        if ($concepts === []) {
            return [];
        }

        $phrase = self::stems($query, keepStopwords: true);
        $rows = [];

        foreach ($this->docs as $doc) {
            $row = $this->score($doc, $concepts, $phrase);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        usort($rows, fn (array $a, array $b) => [$b['matched'], $b['score']] <=> [$a['matched'], $a['score']]);

        $all = count($concepts);
        $complete = array_values(array_filter($rows, fn (array $r) => $r['matched'] === $all));

        // When few articles have every word, add those with most of them.
        if (count($complete) < 5 && $all > 1) {
            $partial = array_filter($rows, fn (array $r) => $r['matched'] < $all && $r['matched'] >= max(1, (int) ceil($all / 2)));
            $rows = [...$complete, ...$partial];
        } else {
            $rows = $complete;
        }

        return array_map(fn (array $r) => $this->present($r, $r['matched'] === $all), array_slice($rows, 0, $limit));
    }

    /**
     * The query as concepts: each is a word or a known phrase, with every
     * stem it may match and how well.
     *
     * @return list<array<string, float>> stem phrase ("cash on delivery") → match quality
     */
    private function concepts(string $query): array
    {
        $stems = self::stems($query, keepStopwords: true);
        $meaningful = array_filter($stems, fn (string $stem) => ! in_array($stem, self::STOPWORDS, true));
        $concepts = [];
        $last = count($stems) - 1;

        for ($i = 0; $i <= $last; $i++) {
            // The longest known phrase starting here, like "cash on delivery".
            $found = null;

            for ($length = min(4, $last - $i + 1); $length >= 2; $length--) {
                $key = implode(' ', array_slice($stems, $i, $length));

                if (isset($this->synonymsByStem[$key])) {
                    $found = [$key, $length];
                    break;
                }
            }

            if ($found !== null) {
                [$key, $length] = $found;
                $concept = [$key => self::EXACT];
                $this->addSynonyms($concept, $key);
                $concepts[] = $concept;
                $i += $length - 1;

                continue;
            }

            $word = $stems[$i];

            // Little words only count when there's nothing else ("how do I").
            if ($meaningful !== [] && in_array($word, self::STOPWORDS, true)) {
                continue;
            }

            $concept = $this->wordMatches($word, typing: $i === $last);
            $this->addSynonyms($concept, $word);
            $concepts[] = $concept;
        }

        return $concepts;
    }

    /**
     * Every indexed stem this word can match: itself, longer words it starts
     * (as the person types), and words a typo or two away.
     *
     * @return array<string, float>
     */
    private function wordMatches(string $word, bool $typing): array
    {
        $matches = [$word => self::EXACT];
        $length = strlen($word);
        $numeric = ctype_digit($word);
        $prefixFrom = $typing ? 2 : 3;

        // A word the articles use, or one we know (RPE, macros), is spelled
        // right: it isn't the start of a longer word (rep is not report) and
        // doesn't match the words a letter away (load is not lead).
        $known = isset($this->synonymsByStem[$word]) || isset($this->df[$word]);
        $prefixFrom = $known ? PHP_INT_MAX : $prefixFrom;
        $typos = $numeric || $known ? 0 : ($length >= 8 ? 2 : ($length >= 4 ? 1 : 0));

        foreach (array_keys($this->df) as $stem) {
            $stem = (string) $stem;

            if ($stem === $word) {
                continue;
            }

            if ($length >= $prefixFrom && ! $numeric && str_starts_with($stem, $word)) {
                $matches[$stem] = self::PREFIX;

                continue;
            }

            if ($typos > 0 && abs(strlen($stem) - $length) <= $typos && self::distance($word, $stem, $typos) <= $typos) {
                $matches[$stem] = self::TYPO;
            }
        }

        return $matches;
    }

    /**
     * @param  array<string, float>  $concept
     */
    private function addSynonyms(array &$concept, string $key): void
    {
        foreach ($this->synonymsByStem[$key] ?? [] as $group) {
            foreach ($group as $phrase) {
                $concept[implode(' ', $phrase)] ??= self::SYNONYM;
            }
        }
    }

    /**
     * How well one article matches, or null if it matches none of it.
     *
     * @param  array{article: HelpArticle, category: list<string>, fields: array<string, list<string>>, sections: list<array{id: string|null, title: string|null, text: string, tokens: list<string>, heading: list<string>}>}  $doc
     * @param  list<array<string, float>>  $concepts
     * @param  list<string>  $phrase
     * @return array{doc: array<string, mixed>, matched: int, score: float, section: int|null, hits: array<string, true>}|null
     */
    private function score(array $doc, array $concepts, array $phrase): ?array
    {
        $matched = 0;
        $score = 0.0;
        $hits = [];
        $sectionScores = array_fill(0, count($doc['sections']), 0.0);

        foreach ($concepts as $concept) {
            $best = 0.0;
            $cap = $this->rarityCap($concept);

            foreach (['title', 'summary', 'category'] as $field) {
                foreach ($concept as $stems => $quality) {
                    if (self::contains($doc['fields'][$field], (string) $stems)) {
                        $best = max($best, $quality * self::WEIGHTS[$field] * min($this->rarity((string) $stems), $cap));
                        $this->hit($hits, (string) $stems);
                    }
                }
            }

            foreach ($doc['sections'] as $s => $section) {
                $inSection = 0.0;

                foreach ($concept as $stems => $quality) {
                    $stems = (string) $stems;
                    $rarity = min($this->rarity($stems), $cap);

                    if (self::contains($section['heading'], $stems)) {
                        $inSection = max($inSection, $quality * self::WEIGHTS['heading'] * $rarity);
                        $this->hit($hits, $stems);
                    } elseif (self::contains($section['tokens'], $stems)) {
                        $inSection = max($inSection, $quality * self::WEIGHTS['body'] * $rarity);
                        $this->hit($hits, $stems);
                    }
                }

                $sectionScores[$s] += $inSection;
                $best = max($best, $inSection);
            }

            if ($best > 0) {
                $matched++;
                $score += $best;
            }
        }

        if ($matched === 0) {
            return null;
        }

        // A title the query mostly covers beats a longer one that only
        // mentions it ("Templates" over "Scheduling workouts from templates").
        $titleWords = array_values(array_filter($doc['fields']['title'], fn (string $stem) => ! in_array($stem, self::STOPWORDS, true)));

        if ($titleWords !== []) {
            $covered = count(array_filter($titleWords, fn (string $stem) => isset($hits[$stem])));
            $score += 6 * $covered / count($titleWords);
        }

        // Words found together in one section count extra.
        $top = $sectionScores === [] ? 0.0 : max($sectionScores);
        $score += $top * 0.5;

        // The words together, in order, count extra.
        if (count($phrase) > 1) {
            if (self::contains($doc['fields']['title'], implode(' ', $phrase))) {
                $score += 10;
            } elseif (self::contains($doc['fields']['body'], implode(' ', $phrase))) {
                $score += 4;
            }
        }

        $section = $top > 0 ? array_search($top, $sectionScores, true) : null;

        return [
            'doc' => $doc,
            'matched' => $matched,
            'score' => $score,
            'section' => is_int($section) ? $section : null,
            'hits' => $hits,
        ];
    }

    /**
     * A synonym, prefix or typo match can't count for more than the word the
     * person actually typed: "coach" is common, so its rarer synonym "trainer"
     * mustn't outrank articles that say "coach".
     *
     * @param  array<string, float>  $concept
     */
    private function rarityCap(array $concept): float
    {
        $typed = array_keys(array_filter($concept, fn (float $quality) => $quality === self::EXACT));
        $known = array_filter($typed, fn ($stems) => isset($this->df[explode(' ', (string) $stems)[0]]));

        // A word no article uses (a typo, say) sets no cap.
        return $known === [] ? INF : max(array_map(fn ($stems) => $this->rarity((string) $stems), $known));
    }

    /** Words few articles use count for more than ones most use. */
    private function rarity(string $stems): float
    {
        $rarest = min(array_map(fn (string $stem) => $this->df[$stem] ?? 1, explode(' ', $stems)));

        return 1 + log(max(1, count($this->docs)) / max(1, $rarest));
    }

    /**
     * @param  array<string, true>  $hits
     */
    private function hit(array &$hits, string $stems): void
    {
        foreach (explode(' ', $stems) as $stem) {
            $hits[$stem] = true;
        }
    }

    /**
     * @param  array{doc: array<string, mixed>, matched: int, score: float, section: int|null, hits: array<string, true>}  $row
     * @return array{article: HelpArticle, snippet: string, snippetParts: list<array{text: string, hit: bool}>, titleParts: list<array{text: string, hit: bool}>, section: array{id: string, title: string}|null, complete: bool}
     */
    private function present(array $row, bool $complete): array
    {
        /** @var array{article: HelpArticle, sections: list<array{id: string|null, title: string|null, text: string, tokens: list<string>, heading: list<string>}>} $doc */
        $doc = $row['doc'];
        $sections = $doc['sections'];
        $index = $row['section'];

        // Snippet from the best section, else the first section with a hit.
        $text = $sections[$index ?? 0]['text'] ?? '';
        $parts = self::excerpt($text, $row['hits']);

        if ($index === null || ! self::anyHit($parts)) {
            foreach ($sections as $section) {
                $candidate = self::excerpt($section['text'], $row['hits']);

                if (self::anyHit($candidate)) {
                    $parts = $candidate;
                    break;
                }
            }
        }

        $best = $index !== null ? $sections[$index] : null;

        return [
            'article' => $doc['article'],
            'snippet' => implode('', array_column($parts, 'text')),
            'snippetParts' => $parts,
            'titleParts' => self::mark($doc['article']->title, $row['hits']),
            'section' => $best !== null && $best['id'] !== null && $best['title'] !== null
                ? ['id' => $best['id'], 'title' => $best['title']]
                : null,
            'complete' => $complete,
        ];
    }

    private function add(HelpArticle $article, string $categoryTitle): void
    {
        $sections = [];
        $pieces = preg_split('#<h2 id="([^"]+)">(.*?)</h2>#s', $article->html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$article->html];

        for ($i = 0; $i < count($pieces); $i += $i === 0 ? 1 : 3) {
            $id = $i === 0 ? null : $pieces[$i];
            $title = $i === 0 ? null : self::plain($pieces[$i + 1]);
            $html = $i === 0 ? $pieces[0] : ($pieces[$i + 2] ?? '');

            // Smaller headings inside a section count as headings too.
            preg_match_all('#<h[3-6][^>]*>(.*?)</h[3-6]>#s', $html, $subheadings);

            $text = self::plain($html);
            $sections[] = [
                'id' => $id,
                'title' => $title,
                'text' => $text,
                'tokens' => self::stems($text, keepStopwords: true),
                'heading' => self::stems(trim(($title ?? '').' '.implode(' ', array_map(self::plain(...), $subheadings[1]))), keepStopwords: true),
            ];
        }

        $body = [];
        foreach ($sections as $section) {
            $body = [...$body, ...$section['tokens']];
        }

        $fields = [
            'title' => self::stems($article->title, keepStopwords: true),
            'summary' => self::stems($article->summary, keepStopwords: true),
            'category' => self::stems($categoryTitle, keepStopwords: true),
            'body' => $body,
        ];

        foreach (array_unique([...$fields['title'], ...$fields['summary'], ...$body]) as $stem) {
            $this->df[$stem] = ($this->df[$stem] ?? 0) + 1;
        }

        $this->docs[] = ['article' => $article, 'category' => $fields['category'], 'fields' => $fields, 'sections' => $sections];
    }

    /**
     * Whether the tokens contain these stems, next to each other in order.
     *
     * @param  list<string>  $tokens
     */
    private static function contains(array $tokens, string $stems): bool
    {
        $needle = explode(' ', $stems);
        $length = count($needle);

        if ($length === 1) {
            return in_array($needle[0], $tokens, true);
        }

        $last = count($tokens) - $length;

        for ($i = 0; $i <= $last; $i++) {
            if ($tokens[$i] === $needle[0] && array_slice($tokens, $i, $length) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * About 180 characters of the text around the first matched word, cut
     * on word boundaries, split into marked and unmarked parts.
     *
     * @param  array<string, true>  $hits
     * @return list<array{text: string, hit: bool}>
     */
    private static function excerpt(string $text, array $hits, int $length = 180): array
    {
        $first = null;

        foreach (self::words($text) as [$word, $offset]) {
            if (isset($hits[self::stem(self::normalize($word))])) {
                $first = $offset;
                break;
            }
        }

        $start = 0;

        if ($first !== null && $first > 60) {
            $space = strrpos(substr($text, 0, $first - 40), ' ');
            $start = $space === false ? 0 : $space + 1;
        }

        $piece = substr($text, $start);

        if (strlen($piece) > $length) {
            $cut = strrpos(substr($piece, 0, $length), ' ');
            $piece = rtrim(substr($piece, 0, $cut === false ? $length : $cut), ' ,.;:').'…';
        }

        if ($start > 0) {
            $piece = '…'.$piece;
        }

        return self::mark($piece, $hits);
    }

    /**
     * @param  array<string, true>  $hits
     * @return list<array{text: string, hit: bool}>
     */
    private static function mark(string $text, array $hits): array
    {
        $parts = [];
        $at = 0;

        foreach (self::words($text) as [$word, $offset]) {
            if (! isset($hits[self::stem(self::normalize($word))])) {
                continue;
            }

            if ($offset > $at) {
                $parts[] = ['text' => substr($text, $at, $offset - $at), 'hit' => false];
            }

            $parts[] = ['text' => $word, 'hit' => true];
            $at = $offset + strlen($word);
        }

        if ($at < strlen($text)) {
            $parts[] = ['text' => substr($text, $at), 'hit' => false];
        }

        return $parts;
    }

    /**
     * @param  list<array{text: string, hit: bool}>  $parts
     */
    private static function anyHit(array $parts): bool
    {
        foreach ($parts as $part) {
            if ($part['hit']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Words in the text with their byte offsets.
     *
     * @return list<array{string, int}>
     */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+(?:[\'’][\p{L}]+)?/u', $text, $matches, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $m) => [$m[0], $m[1]], $matches[0]);
    }

    /**
     * The stems of the words in the text, in order.
     *
     * @return list<string>
     */
    public static function stems(string $text, bool $keepStopwords = false): array
    {
        $stems = [];

        foreach (self::words($text) as [$word]) {
            $stem = self::stem(self::normalize($word));

            if ($stem !== '' && ($keepStopwords || ! in_array($stem, self::STOPWORDS, true))) {
                $stems[] = $stem;
            }
        }

        return $stems;
    }

    private static function normalize(string $word): string
    {
        $word = mb_strtolower($word);
        $word = str_replace(["'", '’'], '', $word);

        return strtr($word, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e',
            'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        ]);
    }

    /**
     * A light English stemmer: enough that plurals and -ing/-ed forms find
     * each other (programs → program, logging → log, invited → invit,
     * invites → invit).
     */
    public static function stem(string $word): string
    {
        $length = strlen($word);

        if ($length <= 3 || ctype_digit($word)) {
            return $word;
        }

        if (str_ends_with($word, 'ies') && $length > 4) {
            return substr($word, 0, -3).'y';
        }

        if (preg_match('/(ss|us|is|eed)$/', $word)) {
            return $word;
        }

        if (str_ends_with($word, 'ing') && $length > 5) {
            return self::undouble(substr($word, 0, -3));
        }

        if (str_ends_with($word, 'ed') && $length > 4) {
            return self::undouble(substr($word, 0, -2));
        }

        if (preg_match('/(ches|shes|sses|xes|zes)$/', $word)) {
            return substr($word, 0, -2);
        }

        if (str_ends_with($word, 's')) {
            $word = substr($word, 0, -1);
        }

        // "charge" and "charged" both become "charg".
        return strlen($word) > 4 && str_ends_with($word, 'e') ? substr($word, 0, -1) : $word;
    }

    /** "logg" → "log", "skipp" → "skip", but not "fill" or "pass". */
    private static function undouble(string $word): string
    {
        $length = strlen($word);

        if ($length > 3 && $word[$length - 1] === $word[$length - 2] && ! in_array($word[$length - 1], ['l', 's', 'z'], true)) {
            return substr($word, 0, -1);
        }

        return str_ends_with($word, 'e') && $length > 4 ? substr($word, 0, -1) : $word;
    }

    /**
     * Edit distance counting a swap of two letters as one typo, stopping
     * early once it's past $max.
     */
    private static function distance(string $a, string $b, int $max): int
    {
        $n = strlen($a);
        $m = strlen($b);
        $previous2 = [];
        $previous = range(0, $m);

        for ($i = 1; $i <= $n; $i++) {
            $current = [$i];
            $rowMin = $i;

            for ($j = 1; $j <= $m; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $value = min($previous[$j] + 1, $current[$j - 1] + 1, $previous[$j - 1] + $cost);

                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $value = min($value, $previous2[$j - 2] + 1);
                }

                $current[$j] = $value;
                $rowMin = min($rowMin, $value);
            }

            if ($rowMin > $max) {
                return $max + 1;
            }

            $previous2 = $previous;
            $previous = $current;
        }

        return $previous[$m];
    }

    /** Text from a piece of the article's HTML, with table cells kept apart. */
    private static function plain(string $html): string
    {
        $text = (string) preg_replace('/<[^>]+>/', ' ', $html);

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
