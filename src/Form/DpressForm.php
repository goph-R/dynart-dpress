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

    /**
     * The CMS renders its own inputs, so it can offer field types the framework has never heard
     * of - a markdown editor, a media picker, a grouped permission list. Anything it does not
     * recognise falls through to the framework's partial, so nothing has to be duplicated.
     */
    const VIEW_INPUT = 'dpress:form-input';

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
