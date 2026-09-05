<?php

namespace Dynart\Dpress\Cli;

use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Role;
use Dynart\Dpress\Entity\User;
use Dynart\Dpress\Security\PasswordHasher;
use Dynart\Dpress\Service\RoleService;
use Dynart\Dpress\Service\UserService;

/**
 * The user and role commands
 *
 * These exist mainly so a site can be bootstrapped before there is any way to log in, and so a
 * locked out administrator has a way back that does not involve editing the database by hand.
 */
class UserCommands extends AbstractCommands {

    public function __construct(
        CliOutputInterface $output,
        protected UserService $users,
        protected RoleService $roles,
    ) {
        parent::__construct($output);
    }

    /**
     * `dpress user:create -email x -password y -name z -role admin`
     */
    public function create(array $params = []): int {
        $email = $this->param($params, 'email');
        if ($email === '') {
            return $this->fail('An -email is required.');
        }
        $password = $this->param($params, 'password');
        $generated = false;
        if ($password === '') {
            $password = $this->generatePassword();
            $generated = true;
        }
        $roleName = $this->param($params, 'role', Role::NAME_ADMIN);
        try {
            $user = $this->users->create(
                $email,
                $password,
                $this->param($params, 'name'),
                [$roleName],
                User::STATUS_ACTIVE
            );
        } catch (DpressException $e) {
            return $this->fail($e->getMessage());
        }
        $this->success("Created user #{$user->id} <{$user->email}> with the role '$roleName'.");
        if ($generated) {
            $this->output->writeLine('Generated password: '.$password);
            $this->output->writeLine('Write it down, it is not stored anywhere in plain text.');
        }
        return 0;
    }

    /**
     * `dpress user:password -email x [-password y]`
     */
    public function password(array $params = []): int {
        $email = $this->param($params, 'email');
        if ($email === '') {
            return $this->fail('An -email is required.');
        }
        $user = $this->users->findByEmail($this->users->normalizeEmail($email));
        if ($user === null) {
            return $this->fail("There is no user with the email address '$email'.");
        }
        $password = $this->param($params, 'password');
        $generated = false;
        if ($password === '') {
            $password = $this->generatePassword();
            $generated = true;
        }
        try {
            $this->users->setPassword($user, $password);
        } catch (DpressException $e) {
            return $this->fail($e->getMessage());
        }
        $this->success("Password changed for <{$user->email}>.");
        if ($generated) {
            $this->output->writeLine('Generated password: '.$password);
        }
        return 0;
    }

    /**
     * `dpress user:list [-status active] [-search joe]`
     */
    public function listUsers(array $params = []): int {
        $context = [];
        foreach (['status', 'search'] as $key) {
            $value = $this->param($params, $key);
            if ($value !== '') {
                $context[$key] = $value;
            }
        }
        $rows = $this->users->findAll($context);
        if (empty($rows)) {
            $this->output->writeLine('No users.');
            return 0;
        }
        foreach ($rows as $row) {
            $roles = join(', ', $this->users->roleNames((int)$row['id']));
            $this->output->setColor(CliOutput::CYAN);
            $this->output->write(str_pad('#'.$row['id'], 6));
            $this->output->setColor(null);
            $this->output->write(str_pad($row['email'], 34));
            $this->output->write(str_pad($row['status'], 10));
            $this->output->writeLine($roles);
        }
        $this->output->writeLine(count($rows).' user(s).');
        return 0;
    }

    /**
     * `dpress user:status -email x -status active`
     *
     * Registration creates a pending user, so without this there is no way to activate one
     * short of editing the database by hand.
     */
    public function status(array $params = []): int {
        $email = $this->param($params, 'email');
        $status = $this->param($params, 'status');
        if ($email === '' || $status === '') {
            return $this->fail('Both -email and -status are required. One of: '.join(', ', User::STATUSES));
        }
        $user = $this->users->findByEmail($this->users->normalizeEmail($email));
        if ($user === null) {
            return $this->fail("There is no user with the email address '$email'.");
        }
        try {
            $this->users->setStatus($user, $status);
        } catch (DpressException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success("<{$user->email}> is now '$status'.");
    }

    /**
     * `dpress user:role -email x -role editor [-revoke]`
     */
    public function role(array $params = []): int {
        $email = $this->param($params, 'email');
        $roleName = $this->param($params, 'role');
        if ($email === '' || $roleName === '') {
            return $this->fail('Both -email and -role are required.');
        }
        $user = $this->users->findByEmail($this->users->normalizeEmail($email));
        if ($user === null) {
            return $this->fail("There is no user with the email address '$email'.");
        }
        try {
            if ($this->flag($params, 'revoke')) {
                $this->users->revokeRole($user, $roleName);
                $this->success("Revoked '$roleName' from <{$user->email}>.");
            } else {
                $this->users->grantRole($user, $roleName);
                $this->success("Granted '$roleName' to <{$user->email}>.");
            }
        } catch (DpressException $e) {
            return $this->fail($e->getMessage());
        }
        return 0;
    }

    /**
     * `dpress user:delete -email x -confirm`
     *
     * The account and its roles and tokens; not what the person wrote. `UserService::delete()`
     * refuses while they still own a post or a media item, because `author_id` and
     * `uploaded_by` are not null foreign keys - so the alternative to refusing is deleting
     * somebody's work along with their login.
     *
     * `-confirm`, like `media:purge`, because it is the other operation here that takes
     * something away for good: the audit history keeps every change the account ever made,
     * and the account itself does not come back.
     */
    public function delete(array $params = []): int {
        $email = $this->param($params, 'email');
        if ($email === '') {
            return $this->fail('-email is required.');
        }
        $user = $this->users->findByEmail($this->users->normalizeEmail($email));
        if ($user === null) {
            return $this->fail("There is no user with the email address '$email'.");
        }
        if (!$this->flag($params, 'confirm')) {
            return $this->describe($user);
        }
        try {
            $this->users->delete($user);
        } catch (DpressException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success("Deleted <{$user->email}>. What they wrote is untouched.");
    }

    /**
     * What the account is, before anybody says to take it away
     *
     * The roles matter here more than they look: revoking the last administrator is the one
     * refusal somebody is likely to hit, and reading "admin" in this list is what makes the
     * refusal make sense when it comes.
     */
    protected function describe(User $user): int {
        $this->output->setColor(CliOutput::YELLOW);
        $this->output->writeLine('This deletes the account. It does not come back.');
        $this->output->setColor(null);
        $roles = $this->users->roleNames($user->id);
        $this->output->writeLine("  #{$user->id}  {$user->name} <{$user->email}>");
        $this->output->writeLine('  '.$user->status.', '
            .($roles === [] ? 'no roles' : join(', ', $roles)));
        $owned = $this->users->ownedRows($user->id);
        if ($owned !== []) {
            $this->output->writeLine('  '.UserService::describeOwned($owned));
        }
        $this->output->writeLine('');
        if ($owned !== []) {
            $this->output->writeLine('What they wrote stays, which is why this will be refused:');
            $this->output->writeLine('give it to somebody else first, with the Author box in the');
            $this->output->writeLine('post editor.');
            $this->output->writeLine('');
            return 1;
        }
        $this->output->writeLine('Their roles and tokens go with it. The audit history of what they');
        $this->output->writeLine('changed is kept.');
        $this->output->writeLine('');
        $this->output->writeLine('Add -confirm if that is what you want.');
        return 1;
    }

    /**
     * `dpress role:list`
     */
    public function listRoles(array $params = []): int {
        $rows = $this->roles->findAll();
        foreach ($rows as $row) {
            $permissions = $this->roles->permissionsOf((int)$row['id']);
            $this->output->setColor(CliOutput::CYAN);
            $this->output->write(str_pad($row['name'], 14));
            $this->output->setColor(null);
            $this->output->write(str_pad($row['label'], 20));
            if ($row['name'] === Role::NAME_ADMIN) {
                $this->output->writeLine('(every permission)');
            } else {
                $this->output->writeLine($permissions ? join(', ', $permissions) : '-');
            }
        }
        return 0;
    }

    protected function generatePassword(): string {
        return substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(24))), 0, 16);
    }

}
