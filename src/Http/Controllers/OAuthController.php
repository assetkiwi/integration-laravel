<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Http\Controllers;

use AssetKiwi\Laravel\OAuth\OAuthManager;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OAuthController extends Controller
{
    public function callback(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        $storedState = session()->pull('assetkiwi.oauth.state');
        $codeVerifier = session()->pull('assetkiwi.oauth.code_verifier');

        if (! $storedState || ! hash_equals($storedState, $request->input('state'))) {
            return response()->json(['error' => 'Invalid state parameter.'], 400);
        }

        $tokenData = OAuthManager::exchangeCode(
            $request->input('code'),
            $request->input('state'),
            $codeVerifier
        );

        if (! $tokenData) {
            return redirect()->intended('/')->with('error', 'Failed to connect to asset.kiwi.');
        }

        OAuthManager::storeToken($request->user(), $tokenData);

        return redirect()->intended('/')->with('success', 'Successfully connected to asset.kiwi.');
    }
}
