<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Benchmark;

use Loupe\Loupe\BrowseParameters;
use Loupe\Loupe\Loupe;
use PhpBench\Attributes\BeforeClassMethods;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\OutputTimeUnit;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[BeforeClassMethods('setUpClass')]
#[BeforeMethods('setUp')]
#[Revs(1)]
#[Iterations(10)]
#[Warmup(2)]
#[OutputTimeUnit('milliseconds', precision: 2)]
#[Groups(['browse'])]
class BrowseBench extends AbstractBench
{
    private const PAGE_SIZE = 1_000;

    private int $documentCount = 0;

    private Loupe $loupe;

    /**
     * Only the primary key: the narrowest possible projection.
     */
    public function benchBrowsePrimaryKey(): void
    {
        $this->browseAll(['id']);
    }

    /**
     * A typical listing projection: a couple of small attributes out of a large document.
     */
    public function benchBrowseSubset(): void
    {
        $this->browseAll(['id', 'title', 'release_date']);
    }

    /**
     * Control: retrieving everything must not regress.
     */
    public function benchBrowseWholeDocument(): void
    {
        $this->browseAll(['*']);
    }

    /**
     * Filtered browsing with a narrow projection.
     */
    public function benchBrowseSubsetFiltered(): void
    {
        for ($offset = 0; $offset < $this->documentCount; $offset += self::PAGE_SIZE) {
            $this->loupe->browse(
                BrowseParameters::create()
                    ->withAttributesToRetrieve(['id', 'title'])
                    ->withFilter("release_date > 0")
                    ->withLimit(self::PAGE_SIZE)
                    ->withOffset($offset),
            );
        }
    }

    public function setUp(): void
    {
        $this->loupe = self::loupe(self::searchIndexPath());
        $this->documentCount = $this->loupe->countDocuments();
    }

    public static function setUpClass(): void
    {
        self::ensureSearchIndex();
    }

    /**
     * @param array<string> $attributesToRetrieve
     */
    private function browseAll(array $attributesToRetrieve): void
    {
        for ($offset = 0; $offset < $this->documentCount; $offset += self::PAGE_SIZE) {
            $this->loupe->browse(
                BrowseParameters::create()
                    ->withAttributesToRetrieve($attributesToRetrieve)
                    ->withLimit(self::PAGE_SIZE)
                    ->withOffset($offset),
            );
        }
    }
}
