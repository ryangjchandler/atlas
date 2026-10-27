<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    /**
     * Close the first-chat welcome. Servers that exist now were shown in it, so only later ones are announced.
     */
    public function dismissWelcome(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['welcomed_at' => now(), 'source_hint_dismissed_at' => now()])->save();

        return back();
    }

    /**
     * Hide the hint about newly added MCP servers until another one is added.
     */
    public function dismissSourceHint(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['source_hint_dismissed_at' => now()])->save();

        return back();
    }
}
