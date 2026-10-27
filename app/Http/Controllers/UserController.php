<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/users', [
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'role', 'created_at']),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        // Admins cannot change their own role, so there is always at least one admin...
        abort_if($user->is($request->user()), 403, 'You cannot change your own role.');

        $user->forceFill($request->validate([
            'role' => ['required', Rule::enum(Role::class)],
        ]))->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} is now ".($user->isAdmin() ? 'an admin.' : 'a user.')]);

        return back();
    }
}
