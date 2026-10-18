<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CompleteCredentialSetupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class CredentialSetupController extends Controller
{
    public function edit(Request $request): RedirectResponse|View
    {
        if (! $request->user()->must_change_credentials) {
            return to_route('dashboard');
        }

        return view('auth.change-credentials', ['account' => $request->user()]);
    }

    public function update(CompleteCredentialSetupRequest $request): RedirectResponse
    {
        $account = $request->user();
        $account->username = $request->validated('username');
        $account->password = $request->validated('password');
        $account->must_change_credentials = false;
        $account->setRememberToken(Str::random(60));
        $account->save();
        $request->session()->regenerate();

        return to_route('dashboard')->with('status', 'Your username and password have been updated.');
    }
}
