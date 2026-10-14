@extends('layouts.admin')

@section('title', 'My account')
@section('heading', 'My account')

@section('content')
    <div class="mb-6">
        <p class="text-sm font-semibold text-maroon-700">Auditor profile</p>
        <h2 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">Manage your account</h2>
        <p class="mt-2 text-sm text-slate-500">Update your name and username, or change the password you use to sign in.</p>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="account-details-title">
            <div class="border-b border-slate-200 px-6 py-5">
                <h3 id="account-details-title" class="text-lg font-semibold text-slate-950">Account details</h3>
                <p class="mt-1 text-sm text-slate-500">Your role and account status are managed by an administrator.</p>
            </div>

            <form method="POST" action="{{ route('auditor-account.details.update', [], false) }}" class="space-y-5 p-6" data-loading-text="Updating account details…">
                @csrf
                @method('PATCH')

                <div>
                    <label for="account_name" class="mb-1.5 block text-sm font-semibold text-slate-700">Full name</label>
                    <input id="account_name" name="name" type="text" value="{{ old('name', $account->name) }}" maxlength="255" autocomplete="name" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                    @error('name')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="account_username" class="mb-1.5 block text-sm font-semibold text-slate-700">Username</label>
                    <input id="account_username" name="username" type="text" value="{{ old('username', $account->username) }}" maxlength="255" autocomplete="username" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                    @error('username')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="h-11 rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white transition hover:bg-maroon-800">Save details</button>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="account-password-title">
            <div class="border-b border-slate-200 px-6 py-5">
                <h3 id="account-password-title" class="text-lg font-semibold text-slate-950">Change password</h3>
                <p class="mt-1 text-sm text-slate-500">Enter your current password and choose a new password of at least 8 characters.</p>
            </div>

            <form method="POST" action="{{ route('auditor-account.password.update', [], false) }}" class="space-y-5 p-6" data-loading-text="Changing password…">
                @csrf
                @method('PATCH')

                <div>
                    <label for="current_password" class="mb-1.5 block text-sm font-semibold text-slate-700">Current password</label>
                    <div class="relative">
                        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-4 pr-20 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                        <button type="button" data-password-toggle data-password-label="current password" aria-controls="current_password" aria-pressed="false" aria-label="Show current password" class="absolute inset-y-0 right-0 px-4 text-sm font-semibold text-slate-500 transition hover:text-maroon-700">Show</button>
                    </div>
                    @error('current_password')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="new_password" class="mb-1.5 block text-sm font-semibold text-slate-700">New password</label>
                    <div class="relative">
                        <input id="new_password" name="password" type="password" minlength="8" autocomplete="new-password" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-4 pr-20 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                        <button type="button" data-password-toggle data-password-label="new password" aria-controls="new_password" aria-pressed="false" aria-label="Show new password" class="absolute inset-y-0 right-0 px-4 text-sm font-semibold text-slate-500 transition hover:text-maroon-700">Show</button>
                    </div>
                    @error('password')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="password_confirmation" class="mb-1.5 block text-sm font-semibold text-slate-700">Confirm new password</label>
                    <div class="relative">
                        <input id="password_confirmation" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-4 pr-20 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                        <button type="button" data-password-toggle data-password-label="password confirmation" aria-controls="password_confirmation" aria-pressed="false" aria-label="Show password confirmation" class="absolute inset-y-0 right-0 px-4 text-sm font-semibold text-slate-500 transition hover:text-maroon-700">Show</button>
                    </div>
                    @error('password_confirmation')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="h-11 rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white transition hover:bg-maroon-800">Change password</button>
                </div>
            </form>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-password-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = document.getElementById(button.getAttribute('aria-controls'));

                if (!(input instanceof HTMLInputElement)) return;

                const showPassword = input.type === 'password';
                input.type = showPassword ? 'text' : 'password';
                button.textContent = showPassword ? 'Hide' : 'Show';
                button.setAttribute('aria-pressed', String(showPassword));
                button.setAttribute('aria-label', `${showPassword ? 'Hide' : 'Show'} ${button.dataset.passwordLabel}`);
            });
        });
    </script>
@endpush
