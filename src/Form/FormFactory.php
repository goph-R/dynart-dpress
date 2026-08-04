<?php

namespace Dynart\Dpress\Form;

use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\Micro;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\SessionInterface;
use Dynart\Micro\TranslationInterface;
use Dynart\Dpress\DpressException;

/**
 * Builds every form of the CMS by name, and announces it
 *
 * A form built here is handed to the event subscribers before it is returned, so a plugin can
 * add fields and validators to a form it did not write. `Form::fields()` is data and
 * `Form::fetch()` renders it generically, so an added field appears without any template change
 * - **as long as the template calls `$form->fetch()` instead of hand writing `<input>` tags**.
 * Hand written markup silently breaks every plugin.
 */
class FormFactory {

    /** The namespace of the per form events */
    const EVENT_NAMESPACE = 'form';

    /** Emitted for every form, with the name as its first argument */
    const EVENT_CREATED = 'form:created';

    /** @var array<string, callable|array> */
    protected array $builders = [];

    public function __construct(
        protected RequestInterface $request,
        protected SessionInterface $session,
        protected EventServiceInterface $events,
        protected TranslationInterface $translation,
    ) {}

    /**
     * Registers a builder for a form name
     *
     * The builder receives the form and the context, and adds fields and validators to it. It
     * does not create the form: the name has to be set at construction, because it is part of
     * the CSRF session key and of every input name.
     *
     * @param string $name The form name, snake_case with no dots (see Dpress conventions)
     * @param callable|array $builder A Micro callable: `[SomeClass::class, 'method']` or a closure
     */
    public function add(string $name, callable|array $builder): void {
        self::registerBuilderClass($builder);
        $this->builders[$name] = $builder;
    }

    /**
     * Registers a `[SomeClass::class, 'method']` builder's class in the DI container
     *
     * The builder is resolved through the container when the form is created, so registering it
     * here saves every caller from remembering a second `Micro::add()` - the same thing
     * `Migrations::add()` does for migrations.
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
     * @return string[] Every registered form name
     */
    public function names(): array {
        return array_keys($this->builders);
    }

    /**
     * Returns with the scoped event name of a form, for example `form.user_login:created`
     */
    public static function eventName(string $name, string $event = 'created'): string {
        return self::EVENT_NAMESPACE.'.'.$name.':'.$event;
    }

    /**
     * Builds a form and lets the subscribers change it
     *
     * Both a scoped and a generic event are emitted: `EventService` matches event names exactly
     * with no wildcards, so a generic only event would wake every subscriber on every form. The
     * generic one is for the genuinely cross cutting cases - a captcha or a honeypot on all of
     * them.
     *
     * @throws DpressException if there is no such form
     */
    public function create(string $name, array $context = [], bool $csrf = true): DpressForm {
        if (!isset($this->builders[$name])) {
            throw new DpressException("There is no form named '$name'.");
        }
        $form = new DpressForm($this->request, $this->session, $name, $csrf);
        $form->setTranslation($this->translation);
        $form->setEvents($this->events);
        $form->setContext($context);
        call_user_func(Micro::getCallable($this->builders[$name]), $form, $context);
        $this->events->emit(self::eventName($name), [$form, $context]);
        $this->events->emit(self::EVENT_CREATED, [$name, $form, $context]);
        return $form;
    }
}
