<?php

namespace Dynart\Dpress\Form;

use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\Form;

/**
 * A form that announces its lifecycle
 *
 * Built by `FormFactory`, never with `new` from a service. The factory emits the `created`
 * event, this class emits `validated` from the framework's `afterValidate()` hook, and
 * `handle()` wraps whatever the controller does with the valid values in a before/after pair.
 */
class DpressForm extends Form {

    // The CMS field types - markdown, media, checkboxes, permissions - are registered with
    // `FormWidgets` in `DpressServices::registerWidgets()`, the same call a plugin uses. This
    // class used to point `VIEW_INPUT` at a template holding all four, which worked exactly once:
    // the override was spent, and nothing after the CMS could add a fifth. See micro 0.20.0.

    protected ?EventServiceInterface $events = null;
    protected array $context = [];

    public function setEvents(?EventServiceInterface $events): void {
        $this->events = $events;
    }

    public function setContext(array $context): void {
        $this->context = $context;
    }

    public function context(): array {
        return $this->context;
    }

    /**
     * Returns with the scoped event name of this form, for example `form.user_login:validated`
     */
    public function eventName(string $event): string {
        return FormFactory::eventName($this->name, $event);
    }

    /**
     * Runs the handler for a valid form, wrapped in the before/after process events
     *
     * <pre>
     * if ($form->process()) {
     *     $form->handle(fn($form) => $this->userService->create($form->values()));
     * }
     * </pre>
     *
     * @param callable $handler Receives this form, its return value is passed on
     * @return mixed Whatever the handler returned
     */
    public function handle(callable $handler): mixed {
        $this->emit('before_process', [$this, $this->context]);
        $result = $handler($this);
        $this->emit('after_process', [$this, $result, $this->context]);
        return $result;
    }

    /**
     * Lets the subscribers add their own errors after the built in validation ran
     */
    protected function afterValidate(bool $valid): void {
        $this->emit('validated', [$this, $valid, $this->context]);
    }

    protected function emit(string $event, array $args): void {
        if ($this->events !== null) {
            $this->events->emit($this->eventName($event), $args);
        }
    }
}
