<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Secure your account | Tabulation System</title>
    <link rel="icon" type="image/png" href="/images/um_logo.png">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 font-sans text-slate-900 antialiased">
    <main class="flex min-h-screen items-center justify-center px-4 py-10 sm:px-6">
        <section class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl sm:p-10" aria-labelledby="setup-heading">
            <img src="{{ asset('images/um_logo.png') }}" alt="UM Digos College logo" width="48" height="48" class="h-12 w-12 object-contain">
            <p class="mt-6 text-sm font-semibold text-maroon-700">First sign-in security</p>
            <h1 id="setup-heading" class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Set your own credentials</h1>
            <p class="mt-3 text-sm leading-6 text-slate-600">Your account credentials were provided by an administrator. Change both your username and password before accessing the system.</p>

            <form method="POST" action="{{ route('credentials.update', [], false) }}" class="mt-8 space-y-5" data-loading-text="Securing your account…">
                @csrf
                @method('PATCH')

                <div>
                    <label for="new_username" class="mb-2 block text-sm font-semibold text-slate-700">New username</label>
                    <input id="new_username" name="username" type="text" value="{{ old('username') }}" maxlength="255" autocomplete="username" required autofocus class="h-11 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm outline-none focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10" placeholder="Choose a different username">
                    <p class="mt-1 text-xs text-slate-500">Current username: {{ $account->username }}</p>
                    @error('username')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="current_password" class="mb-2 block text-sm font-semibold text-slate-700">Current password</label>
                    <div class="relative">
                        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-4 pr-20 text-sm outline-none focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                        <button type="button" data-password-toggle data-password-label="current password" aria-controls="current_password" aria-pressed="false" aria-label="Show current password" class="absolute inset-y-0 right-0 px-4 text-sm font-semibold text-slate-500 hover:text-maroon-700">Show</button>
                    </div>
                    @error('current_password')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="new_password" class="mb-2 block text-sm font-semibold text-slate-700">New password</label>
                    <div class="relative">
                        <input id="new_password" name="password" type="password" minlength="8" autocomplete="new-password" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-4 pr-20 text-sm outline-none focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                        <button type="button" data-password-toggle data-password-label="new password" aria-controls="new_password" aria-pressed="false" aria-label="Show new password" class="absolute inset-y-0 right-0 px-4 text-sm font-semibold text-slate-500 hover:text-maroon-700">Show</button>
                    </div>
                    @error('password')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="password_confirmation" class="mb-2 block text-sm font-semibold text-slate-700">Confirm new password</label>
                    <div class="relative">
                        <input id="password_confirmation" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-4 pr-20 text-sm outline-none focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                        <button type="button" data-password-toggle data-password-label="password confirmation" aria-controls="password_confirmation" aria-pressed="false" aria-label="Show password confirmation" class="absolute inset-y-0 right-0 px-4 text-sm font-semibold text-slate-500 hover:text-maroon-700">Show</button>
                    </div>
                </div>

                <button type="submit" class="h-11 w-full rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white transition hover:bg-maroon-800">Save username and password</button>
            </form>

            <form method="POST" action="{{ route('logout', [], false) }}" class="mt-5 text-center">
                @csrf
                <button type="submit" class="text-sm font-semibold text-slate-500 hover:text-maroon-700">Sign out instead</button>
            </form>
        </section>
    </main>

    <x-loading-indicator />
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
</body>
</html>
