<?php

namespace App\Services\Ai;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class DocsSearchService
{
    private const array EXCLUDED_FILES = ['index.md', 'markdown-examples.md'];

    private const int HEADING_WEIGHT = 5;

    private const int EXCERPT_CONTEXT_LINES = 6;

    private const int EXCERPTS_PER_PAGE = 2;

    private const int MAX_PAGE_LENGTH = 30000;

    private const int PHRASE_WEIGHT = 3;

    private const int SCORED_LINES_PER_PAGE = 3;

    /**
     * @var Collection<string, array{page: string, title: string, url: string, lines: non-empty-list<string>, lower: non-empty-list<string>}>|null
     */
    private ?Collection $documents = null;

    /**
     * Keyword search over the markdown sources in docs/ (the same content the
     * public VitePress site is built from).
     *
     * Terms are weighted by how rare they are across all pages, otherwise words like
     * "track" that appear on almost every page drown out the one specific term
     * (e.g. "query") that points to the right paragraph.
     *
     * @return array<int, array{page: string, title: string, url: string, excerpt: string}>
     */
    public function search(string $query, int $limit = 5): array
    {
        $terms = collect(preg_split('/[^\pL\pN_]+/u', Str::lower($query)) ?: [])
            ->filter(fn (string $term): bool => Str::length($term) >= 3)
            ->unique()
            ->values();

        if ($terms->isEmpty()) {
            return [];
        }

        $documents = $this->documents();
        $weights = $this->termWeights($terms, $documents);
        $phrase = trim((string) preg_replace('/[^\pL\pN_]+/u', ' ', Str::lower($query)));

        return $documents
            ->map(fn (array $document): ?array => $this->scoreDocument($document, $weights, $phrase))
            ->filter()
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn (array $result): array => [
                'page' => $result['page'],
                'title' => $result['title'],
                'url' => $result['url'],
                'excerpt' => $result['excerpt'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{page: string, title: string, url: string, content: string}|null
     */
    public function page(string $slug): ?array
    {
        $document = $this->documents()->get($slug);

        if ($document === null) {
            return null;
        }

        return [
            'page' => $document['page'],
            'title' => $document['title'],
            'url' => $document['url'],
            'content' => Str::limit(implode("\n", $document['lines']), self::MAX_PAGE_LENGTH),
        ];
    }

    /**
     * @return list<array{page: string, title: string}>
     */
    public function pages(): array
    {
        return $this->documents()
            ->map(fn (array $document): array => ['page' => $document['page'], 'title' => $document['title']])
            ->values()
            ->all();
    }

    /**
     * @return Collection<string, array{page: string, title: string, url: string, lines: non-empty-list<string>, lower: non-empty-list<string>}>
     */
    private function documents(): Collection
    {
        return $this->documents ??= collect(File::files(base_path('docs')))
            ->filter(fn ($file): bool => $file->getExtension() === 'md' && ! in_array($file->getFilename(), self::EXCLUDED_FILES, true))
            ->sortBy(fn ($file): string => $file->getFilename())
            ->mapWithKeys(function ($file): array {
                $slug = $file->getBasename('.md');
                $content = str_replace("\r\n", "\n", $this->stripFrontmatter(File::get($file->getPathname())));
                $lines = explode("\n", $content);

                return [$slug => [
                    'page' => $slug,
                    'title' => $this->title($lines, $slug),
                    'url' => url('docs/'.$slug),
                    'lines' => $lines,
                    'lower' => explode("\n", Str::lower($content)),
                ]];
            });
    }

    /**
     * @param  Collection<int, string>  $terms
     * @param  Collection<string, array{page: string, title: string, url: string, lines: non-empty-list<string>, lower: non-empty-list<string>}>  $documents
     * @return Collection<string, float>
     */
    private function termWeights(Collection $terms, Collection $documents): Collection
    {
        $documentTexts = $documents->map(fn (array $document): string => implode("\n", $document['lower']));

        return $terms->mapWithKeys(function (string $term) use ($documentTexts): array {
            $documentFrequency = $documentTexts->filter(fn (string $text): bool => str_contains($text, $term))->count();

            return [$term => log(1 + $documentTexts->count() / max(1, $documentFrequency))];
        });
    }

    /**
     * @param  array{page: string, title: string, url: string, lines: non-empty-list<string>, lower: non-empty-list<string>}  $document
     * @param  Collection<string, float>  $weights
     * @return array{page: string, title: string, url: string, excerpt: string, score: float}|null
     */
    private function scoreDocument(array $document, Collection $weights, string $phrase): ?array
    {
        $lineScores = [];

        foreach ($document['lower'] as $index => $line) {
            $matchedTerms = 0;
            $lineScore = 0.0;

            foreach ($weights as $term => $weight) {
                $hits = substr_count($line, $term);

                if ($hits > 0) {
                    $matchedTerms++;
                    $lineScore += $hits * $weight;
                }
            }

            if ($matchedTerms === 0) {
                continue;
            }

            // A line hitting several different terms is far more likely the answer than
            // one repeating a single term.
            $lineScore *= $matchedTerms;

            if (str_contains($phrase, ' ') && str_contains($line, $phrase)) {
                $lineScore *= self::PHRASE_WEIGHT;
            }

            if (str_starts_with(ltrim($line), '#')) {
                $lineScore *= self::HEADING_WEIGHT;
            }

            $lineScores[$index] = $lineScore;
        }

        if ($lineScores === []) {
            return null;
        }

        return [
            'page' => $document['page'],
            'title' => $document['title'],
            'url' => $document['url'],
            'excerpt' => $this->excerpts($document['lines'], $lineScores),
            'score' => $this->pageScore($lineScores),
        ];
    }

    /**
     * Only the best lines count, a plain sum would let long pages full of common
     * words outrank the short page that states the answer once.
     *
     * @param  array<int, float>  $lineScores
     */
    private function pageScore(array $lineScores): float
    {
        rsort($lineScores);

        return array_sum(array_slice($lineScores, 0, self::SCORED_LINES_PER_PAGE));
    }

    /**
     * @param  list<string>  $lines
     * @param  array<int, float>  $lineScores
     */
    private function excerpts(array $lines, array $lineScores): string
    {
        arsort($lineScores);

        $anchors = [];

        foreach (array_keys($lineScores) as $index) {
            $overlaps = collect($anchors)->contains(fn (int $anchor): bool => abs($anchor - $index) <= self::EXCERPT_CONTEXT_LINES * 2);

            if (! $overlaps) {
                $anchors[] = $index;
            }

            if (count($anchors) === self::EXCERPTS_PER_PAGE) {
                break;
            }
        }

        sort($anchors);

        return collect($anchors)
            ->map(function (int $anchor) use ($lines): string {
                $start = max(0, $anchor - self::EXCERPT_CONTEXT_LINES);

                return trim(implode("\n", array_slice($lines, $start, self::EXCERPT_CONTEXT_LINES * 2 + 1)));
            })
            ->implode("\n\n[...]\n\n");
    }

    private function stripFrontmatter(string $content): string
    {
        return preg_replace('/\A---\r?\n.*?\r?\n---\r?\n/s', '', $content);
    }

    /**
     * @param  list<string>  $lines
     */
    private function title(array $lines, string $slug): string
    {
        foreach ($lines as $line) {
            if (str_starts_with($line, '# ')) {
                return trim(Str::after($line, '# '));
            }
        }

        return Str::headline($slug);
    }
}
