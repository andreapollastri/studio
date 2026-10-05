<x-layouts::auth :title="__('Forgot password')">
    <div class="flex flex-col gap-6">
        @if (! \App\Support\Mail::configured())
            {{-- A fresh server cannot send email yet: say so instead of promising a link that lands in a log file. --}}
            <x-auth-header :title="__('Forgot password')" :description="__('This Studio does not send email yet, so it cannot mail you a reset link.')" />

            <flux:callout icon="key" variant="secondary">
                <flux:callout.heading>{{ __('Ask an administrator') }}</flux:callout.heading>
                <flux:callout.text>{{ __('From Administration → Users they can give you a new password to log in with; you then choose your own in Settings.') }}</flux:callout.text>
            </flux:callout>
        @else
        <x-auth-header :title="__('Forgot password')" :description="__('Enter your email to receive a password reset link')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                type="email"
                required
                autofocus
                placeholder="email@example.com"
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="email-password-reset-link-button">
                {{ __('Email password reset link') }}
            </flux:button>
        </form>
        @endif

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-400">
            <span>{{ __('Or, return to') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
