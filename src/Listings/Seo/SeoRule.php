<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo;

/**
 * What a listing's view says about itself: its title, meta description,
 * heading, introduction and questions, for one language and one key (the
 * facets it describes, RuleKey). Stored in the SEO rules' table (SeoRules).
 */
final class SeoRule
{
    public const FIELDS = ['title', 'description', 'h1', 'intro', 'faq'];

    /**
     * @param  string  $locale  A WordPress locale, or '' for every language
     * @param  string  $key  RuleKey's canonical form: '' is the listing without filters
     * @param  list<array{question: string, answer: string}>  $faq
     */
    public function __construct(
        public readonly string $listing,
        public readonly string $locale,
        public readonly string $key,
        public readonly int $specificity = 0,
        public readonly string $title = '',
        public readonly string $description = '',
        public readonly string $h1 = '',
        public readonly string $intro = '',
        public readonly array $faq = [],
        public readonly ?int $id = null,
        public readonly ?string $updatedAt = null,
    ) {}

    /**
     * The fields as stored (JSON) and edited.
     *
     * @return array{title: string, description: string, h1: string, intro: string, faq: list<array{question: string, answer: string}>}
     */
    public function fields(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'h1' => $this->h1,
            'intro' => $this->intro,
            'faq' => $this->faq,
        ];
    }

    /**
     * The questions of an input: pairs with a question and an answer, others dropped.
     *
     * @return list<array{question: string, answer: string}>
     */
    public static function faqFrom(mixed $faq): array
    {
        if (is_string($faq)) {
            $faq = $faq === '' ? [] : json_decode($faq, true);
        }

        $pairs = [];
        foreach (is_array($faq) ? $faq : [] as $pair) {
            $question = trim((string) (is_array($pair) ? ($pair['question'] ?? '') : ''));
            $answer = trim((string) (is_array($pair) ? ($pair['answer'] ?? '') : ''));

            if ($question !== '' && $answer !== '') {
                $pairs[] = ['question' => $question, 'answer' => $answer];
            }
        }

        return $pairs;
    }

    /**
     * The same rule with its fields replaced by an input's.
     *
     * @param  array<string, mixed>  $fields
     */
    public function withFields(array $fields): self
    {
        return new self(
            $this->listing,
            $this->locale,
            $this->key,
            $this->specificity,
            trim((string) ($fields['title'] ?? $this->title)),
            trim((string) ($fields['description'] ?? $this->description)),
            trim((string) ($fields['h1'] ?? $this->h1)),
            trim((string) ($fields['intro'] ?? $this->intro)),
            array_key_exists('faq', $fields) ? self::faqFrom($fields['faq']) : $this->faq,
            $this->id,
            $this->updatedAt,
        );
    }
}
