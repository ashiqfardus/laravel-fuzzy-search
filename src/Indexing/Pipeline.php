<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

use Ashiqfardus\LaravelFuzzySearch\Support\Accents;

/**
 * The text → terms sequence the index and the query side share: tokenize, fold accents
 * (opt-in), drop stop words, stem. One instance per model class (see IndexManager::pipelineFor()).
 *
 * @internal Construct through IndexManager; the shape may change without notice.
 */
final class Pipeline
{
    /** @var string[] lower-cased (and folded, when enabled) */
    private array $stopWords;

    public function __construct(
        private readonly TokenizerInterface $tokenizer,
        private readonly StemmerInterface   $stemmer,
        array $stopWords = [],
        private readonly bool $foldAccents = false,
    ) {
        $this->stopWords = $this->normaliseStopWords($stopWords);
    }

    /** Tokens in text order, duplicates kept, stop words removed, stemmed. */
    public function tokens(string $text, array $extraStopWords = []): array
    {
        $stop   = $extraStopWords === [] ? $this->stopWords : array_values(array_unique(array_merge($this->stopWords, $this->normaliseStopWords($extraStopWords))));
        $tokens = [];

        foreach ($this->tokenizer->tokenize($text) as $word) {
            if ($this->foldAccents) {
                $word = Accents::fold($word);
            }
            if (in_array($word, $stop, true)) {
                continue;
            }
            $tokens[] = $this->stemmer->stem($word);
        }

        return $tokens;
    }

    public function withStopWords(array $stopWords): static
    {
        return new static($this->tokenizer, $this->stemmer, $stopWords, $this->foldAccents);
    }

    public function tokenizer(): TokenizerInterface { return $this->tokenizer; }
    public function stemmer(): StemmerInterface     { return $this->stemmer; }
    /** @return string[] */
    public function stopWords(): array              { return $this->stopWords; }
    public function foldsAccents(): bool            { return $this->foldAccents; }

    private function normaliseStopWords(array $words): array
    {
        $out = [];
        foreach ($words as $word) {
            $word  = mb_strtolower((string) $word, 'UTF-8');
            $out[] = $this->foldAccents ? Accents::fold($word) : $word;
        }

        return array_values(array_unique($out));
    }
}
