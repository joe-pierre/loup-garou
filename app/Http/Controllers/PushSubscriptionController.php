<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'endpoint'       => ['required', 'url'],
            'keys.p256dh'    => ['required', 'string'],
            'keys.auth'      => ['required', 'string'],
        ]);

        $request->user()->updatePushSubscription(
            endpoint: $request->input('endpoint'),
            key: $request->input('keys.p256dh'),
            token: $request->input('keys.auth'),
            encoding: $request->input('encoding', 'aesgcm'),
        );

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->deletePushSubscription(
            $request->input('endpoint')
        );

        return response()->json(['success' => true]);
    }
}
