<?php

namespace Dynart\Dpress\Form;

use Dynart\Dpress\Form\Validator\EmailValidator;
use Dynart\Dpress\Form\Validator\MatchFieldValidator;
use Dynart\Dpress\Form\Validator\MinLengthValidator;
use Dynart\Dpress\Security\PasswordHasher;

/**
 * The form builders the CMS itself registers
 *
 * Every one goes through `FormFactory`, so a plugin can add a field to any of them and it
 * renders without a template change - as long as the template uses `$form->fetch()`.
 */
class CoreForms {

    const LOGIN = 'user_login';
    const REGISTER = 'user_register';
    const FORGOT_PASSWORD = 'user_forgot_password';
    const RESET_PASSWORD = 'user_reset_password';
    const PROFILE = 'user_profile';

    public static function register(FormFactory $factory): void {
        $factory->add(self::LOGIN, [self::class, 'login']);
        $factory->add(self::REGISTER, [self::class, 'registration']);
        $factory->add(self::FORGOT_PASSWORD, [self::class, 'forgotPassword']);
        $factory->add(self::RESET_PASSWORD, [self::class, 'resetPassword']);
        $factory->add(self::PROFILE, [self::class, 'profile']);
    }

    public function login(DpressForm $form, array $context): void {
        $form->addFields([
            'email'    => ['type' => 'text', 'label' => 'Email'],
            'password' => ['type' => 'password', 'label' => 'Password'],
        ]);
        $form->addValidator('email', new EmailValidator());
    }

    /**
     * Named `registration` rather than `register`, which is the static registrar above
     */
    public function registration(DpressForm $form, array $context): void {
        $form->addFields([
            'name'             => ['type' => 'text', 'label' => 'Name'],
            'email'            => ['type' => 'text', 'label' => 'Email'],
            'password'         => ['type' => 'password', 'label' => 'Password'],
            'password_confirm' => ['type' => 'password', 'label' => 'Password again'],
        ]);
        $form->addValidator('email', new EmailValidator());
        $form->addValidator('password', new MinLengthValidator(PasswordHasher::MIN_LENGTH));
        $form->addValidator('password_confirm', new MatchFieldValidator('password', 'The two passwords do not match.'));
    }

    public function forgotPassword(DpressForm $form, array $context): void {
        $form->addFields([
            'email' => ['type' => 'text', 'label' => 'Email'],
        ]);
        $form->addValidator('email', new EmailValidator());
    }

    public function resetPassword(DpressForm $form, array $context): void {
        $form->addFields([
            'token'            => ['type' => 'hidden'],
            'password'         => ['type' => 'password', 'label' => 'New password'],
            'password_confirm' => ['type' => 'password', 'label' => 'New password again'],
        ]);
        $form->addValues(['token' => $context['token'] ?? '']);
        $form->addValidator('password', new MinLengthValidator(PasswordHasher::MIN_LENGTH));
        $form->addValidator('password_confirm', new MatchFieldValidator('password', 'The two passwords do not match.'));
    }

    /**
     * The password fields are optional here: an empty pair means "leave it alone"
     */
    public function profile(DpressForm $form, array $context): void {
        $form->addFields([
            'name'  => ['type' => 'text', 'label' => 'Name'],
            'email' => ['type' => 'text', 'label' => 'Email'],
        ]);
        $form->addFields([
            'password'         => ['type' => 'password', 'label' => 'New password', 'required' => false,
                                   'description' => 'Leave both empty to keep the current one.'],
            'password_confirm' => ['type' => 'password', 'label' => 'New password again', 'required' => false],
        ], false);
        $form->addValidator('email', new EmailValidator());
        $form->addValidator('password', new MinLengthValidator(PasswordHasher::MIN_LENGTH));
        $form->addValidator('password_confirm', new MatchFieldValidator('password', 'The two passwords do not match.'));
        $user = $context['user'] ?? null;
        if ($user !== null) {
            $form->addValues(['name' => $user->name, 'email' => $user->email]);
        }
    }
}
