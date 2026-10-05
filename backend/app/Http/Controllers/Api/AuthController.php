<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->mixedCase()->numbers()],
        ]);

        $user = User::create($data + ['role' => 'customer']);
        $user->refresh();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $this->publicUser($user)], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        if (! Auth::guard('web')->attempt($credentials, (bool) $request->boolean('remember'))) {
            return response()->json(['message' => 'Those sign-in details didn’t match. Check them and try again.'], 422);
        }

        $request->session()->regenerate();
        $user = Auth::guard('web')->user();
        if ($user->role !== 'customer') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Auth::forgetGuards();

            return response()->json(['message' => 'Use the FuudGo administrator sign-in.'], 403);
        }

        return response()->json(['user' => $this->publicUser($user)]);
    }

    public function adminLogin(Request $request)
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::guard('web')->attempt($credentials, false)) {
            return response()->json(['message' => 'Those administrator sign-in details didn’t match.'], 422);
        }

        $request->session()->regenerate();
        $user = Auth::guard('web')->user();
        if ($user->role !== 'admin') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Auth::forgetGuards();

            return response()->json(['message' => 'This account does not have administrator access.'], 403);
        }

        return response()->json(['user' => $this->publicUser($user)]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::forgetGuards();

        return response()->json(['message' => 'You’re signed out.']);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $this->publicUser($request->user())]);
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($data);

        return response()->json(['message' => 'If an account matches that email, a reset link will be sent.']);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->mixedCase()->numbers()],
        ]);

        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Your password has been reset.'])
            : response()->json(['message' => 'This reset link is invalid or has expired.'], 422);
    }

    private function publicUser(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'role' => $user->role];
    }
}
