<?php

namespace App\Livewire\Admin;

use App\Enums\UserRole;
use App\Git\Providers\GitProvider;
use App\Models\Project;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('Users · Administration')]
class Users extends Component
{
    public string $name = '';

    public string $email = '';

    public string $role = 'dev';

    public string $handle = '';

    /** Shown once, right after the account is created or its password reset. */
    public ?string $generatedPassword = null;

    /** Who the generated password belongs to when it is a reset, not a new account. */
    public ?string $resetFor = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
    }

    public function create(): void
    {
        $this->reset(['name', 'email', 'role', 'handle', 'generatedPassword', 'resetFor']);
        $this->role = 'dev';
        $this->resetValidation();

        Flux::modal('user-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(UserRole::class)],
            'handle' => ['nullable', 'string', 'max:'.User::HANDLE_MAX, 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('users', 'handle'), function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && $value !== '' && ($host = Project::siteHostShadowedByHandle($value)) !== null) {
                    $fail(__('The previews of ":handle" would land on the host of an existing project (:host).', ['handle' => $value, 'host' => $host]));
                }
            }],
        ], [
            'handle.regex' => __('A handle becomes a Linux user and part of a host name: lowercase letters, digits and dashes.'),
            'handle.max' => __('A handle becomes a Linux user and part of a host name: :max characters at most.', ['max' => User::HANDLE_MAX]),
        ]);

        $password = Str::password(16, symbols: false);

        User::query()->create([
            'name' => $validated['name'],
            'email' => Str::lower($validated['email']),
            'role' => UserRole::from($validated['role']),
            'handle' => $validated['handle'] ?: null,
            'password' => $password,
            'email_verified_at' => now(),
        ]);

        $this->generatedPassword = $password;

        Flux::toast(variant: 'success', text: __('Account created. Share the initial password once.'));
    }

    public function setRole(User $user, string $role): void
    {
        $role = UserRole::from($role);

        if ($user->is(auth()->user()) && $role !== UserRole::Admin) {
            Flux::toast(variant: 'warning', text: __('You cannot remove your own administrator role.'));

            return;
        }

        $user->forceFill(['role' => $role])->save();

        Flux::toast(text: __(':name is now :role.', ['name' => $user->name, 'role' => $role->label()]));
    }

    /**
     * The way back in for a person who lost their password or their 2FA
     * device, with or without outgoing email: a new password shown once here,
     * handed over out of band. Their sessions close and 2FA is removed.
     */
    public function resetPassword(User $user): void
    {
        if ($user->is(auth()->user())) {
            Flux::toast(variant: 'warning', text: __('Change your own password in Settings.'));

            return;
        }

        $password = Str::password(16, symbols: false);
        $user->resetAccess($password);

        $this->generatedPassword = $password;
        $this->resetFor = $user->name;

        Flux::modal('user-form')->show();
    }

    public function remove(User $user): void
    {
        if ($user->is(auth()->user())) {
            Flux::toast(variant: 'warning', text: __('You cannot delete your own account from here.'));

            return;
        }

        $user->delete();

        Flux::toast(text: __('Account deleted.'));
    }

    public function render(): View
    {
        return view('livewire.admin.users', [
            'users' => User::query()->withCount('projects')->orderBy('name')->get(),
            'roles' => UserRole::cases(),
            'git' => GitProvider::current(),
        ]);
    }
}
