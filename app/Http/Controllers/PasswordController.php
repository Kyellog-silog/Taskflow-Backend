<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash; // still needed for Hash::check() verification
use Illuminate\Validation\Rules;

class PasswordController extends Controller
{
    /**
     * Change the authenticated user's password after verifying the current one.
     * Revokes all existing tokens so other sessions are logged out.
     */
    public function change(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect',
            ], 400);
        }

        $user->update(['password' => $request->password]); // 'hashed' cast on User model handles bcrypt

        // Invalidate all existing tokens so stolen tokens are rendered useless
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully. Please log in again.',
        ]);
    }

    /**
     * Set an initial password for users who registered via social auth and have no password yet.
     */
    public function setInitial(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = $request->user();

        if ($user->password) {
            return response()->json([
                'success' => false,
                'message' => 'A password is already set. Use the change password endpoint instead.',
            ], 400);
        }

        $user->update(['password' => $request->password]); // 'hashed' cast on User model handles bcrypt

        return response()->json([
            'success' => true,
            'message' => 'Password set successfully.',
        ]);
    }
}
