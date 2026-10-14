<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAuditorDetailsRequest;
use App\Http\Requests\UpdateAuditorPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class AuditorAccountController extends Controller
{
    public function edit(Request $request): View
    {
        return view('auditor-account', ['account' => $request->user()]);
    }

    public function updateDetails(UpdateAuditorDetailsRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return to_route('auditor-account.edit')
            ->with('status', 'Your account details were updated successfully.');
    }

    public function updatePassword(UpdateAuditorPasswordRequest $request): RedirectResponse
    {
        $account = $request->user();
        $account->password = $request->validated('password');
        $account->setRememberToken(Str::random(60));
        $account->save();

        return to_route('auditor-account.edit')
            ->with('status', 'Your password was updated successfully.');
    }
}
