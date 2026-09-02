<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Block\Blocks;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Block;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\DpressForm;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\BlockService;
use Dynart\Dpress\Theme\ThemeService;

/**
 * What a site puts beside its content
 *
 * One screen, a table per place, because **where a block is** is the thing somebody is looking at
 * when they open this - not a list of blocks with a Place column to read down. Dragging reorders
 * within a place; moving between places is the select in the editor, since dragging a row from one
 * table into another is a gesture nobody has ever guessed at.
 *
 * The last table is the blocks in no place at all, or in one the active theme stopped rendering
 * after a switch. Same answer as a menu in that position: invisible on the site, listed here,
 * fixable in one click.
 */
class BlockAdminController extends AbstractAdminController {

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        FormFactory $forms,
        ListRequest $list,
        protected BlockService $service,
        protected Blocks $blocks,
        protected ThemeService $themes,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth, $forms, $list);
    }

    protected function section(): string {
        return 'blocks';
    }

    #[Route('GET', '/admin/blocks')]
    public function index(): string {
        $this->requirePermission(Permissions::BLOCK_VIEW);
        $canEdit = $this->can(Permissions::BLOCK_UPDATE);
        $rowActions = [];
        if ($canEdit) {
            $rowActions[] = ['title' => 'Delete', 'class' => 'delete', 'post' => 'delete_url',
                             'icon' => $this->icon('delete'),
                             'confirm' => 'Delete this block?'];
        }
        return $this->admin('dpress_admin:block/list', [
            'title'    => 'Blocks',
            'tables'   => $this->tables($canEdit),
            'columns'  => [
                'title' => ['label' => 'Block', 'link' => $canEdit ? 'edit_url' : ''],
                'type'  => ['label' => 'Type'],
                'state' => ['label' => 'State', 'width' => '1%'],
            ],
            'row_actions' => $rowActions,
            'can_edit'    => $canEdit,
            'new_url'     => $this->router->url('/admin/blocks/new'),
            'drag_icon'   => $this->icon('drag'),
        ]);
    }

    /**
     * One table per place, then the unplaced ones
     *
     * Every place the theme declares gets a table even when it is empty, because an empty place
     * is a thing somebody wants to see - it is where a block can go.
     */
    protected function tables(bool $canEdit): array {
        $byPlace = [];
        foreach ($this->service->all() as $row) {
            $byPlace[(string)$row['place']][] = $this->row($row, $canEdit);
        }
        $tables = [];
        foreach ($this->themes->places() as $place => $label) {
            $tables[] = [
                'place'    => $place,
                'label'    => $label,
                'rows'     => $byPlace[$place] ?? [],
                'move_url' => $canEdit ? $this->router->url('/admin/blocks/move/') : '',
                'empty'    => 'Nothing here yet.',
            ];
            unset($byPlace[$place]);
        }
        $orphans = [];
        foreach ($byPlace as $rows) {
            $orphans = array_merge($orphans, $rows);
        }
        if ($orphans !== []) {
            $tables[] = [
                'place'    => '',
                'label'    => 'Not rendered',
                'rows'     => $orphans,
                // no dragging: the order of things that are not being rendered is not a question
                'move_url' => '',
                'empty'    => '',
            ];
        }
        return $tables;
    }

    protected function row(array $row, bool $canEdit): array {
        $type = (string)$row['type'];
        return [
            'id'         => (int)$row['id'],
            'parent_id'  => null,
            'depth'      => 0,
            'title'      => (string)$row['title'] !== '' ? (string)$row['title'] : $this->blocks->title($type),
            'type'       => $this->blocks->title($type),
            'state'      => empty($row['enabled']) ? 'Off' : '',
            'class'      => empty($row['enabled']) ? 'is-disabled' : '',
            'edit_url'   => $canEdit ? $this->router->url('/admin/blocks/edit/'.(int)$row['id']) : '',
            'delete_url' => $canEdit ? $this->router->url('/admin/blocks/delete/'.(int)$row['id']) : '',
        ];
    }

    /**
     * The kinds of block there are, as a chooser
     *
     * A step rather than a select on the create form, because the fields a block has depend on its
     * type: a select would have to rebuild the form under somebody as they changed it, and the one
     * thing a form must not do is lose what was typed into it.
     */
    #[Route('GET', '/admin/blocks/new')]
    public function chooser(): string {
        $this->requirePermission(Permissions::BLOCK_UPDATE);
        $types = [];
        foreach ($this->blocks->titles() as $type => $label) {
            $types[] = ['type' => $type, 'label' => $label,
                        'url' => $this->router->url('/admin/blocks/new/'.$type)];
        }
        return $this->admin('dpress_admin:block/types', [
            'title'    => 'Add a block',
            'types'    => $types,
            'back_url' => $this->router->url('/admin/blocks'),
        ]);
    }

    #[Route('GET', '/admin/blocks/new/?')]
    #[Route('POST', '/admin/blocks/new/?')]
    public function create(string $type): string {
        $this->requirePermission(Permissions::BLOCK_UPDATE);
        // a type nobody registered is not a page: the same 404 a missing row gets, for the same
        // reason - the URL names something that does not exist
        $this->found($this->blocks->has($type) ? $type : null);
        $form = $this->forms->create(AdminForms::BLOCK, $this->context($type));
        if ($form->process()) {
            $form->handle(function (DpressForm $form) use ($type) {
                $this->service->create($type, $this->data($type, $form->values()));
            });
            $this->done('/admin/blocks', 'Added.');
        }
        return $this->editor($form, 'New '.$this->blocks->title($type).' block');
    }

    #[Route('GET', '/admin/blocks/edit/?')]
    #[Route('POST', '/admin/blocks/edit/?')]
    public function edit(string $id): string {
        $this->requirePermission(Permissions::BLOCK_UPDATE);
        $block = $this->found($this->service->find((int)$id));
        $form = $this->forms->create(AdminForms::BLOCK, $this->context($block->type, $block));
        if ($form->process()) {
            $form->handle(function (DpressForm $form) use ($block) {
                $this->service->update($block, $this->data($block->type, $form->values()));
            });
            $this->done('/admin/blocks', 'Saved.');
        }
        return $this->editor($form, $block->title !== '' ? $block->title : $this->blocks->title($block->type));
    }

    #[Route('POST', '/admin/blocks/delete/?')]
    public function delete(string $id): string {
        $this->requirePermission(Permissions::BLOCK_UPDATE);
        $this->requireAction();
        $block = $this->found($this->service->find((int)$id));
        $this->service->delete($block);
        $this->done('/admin/blocks', 'Deleted.');
        return '';
    }

    /**
     * A drop: the same answer shape the tree screens give, so one piece of browser code serves all
     *
     * `parent_id` arrives and is ignored - a place is a list, and the table it came from is marked
     * flat so nothing ever sends one that is not empty.
     */
    #[Route('POST', '/admin/blocks/move/?')]
    public function move(string $id): array {
        $this->requirePermission(Permissions::BLOCK_UPDATE);
        $this->requireAction();
        $block = $this->found($this->service->find((int)$id));
        try {
            $this->service->move($block, (int)$this->request->get('position', 0));
        } catch (DpressException $e) {
            return $this->answer(['error' => $e->getMessage()]);
        }
        return $this->answer();
    }

    /**
     * What the form is built from: the type's fields, the places, and the values of an existing row
     */
    protected function context(string $type, ?Block $block = null): array {
        $fields = $this->blocks->fields($type);
        $values = ['enabled' => $block === null ? '1' : ($block->enabled ? '1' : '')];
        if ($block !== null) {
            $values['title'] = $block->title;
            $values['place'] = $block->place;
        }
        $settings = $block === null ? [] : $block->settings();
        foreach (array_keys($fields) as $name) {
            $values[AdminForms::blockSettingName($name)] = (string)($settings[$name] ?? '');
        }
        return [
            'fields' => $fields,
            'places' => ['' => '(not placed)'] + $this->themes->places(),
            'values' => $values,
        ];
    }

    /**
     * Form values back into what the service takes
     *
     * Only the fields this type declared are read, so a setting left over from a plugin that has
     * been switched off does not travel back into the row on the next save.
     */
    protected function data(string $type, array $values): array {
        $settings = [];
        foreach (array_keys($this->blocks->fields($type)) as $name) {
            $settings[$name] = $values[AdminForms::blockSettingName($name)] ?? '';
        }
        return [
            'title'    => (string)($values['title'] ?? ''),
            'place'    => (string)($values['place'] ?? ''),
            'enabled'  => !empty($values['enabled']),
            'settings' => $settings,
        ];
    }

    protected function editor(DpressForm $form, string $title): string {
        return $this->admin('dpress_admin:block/edit', [
            'title'    => $title,
            'form'     => $form,
            'back_url' => $this->router->url('/admin/blocks'),
        ]);
    }
}
