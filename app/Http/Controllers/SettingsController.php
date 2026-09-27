<?php

namespace App\Http\Controllers;

use App\Models\AccountingControl;
use App\Support\BackupStatus;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('settings.index', ['user' => $request->user(), 'accounting' => $request->user()->role === UserRole::Admin ? AccountingControl::findOrFail(1) : null, 'backup' => $request->user()->role === UserRole::Admin ? app(BackupStatus::class)->summary() : null]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['notify_orders' => ['required', 'boolean'], 'notify_messages' => ['required', 'boolean']]);
        $user = $request->user();
        $user->notify_orders = (bool) $data['notify_orders'];
        $user->notify_messages = (bool) $data['notify_messages'];
        $user->save();

        return back()->with('status', 'Preferenze salvate.');
    }
}
