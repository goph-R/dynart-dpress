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
        $factory->add(self::UPLOAD, [self::class, 'upload']);
        $factory->add(self::ACTION, [self::class, 'action']);
    }

    /**
     * @param array $context content, categories, media, is_page
     */
    public function content(DpressForm $form, array $context): void {
        $content = $context['content'] ?? null;
        $isPage = $context['is_page'] ?? ($content !== null && $content->isPage());

        // the toolbar's insert button only exists where the server put an endpoint on the field,
        // and it is inactive while that endpoint is empty - which is what a new post has, since
        // there is no id to attach anything to yet
        $markdown = ['type' => 'markdown', 'label' => 'Content',
                     'description' => 'The first line that is only --- separates the lead from the body.'];
        if (!empty($context['can_attach'])) {
            $markdown['attributes'] = ['data-attach-hidden' => (string)($context['attach_url'] ?? '')];
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
            'featured_media_id' => ['type' => 'media', 'label' => 'Featured image', 'required' => false],
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

        if ($content !== null) {
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
            'site_logo'         => ['type' => 'text', 'label' => 'Logo', 'required' => false,
                                    'description' => 'Shown instead of the site name. A path like /static/logo.svg, '
                                        .'or a full URL. The site name stays as its alt text.'],
            'site_icon'         => ['type' => 'text', 'label' => 'Icon', 'required' => false,
                                    'description' => 'The icon in the browser tab. Same idea - /favicon.png.'],
            'registration_open' => ['type' => 'checkbox', 'label' => 'Registration', 'required' => false,
                                    'text' => 'Anybody may create an account'],
            'posts_per_page'    => ['type' => 'text', 'label' => 'Posts per page', 'required' => false],
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
