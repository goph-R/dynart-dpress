<?php

namespace Dynart\Dpress\Form;

use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\Form;
use Dynart\Micro\Micro;
use Dynart\Micro\ViewInterface;

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

    /** The template of one expandable section - see `fetch()` */
    const VIEW_SECTION = 'dpress:form-section';

    /**
     * The fields, with the ones that name a `section` folded away under it
     *
     * A field joins a section by saying so - `'section' => 'Advanced'` in its own data - and
     * that is the whole of the API: nothing is registered first, so a plugin adding a field to
     * somebody else's form puts it in a section, an existing one or a new one, with one key.
     *
     * **The loose fields first, then each section** in the order its first field was added. Not
     * wherever that first field happened to be, because a section is where the things somebody
     * rarely needs are kept, and those go below the ones they need every time - and a plugin's
     * field, added last, would otherwise decide where a section of the core's fields appears.
     *
     * A section is closed until somebody opens it, **unless one of its fields has an error**: a
     * message folded away where nobody can see it is a form that refuses to save and will not
     * say why.
     */
    public function fetch(): string {
        $loose = [];
        $sections = [];
        foreach ($this->fields() as $name => $field) {
            $section = trim((string)($field['section'] ?? ''));
            if ($section === '') {
                $loose[$name] = $field;
            } else {
                $sections[$section][$name] = $field;
            }
        }
        $html = $this->fetchErrors().$this->fetchFields($loose);
        foreach ($sections as $label => $fields) {
            $html .= $this->fetchSection((string)$label, $fields);
        }
        return $html;
    }

    /** The class of the element that puts a row of fields side by side */
    const ROW_CLASS = 'form-row';

    /**
     * Fields in order, with the ones that share a `row` put side by side
     *
     * A field joins a row the way it joins a section, by naming it - `'row' => 'publication'` -
     * and the fields **next to each other** that name the same row are one row. Next to each
     * other, because a row is something seen: two fields with a third between them are not beside
     * each other on any screen, whatever they are called. Side by side on a wide screen and one
     * under the other on a narrow one; the stylesheet decides where the line is.
     *
     * A row of one field is just the field, so a row whose partner is missing - the publication
     * date without the status, for somebody who may not publish - costs no wrapper.
     *
     * @param array $fields [name => field] in the order they were added
     */
    public function fetchFields(array $fields): string {
        $html = '';
        $group = [];
        $groupRow = '';
        $flush = function () use (&$html, &$group) {
            if (count($group) > 1) {
                $html .= '<div class="'.self::ROW_CLASS.'">'.implode('', $group).'</div>';
            } else {
                $html .= implode('', $group);
            }
            $group = [];
        };
        foreach ($fields as $name => $field) {
            $row = trim((string)($field['row'] ?? ''));
            if ($row === '' || $row !== $groupRow) {
                $flush();
            }
            $groupRow = $row;
            $group[] = $this->fetchField($name, $field);
            if ($row === '') {
                $flush();
            }
        }
        $flush();
        return $html;
    }

    /**
     * One section and the fields inside it
     *
     * @param array $fields [name => field] in the order they were added
     */
    public function fetchSection(string $label, array $fields): string {
        $inner = $this->fetchFields($fields);
        $open = false;
        foreach (array_keys($fields) as $name) {
            $open = $open || $this->error($name) !== null;
        }
        return $this->view()->fetch(self::VIEW_SECTION, [
            'form'   => $this,
            'label'  => $label,
            'id'     => $this->inputId('section_'.trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_')),
            'open'   => $open,
            'fields' => $inner,
        ]);
    }

    /** The view a section is rendered with - a method so a test can hand it another */
    protected function view(): ViewInterface {
        return Micro::get(ViewInterface::class);
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
