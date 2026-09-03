<?php

namespace Dynart\Dpress\Form;

use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\MenuItem;
use Dynart\Dpress\Entity\User;
use Dynart\Dpress\Form\Validator\EmailValidator;
use Dynart\Dpress\Form\Validator\MatchFieldValidator;
use Dynart\Dpress\Form\Validator\MinLengthValidator;
use Dynart\Dpress\Security\PasswordHasher;

/**
 * The form builders of the admin
 *
 * Separate from `CoreForms`, which holds the ones a visitor sees. Every one of these goes
 * through `FormFactory`, so a plugin can add a field to any admin screen and it renders without
 * a template change - which is the whole reason the admin is server rendered.
 */
class AdminForms {

    const CONTENT = 'admin_content';
    const CATEGORY = 'admin_category';
    const TAG = 'admin_tag';
    const MEDIA = 'admin_media';
    const USER = 'admin_user';
    const ROLE = 'admin_role';
    const SETTINGS = 'admin_settings';
    const MENU = 'admin_menu';
    const MENU_ITEM = 'admin_menu_item';
    const BLOCK = 'admin_block';
    const UPLOAD = 'admin_upload';

    /**
     * The form behind every row action
     *
     * It has no fields of its own - it exists for its CSRF token. A delete or a publish is a
     * POST, and one hidden form per page is what a row action submits, so a link somebody plants
     * elsewhere cannot delete a post on this site.
     */
    const ACTION = 'admin_action';

    public static function register(FormFactory $factory): void {
        $factory->add(self::CONTENT, [self::class, 'content']);
        $factory->add(self::CATEGORY, [self::class, 'category']);
        $factory->add(self::TAG, [self::class, 'tag']);
        $factory->add(self::MEDIA, [self::class, 'media']);
        $factory->add(self::USER, [self::class, 'user']);
        $factory->add(self::ROLE, [self::class, 'role']);
        $factory->add(self::SETTINGS, [self::class, 'settings']);
        $factory->add(self::MENU, [self::class, 'menu']);
        $factory->add(self::MENU_ITEM, [self::class, 'menuItem']);
        $factory->add(self::BLOCK, [self::class, 'block']);
        $factory->add(self::UPLOAD, [self::class, 'upload']);
        $factory->add(self::ACTION, [self::class, 'action']);
    }

    /**
     * @param array $context content, categories, media, is_page
     */
    public function content(DpressForm $form, array $context): void {
        $content = $context['content'] ?? null;
        $isPage = $context['is_page'] ?? ($content !== null && $content->isPage());

        // The toolbar's insert button exists where the server said this user may see the library.
        // It writes a `media#<id>` reference and nothing else - no id of its own is needed, so it
        // works on a post that has never been saved.
        $markdown = ['type' => 'markdown', 'label' => 'Content',
                     'description' => 'The first line that is only --- separates the lead from the body.'];
        if (!empty($context['can_attach'])) {
            $markdown['attributes'] = ['data-insert-media' => '1'];
        }
        $form->addFields([
            'title'    => ['type' => 'text', 'label' => 'Title'],
            'markdown' => $markdown,
        ]);
        $form->addFields([
            'slug'   => ['type' => 'text', 'label' => 'Slug', 'required' => false,
                         'description' => 'Left empty it is made from the title.'],
        ], false);

        // Only for somebody who may publish this kind of content - the stock editor role holds
        // `post.publish` but not `page.publish`. A select that is ignored on save is the worst
        // of the three options: the screen says "Saved." and the status did not move.
        if ($context['can_publish'] ?? true) {
            $form->addFields([
                'status' => ['type' => 'select', 'label' => 'Status', 'required' => false, 'options' => [
                    Content::STATUS_DRAFT     => 'Draft',
                    Content::STATUS_PUBLISHED => 'Published',
                ]],
            ], false);
        }

        $form->addFields([
            'featured_media_id' => ['type' => 'media', 'label' => 'Featured image', 'required' => false,
                                    'preview' => (string)($context['featured_preview'] ?? '')],
        ], false);

        if ($isPage) {
            $form->addFields([
                'parent_id' => ['type' => 'select', 'label' => 'Parent page', 'required' => false,
                                'options' => $context['pages'] ?? [0 => '(none)']],
            ], false);
        } else {
            $form->addFields([
                'categories' => ['type' => 'checkboxes', 'label' => 'Categories', 'required' => false,
                                 'options' => $context['categories'] ?? []],
                'tags'       => ['type' => 'text', 'label' => 'Tags', 'required' => false,
                                 'description' => 'Comma separated. Unknown ones are created.'],
            ], false);
        }

        // An auto-draft is a row that exists so the editor has an id to write against; nothing
        // in it was typed by anybody. So it fills the form the way a post that does not exist
        // would - **especially the slug**, which holds a placeholder nobody chose and which, if
        // it were offered back, would be submitted as if it had been meant and become the URL.
        if ($content !== null && !$content->isAutoDraft()) {
            $form->addValues([
                'title'    => $content->title,
                'markdown' => $content->markdown,
                'slug'     => $content->slug,
                'status'   => $content->status,
                'featured_media_id' => (string)($content->featured_media_id ?? ''),
                'parent_id' => (string)($content->parent_id ?? ''),
                'tags'      => $context['tags'] ?? '',
                'categories' => $context['selected_categories'] ?? [],
            ]);
        } else {
            $form->addValues(['status' => Content::STATUS_DRAFT]);
        }
    }

    public function category(DpressForm $form, array $context): void {
        $category = $context['category'] ?? null;
        $form->addFields(['name' => ['type' => 'text', 'label' => 'Name']]);
        $form->addFields([
            'slug'        => ['type' => 'text', 'label' => 'Slug', 'required' => false],
            'parent_id'   => ['type' => 'select', 'label' => 'Parent', 'required' => false,
                              'options' => $context['categories'] ?? [0 => '(none)']],
            'description' => ['type' => 'textarea', 'label' => 'Description', 'required' => false],
            'position'    => ['type' => 'text', 'label' => 'Position', 'required' => false],
        ], false);
        if ($category !== null) {
            $form->addValues([
                'name' => $category->name, 'slug' => $category->slug,
                'parent_id' => (string)($category->parent_id ?? ''),
                'description' => (string)$category->description,
                'position' => (string)$category->position,
            ]);
        }
    }

    public function tag(DpressForm $form, array $context): void {
        $tag = $context['tag'] ?? null;
        $form->addFields(['name' => ['type' => 'text', 'label' => 'Name']]);
        $form->addFields(['slug' => ['type' => 'text', 'label' => 'Slug', 'required' => false]], false);
        if ($tag !== null) {
            $form->addValues(['name' => $tag->name, 'slug' => $tag->slug]);
        }
    }

    public function media(DpressForm $form, array $context): void {
        $media = $context['media'] ?? null;
        $form->addFields([
            'title'   => ['type' => 'text', 'label' => 'Title', 'required' => false],
            'alt'     => ['type' => 'text', 'label' => 'Alt text', 'required' => false,
                          'description' => 'What the image shows, for somebody who cannot see it. Leave empty if it is decorative.'],
            'caption' => ['type' => 'textarea', 'label' => 'Caption', 'required' => false],
        ], false);
        if ($media !== null) {
            $form->addValues([
                'title' => (string)$media->title,
                'alt' => (string)$media->alt,
                'caption' => (string)$media->caption,
            ]);
        }
    }

    public function upload(DpressForm $form, array $context): void {
        $form->addFields(['file' => ['type' => 'file', 'label' => 'File']]);
    }

    /**
     * Nothing but the CSRF field the base class adds
     */
    public function action(DpressForm $form, array $context): void {}

    public function user(DpressForm $form, array $context): void {
        $user = $context['user'] ?? null;
        $form->addFields([
            'name'  => ['type' => 'text', 'label' => 'Name'],
            'email' => ['type' => 'text', 'label' => 'Email'],
        ]);
        $form->addFields([
            'status' => ['type' => 'select', 'label' => 'Status', 'required' => false, 'options' => [
                User::STATUS_ACTIVE  => 'Active',
                User::STATUS_PENDING => 'Pending',
                User::STATUS_BLOCKED => 'Blocked',
            ]],
            'roles'    => ['type' => 'checkboxes', 'label' => 'Roles', 'required' => false,
                           'options' => $context['roles'] ?? []],
            'password' => ['type' => 'password', 'label' => 'Password', 'required' => $user === null,
                           'description' => $user !== null ? 'Leave empty to keep the current one.' : ''],
            'password_confirm' => ['type' => 'password', 'label' => 'Password again', 'required' => $user === null],
        ], false);
        $form->addValidator('email', new EmailValidator());
        $form->addValidator('password', new MinLengthValidator(PasswordHasher::MIN_LENGTH));
        $form->addValidator('password_confirm', new MatchFieldValidator('password', 'The two passwords do not match.'));
        if ($user !== null) {
            $form->addValues([
                'name' => $user->name, 'email' => $user->email, 'status' => $user->status,
                'roles' => $context['selected_roles'] ?? [],
            ]);
        } else {
            $form->addValues(['status' => User::STATUS_ACTIVE]);
        }
    }

    public function role(DpressForm $form, array $context): void {
        $role = $context['role'] ?? null;
        $form->addFields([
            'name'  => ['type' => 'text', 'label' => 'Name',
                        'description' => 'Used in code and in the CLI. Lowercase, no spaces.'],
            'label' => ['type' => 'text', 'label' => 'Label'],
        ]);
        $form->addFields([
            'permissions' => ['type' => 'permissions', 'label' => 'Permissions', 'required' => false,
                              'groups' => $context['permission_groups'] ?? []],
        ], false);
        if ($role !== null) {
            $form->addValues([
                'name' => $role->name, 'label' => $role->label,
                'permissions' => $context['selected_permissions'] ?? [],
            ]);
        }
    }

    public function settings(DpressForm $form, array $context): void {
        $form->addFields([
            'site_name' => ['type' => 'text', 'label' => 'Site name'],
        ]);
        $form->addFields([
            'site_description'  => ['type' => 'textarea', 'label' => 'Description', 'required' => false],
            'site_logo'         => ['type' => 'media', 'label' => 'Logo', 'required' => false,
                                    'preview' => (string)($context['site_logo_preview'] ?? ''),
                                    'description' => 'Shown instead of the site name, with the site name as its alt '
                                        .'text. Removed, the built-in one comes back.'],
            'site_icon'         => ['type' => 'media', 'label' => 'Icon', 'required' => false,
                                    'preview' => (string)($context['site_icon_preview'] ?? ''),
                                    'description' => 'The icon in the browser tab. Same again.'],
            'registration_open' => ['type' => 'checkbox', 'label' => 'Registration', 'required' => false,
                                    'text' => 'Anybody may create an account'],
            'posts_per_page'    => ['type' => 'text', 'label' => 'Posts per page', 'required' => false],
            'featured_tag'      => ['type' => 'text', 'label' => 'Featured tag', 'required' => false,
                                    'description' => 'Posts with this tag go to the top of the front page, '
                                        .'and are left out of the list below it. Empty for none.'],
            'code_theme'        => ['type' => 'select', 'label' => 'Code theme', 'required' => false,
                                    'options' => $context['code_themes'] ?? [],
                                    'description' => 'Colours the fenced code blocks. Off loads no script at all.'],
            'theme'             => ['type' => 'select', 'label' => 'Theme', 'required' => false,
                                    'options' => $context['themes'] ?? []],
        ], false);
        $form->addValues($context['values'] ?? []);
    }

    public function menu(DpressForm $form, array $context): void {
        $menu = $context['menu'] ?? null;
        $form->addFields(['name' => ['type' => 'text', 'label' => 'Name']]);
        $form->addFields([
            'place' => ['type' => 'select', 'label' => 'Place', 'required' => false,
                        'options' => $context['places'] ?? ['' => '(not placed)'],
                        'description' => 'The places the active theme renders. One menu per place.'],
        ], false);
        if ($menu !== null) {
            $form->addValues(['name' => $menu->name, 'place' => $menu->place]);
        }
    }

    /**
     * The block editor: what every block has, then what its type asked for
     *
     * The type is **not** a field. It is fixed when the block is made, because changing it would
     * leave a row holding one type's settings under another type's name - and "make a different
     * block" is a clearer thing to offer than a select that silently empties.
     *
     * The type's own fields come from `Blocks::fields()` and are merged in here, so a plugin's
     * block gets a real editor without touching a template. They are named `settings[<name>]`,
     * which is what keeps one type's field out of another's stored settings.
     */
    public function block(DpressForm $form, array $context): void {
        $form->addFields([
            'title' => ['type' => 'text', 'label' => 'Title', 'required' => false,
                        'description' => 'The heading above it. Leave it empty for no heading.'],
        ]);
        foreach ((array)($context['fields'] ?? []) as $name => $field) {
            $form->addFields([self::blockSettingName($name) => $field], false);
        }
        $form->addFields([
            'place'   => ['type' => 'select', 'label' => 'Place', 'required' => false,
                          'options' => $context['places'] ?? ['' => '(not placed)'],
                          'description' => 'The places the active theme renders.'],
            'enabled' => ['type' => 'checkbox', 'label' => 'Enabled', 'required' => false,
                          'text' => 'Render this block'],
        ], false);
        $form->addValues($context['values'] ?? []);
    }

    /** How a type's setting is named in the form, so two types cannot collide */
    public static function blockSettingName(string $name): string {
        return 'settings_'.$name;
    }

    public function menuItem(DpressForm $form, array $context): void {
        $item = $context['item'] ?? null;
        $form->addFields([
            'label'       => ['type' => 'text', 'label' => 'Label'],
            'target_type' => ['type' => 'select', 'label' => 'Points at', 'options' => [
                MenuItem::TARGET_HOME     => 'The front page',
                MenuItem::TARGET_CONTENT  => 'A post or page',
                MenuItem::TARGET_CATEGORY => 'A category',
                MenuItem::TARGET_TAG      => 'A tag',
                MenuItem::TARGET_URL      => 'An external address',
            ]],
        ]);
        $form->addFields([
            'target_id' => ['type' => 'select', 'label' => 'Target', 'required' => false,
                            'options' => $context['targets'] ?? []],
            'url'       => ['type' => 'text', 'label' => 'Address', 'required' => false,
                            'description' => 'Only for an external address.'],
            'parent_id' => ['type' => 'select', 'label' => 'Under', 'required' => false,
                            'options' => $context['items'] ?? ['' => '(top level)']],
            'position'  => ['type' => 'text', 'label' => 'Position', 'required' => false],
        ], false);
        if ($item !== null) {
            $form->addValues([
                'label' => $item->label, 'target_type' => $item->target_type,
                'target_id' => (string)($item->target_id ?? ''), 'url' => (string)$item->url,
                'parent_id' => (string)($item->parent_id ?? ''), 'position' => (string)$item->position,
            ]);
        } else {
            $form->addValues(['target_type' => MenuItem::TARGET_CONTENT]);
        }
    }
}
