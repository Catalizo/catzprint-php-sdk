<?php

declare(strict_types=1);

namespace CatzPrint\Model;

use CatzPrint\Resource\Jobs;

/**
 * One page of Jobs::list(). Iterate it for this page's jobs, or call
 * autoPagingIterator() to walk every following page too.
 *
 * @implements \IteratorAggregate<int, Job>
 */
final class JobPage implements \IteratorAggregate, \Countable
{
    /**
     * @param list<Job>            $items
     * @param array<string, int>   $counts Per-status counts for the last 24 h (with counts: true).
     * @param array<string, mixed> $params The list() params, used to fetch the next page.
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $nextCursor,
        public readonly array $counts,
        private readonly ?Jobs $jobs = null,
        private readonly array $params = [],
    ) {
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null && $this->nextCursor !== '';
    }

    /** The next page, or null on the last one. */
    public function nextPage(): ?self
    {
        if (!$this->hasMore() || $this->jobs === null) {
            return null;
        }

        return $this->jobs->list(['cursor' => $this->nextCursor] + $this->params);
    }

    /** @return \Generator<int, Job> Every job on this and all following pages. */
    public function autoPagingIterator(): \Generator
    {
        $page = $this;
        while ($page !== null) {
            foreach ($page->items as $job) {
                yield $job;
            }
            $page = $page->nextPage();
        }
    }

    /** @return \ArrayIterator<int, Job> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
