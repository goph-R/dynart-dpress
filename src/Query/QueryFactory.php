<?php

namespace Dynart\Dpress\Query;

use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\Micro;
use Dynart\Micro\Entities\Query;
use Dynart\Dpress\DpressException;

/**
 * Builds every query of the CMS by name, and announces it
 *
 * The read side counterpart of the form factory. A query built here is handed to the event
 * subscribers before it is returned, so a plugin can attach conditions, joins and ordering to a
 * query it did not write.
 *
 * **No service builds a `Query` with `new`.** A hand built query is invisible to plugins
 * forever, exactly like a hand written `<input>` is.
 *
 * Plugins can narrow a query but never widen it, and that falls out of the `Query` API rather
 * than being enforced here: there is no `removeCondition()`, conditions are appended, and the
 * query builder joins them with `AND`. A plugin therefore cannot strip the published filter off
 * a public listing. This only holds if the registered builder adds its own security critical
 * conditions, rather than leaving them to the caller.
 */
class QueryFactory {

    /** The namespace of the per query events */
    const EVENT_NAMESPACE = 'query';

    /** Emitted for every query, with the name as its first argument */
    const EVENT_CREATED = 'query:created';

    /** @var array<string, callable|array> */
    protected array $builders = [];

    public function __construct(protected EventServiceInterface $events) {}

    /**
     * Registers a builder for a query name
     *
     * The builder receives the context array and returns a `Query`. It has to create the query
     * itself, because `Query` takes its source in the constructor and has no setter for it.
     *
     * @param string $name The query name, snake_case (see Dpress conventions)
     * @param callable|array $builder A Micro callable: `[SomeClass::class, 'method']` or a closure
     */
    public function add(string $name, callable|array $builder): void {
        self::registerBuilderClass($builder);
        $this->builders[$name] = $builder;
    }

    /**
     * Registers a `[SomeClass::class, 'method']` builder's class in the DI container
     *
     * The builder is resolved through the container when the query is created, so registering it
     * here saves every caller from remembering a second `Micro::add()`.
     */
    public static function registerBuilderClass(callable|array $builder): void {
        if (!is_array($builder) || !isset($builder[0]) || !is_string($builder[0])) {
            return; // a closure or an already constructed object, nothing to register
        }
        if (!Micro::hasInterface($builder[0])) {
            Micro::add($builder[0]);
        }
    }

    public function has(string $name): bool {
        return isset($this->builders[$name]);
    }

    /**
     * @return string[] Every registered query name
     */
    public function names(): array {
        return array_keys($this->builders);
    }

    /**
     * Returns with the scoped event name of a query, for example `query.content_list:created`
     */
    public static function eventName(string $name, string $event = 'created'): string {
        return self::EVENT_NAMESPACE.'.'.$name.':'.$event;
    }

    /**
     * Builds a query and lets the subscribers change it
     *
     * Both a scoped and a generic event are emitted: `EventService` matches event names exactly
     * with no wildcards, so a generic only event would wake every subscriber on every query.
     * The generic one is for the genuinely cross cutting cases - access scoping, soft deletes.
     *
     * @throws DpressException if there is no such query, or the builder returns something else
     */
    public function create(string $name, array $context = []): Query {
        if (!isset($this->builders[$name])) {
            throw new DpressException("There is no query named '$name'.");
        }
        $query = call_user_func(Micro::getCallable($this->builders[$name]), $context);
        if (!$query instanceof Query) {
            throw new DpressException("The builder of the query '$name' didn't return a Query.");
        }
        $this->events->emit(self::eventName($name), [$query, $context]);
        $this->events->emit(self::EVENT_CREATED, [$name, $query, $context]);
        return $query;
    }
}
