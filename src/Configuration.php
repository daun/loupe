<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Exception\InvalidConfigurationException;
use Loupe\Loupe\Internal\Search\Sorting\Relevance;
use Psr\Log\LoggerInterface;

final class Configuration
{
    public const ATTRIBUTE_NAME_RGXP = '[a-zA-Z\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*';

    public const ATTRIBUTE_RANKING_ORDER_FACTOR = 0.8;

    public const MAX_ATTRIBUTE_NAME_LENGTH = 64;

    public const RANKING_RULES_ORDER_FACTOR = 0.7;

    /**
     * @var array<string>
     */
    private array $filterableAttributes = [];

    /**
     * @var array<string>
     */
    private array $languages = [];

    private ?LoggerInterface $logger = null;

    private int $maxQueryTokens = 10;

    private int $minTokenLengthForPrefixSearch = 3;

    private string $primaryKey = 'id';

    /**
     * @var array<string>
     */
    private array $rankingRules = [
        'words',
        'typo',
        'proximity',
        'attribute',
        'exactness',
    ];

    /**
     * @var array<string>
     */
    private array $searchableAttributes = ['*'];

    /**
     * @var array<string>
     */
    private array $sortableAttributes = [];

    /**
     * @var array<string>
     */
    private array $stopWords = [];

    private TypoTolerance $typoTolerance;

    public function __construct()
    {
        $this->typoTolerance = new TypoTolerance();
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * @return array<string>
     */
    public function getDocumentSchemaRelevantAttributes(): array
    {
        return array_unique(array_merge(
            [$this->getPrimaryKey()],
            $this->getSearchableAttributes(),
            $this->getFilterableAttributes(),
            $this->getSortableAttributes()
        ));
    }

    /**
     * @return array<string>
     */
    public function getFilterableAttributes(): array
    {
        return $this->filterableAttributes;
    }

    /**
     * Returns a hash of all the settings that are relevant during the indexing process. If anything changes in the
     * configuration, a reindex of data is needed.
     */
    public function getIndexHash(): string
    {
        $hash = [];

        $hash[] = json_encode($this->getPrimaryKey());
        $hash[] = json_encode($this->getSearchableAttributes());
        $hash[] = json_encode($this->getFilterableAttributes());
        $hash[] = json_encode($this->getSortableAttributes());
        $hash[] = json_encode($this->getStopWords());

        $hash[] = $this->getTypoTolerance()->isDisabled() ? 'disabled' : 'enabled';
        $hash[] = $this->getTypoTolerance()->getAlphabetSize();
        $hash[] = $this->getTypoTolerance()->getIndexLength();
        $hash[] = $this->getTypoTolerance()->isEnabledForPrefixSearch();

        return hash('sha256', implode(';', $hash));
    }

    /**
     * @return array<string>
     */
    public function getLanguages(): array
    {
        return $this->languages;
    }

    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    public function getMaxQueryTokens(): int
    {
        return $this->maxQueryTokens;
    }

    public function getMinTokenLengthForPrefixSearch(): int
    {
        return $this->minTokenLengthForPrefixSearch;
    }

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    /**
     * @return array<string>
     */
    public function getRankingRules(): array
    {
        return $this->rankingRules;
    }

    /**
     * @return array<string>
     */
    public function getSearchableAttributes(): array
    {
        return $this->searchableAttributes;
    }

    /**
     * @return array<string>
     */
    public function getSortableAttributes(): array
    {
        return $this->sortableAttributes;
    }

    /**
     * @return array<string>
     */
    public function getStopWords(): array
    {
        return $this->stopWords;
    }

    public function getTypoTolerance(): TypoTolerance
    {
        return $this->typoTolerance;
    }

    public static function validateAttributeName(string $name): void
    {
        if (\strlen($name) > self::MAX_ATTRIBUTE_NAME_LENGTH
            || !preg_match('/^' . self::ATTRIBUTE_NAME_RGXP . '$/', $name)
        ) {
            throw InvalidConfigurationException::becauseInvalidAttributeName($name);
        }
    }

    /**
     * @param array<string> $filterableAttributes
     */
    public function withFilterableAttributes(array $filterableAttributes): self
    {
        self::validateAttributeNames($filterableAttributes);

        sort($filterableAttributes);

        $clone = clone $this;
        $clone->filterableAttributes = $filterableAttributes;

        return $clone;
    }

    /**
     * @param array<string> $languages
     */
    public function withLanguages(array $languages): self
    {
        sort($languages);

        $clone = clone $this;
        $clone->languages = $languages;

        return $clone;
    }

    public function withLogger(?LoggerInterface $logger): self
    {
        $clone = clone $this;
        $clone->logger = $logger;

        return $clone;
    }

    public function withMaxQueryTokens(int $maxQueryTokens): self
    {
        $clone = clone $this;
        $clone->maxQueryTokens = $maxQueryTokens;

        return $clone;
    }

    public function withMinTokenLengthForPrefixSearch(int $minTokenLengthForPrefixSearch): self
    {
        $clone = clone $this;
        $clone->minTokenLengthForPrefixSearch = $minTokenLengthForPrefixSearch;

        return $clone;
    }

    public function withPrimaryKey(string $primaryKey): self
    {
        $clone = clone $this;
        $clone->primaryKey = $primaryKey;

        return $clone;
    }

    /**
     * @param array<string> $rankingRules
     */
    public function withRankingRules(array $rankingRules): self
    {
        if (!\count($rankingRules)) {
            throw new InvalidConfigurationException('Ranking rules cannot be empty.');
        }

        foreach ($rankingRules as $v) {
            if (!\is_string($v)) {
                throw new InvalidConfigurationException('Ranking rules must be an array of strings.');
            }
            if (!\in_array($v, array_keys(Relevance::RANKERS), true)) {
                throw new InvalidConfigurationException('Unknown ranking rule: ' . $v);
            }
        }

        $clone = clone $this;
        $clone->rankingRules = $rankingRules;

        return $clone;
    }

    /**
     * @param array<string> $searchableAttributes
     */
    public function withSearchableAttributes(array $searchableAttributes): self
    {
        if (['*'] !== $searchableAttributes) {
            self::validateAttributeNames($searchableAttributes);
        }

        // Do not sort searchable attributes as their order is relevant for ranking
        // sort($searchableAttributes);

        $clone = clone $this;
        $clone->searchableAttributes = $searchableAttributes;

        return $clone;
    }

    /**
     * @param array<string> $sortableAttributes
     */
    public function withSortableAttributes(array $sortableAttributes): self
    {
        self::validateAttributeNames($sortableAttributes);

        sort($sortableAttributes);

        $clone = clone $this;
        $clone->sortableAttributes = $sortableAttributes;

        return $clone;
    }

    /**
     * @param array<string> $stopWords
     */
    public function withStopWords(array $stopWords): self
    {
        sort($stopWords);

        $clone = clone $this;
        $clone->stopWords = $stopWords;

        return $clone;
    }

    public function withTypoTolerance(TypoTolerance $tolerance): self
    {
        $clone = clone $this;
        $clone->typoTolerance = $tolerance;

        return $clone;
    }

    /**
     * @param array<string> $names
     */
    private static function validateAttributeNames(array $names): void
    {
        foreach ($names as $name) {
            self::validateAttributeName($name);
        }
    }
}
