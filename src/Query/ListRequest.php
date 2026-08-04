<?php

namespace Dynart\Dpress\Query;

use Dynart\Micro\RequestInterface;

/**
 * Turns what a dynamic list asked for into a query context
 *
 * The admin lists render themselves in the browser and ask the server for rows, which means the
 * sort column, the direction and the page all arrive as request parameters. Nothing here trusts
 * any of them: **the sort column has to be in a whitelist the calling screen passes in**, or it
 * is dropped. `Query::addOrderBy()` puts the name into the SQL, so a list handing the request
 * straight through would be an injection.
 *
 * The parameter names match the defaults of `dynamic-list.js` - `sort`, `order`, `offset`, `max`.
 */
class ListRequest {

    const SORT = 'sort';
    const ORDER = 'order';
    const OFFSET = 'offset';
    const MAX = 'max';

    const DEFAULT_MAX = 25;

    /** However large a page the browser asks for, this is what it gets at most */
    const MAX_MAX = 200;

    public function __construct(private RequestInterface $request) {}

    /**
     * The query context for one page of a list
     *
     * @param string[] $sortable The column names this list may be ordered by
     * @param string[] $filters  Request parameter names to carry into the context as they are
     * @return array `order_by`, `order_dir`, `offset`, `max`, plus whatever filters had a value
     */
    public function context(array $sortable, array $filters = []): array {
        $context = [
            'offset' => $this->offset(),
            'max'    => $this->max(),
        ];
        $sort = $this->sort($sortable);
        if ($sort !== '') {
            $context['order_by'] = $sort;
            $context['order_dir'] = $this->direction();
        }
        foreach ($filters as $name) {
            $value = $this->request->get($name);
            if ($value !== null && $value !== '') {
                $context[$name] = $value;
            }
        }
        return $context;
    }

    /**
     * @return string The requested sort column when the list allows it, otherwise nothing
     */
    public function sort(array $sortable): string {
        $sort = (string)$this->request->get(self::SORT, '');
        return in_array($sort, $sortable, true) ? $sort : '';
    }

    public function direction(): string {
        return strtolower((string)$this->request->get(self::ORDER, 'asc')) === 'desc' ? 'desc' : 'asc';
    }

    public function offset(): int {
        return max(0, (int)$this->request->get(self::OFFSET, 0));
    }

    /**
     * A page size is clamped rather than rejected: a browser asking for everything gets a page,
     * not an error, and a hand-written `max=100000` cannot pull the whole table into memory.
     */
    public function max(): int {
        $max = (int)$this->request->get(self::MAX, self::DEFAULT_MAX);
        if ($max < 1) {
            $max = self::DEFAULT_MAX;
        }
        return min($max, self::MAX_MAX);
    }
}
