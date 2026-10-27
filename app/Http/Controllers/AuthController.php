<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    private const MAX_USER_ATTEMPTS = 5;   // per username + IP
    private const MAX_IP_ATTEMPTS = 30;    // per IP
    private const LOCK_SECONDS = 15 * 60;
    private const TOKEN_DAYS = 30;
    private const MIN_PASSWORD_LENGTH = 10;

    /** Web app (SPA) login: starts a cookie session. */
    public function login(Request $request): JsonResponse
    {
        // A session only exists for requests coming from a trusted SPA origin.
        if (! $request->hasSession()) {
            abort(400, 'This endpoint is for the web app. Mobile apps must use /api/auth/token.');
        }

        $user = $this->authenticate($request);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json($this->userPayload($user));
    }

    /** Mobile app login: returns a bearer token. */
    public function token(Request $request): JsonResponse
    {
        $request->validate([
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = $this->authenticate($request);

        $token = $user->createToken(
            $request->string('device_name')->toString(),
            ['*'], // real permissions are read from the user's role on every request
            now()->addDays(self::TOKEN_DAYS),
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();

        if ($currentToken instanceof PersonalAccessToken) {
            // Mobile app: revoke this token
            $currentToken->delete();
        } else {
            // Web app: end the cookie session
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        Audit::log($user->id, 'logout', 'user', $user->id);

        return response()->json(['ok' => true]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string', 'max:200'],
            'newPassword' => [
                'required',
                'string',
                'min:'.self::MIN_PASSWORD_LENGTH,
                function (string $attribute, mixed $value, \Closure $fail): void {
                    // bcrypt only uses the first 72 bytes
                    if (strlen($value) > 72) {
                        $fail('The new password is too long (maximum 72 bytes).');
                    }
                },
            ],
        ]);

        $user = $request->user();

        if (! $this->passwordMatches($data['currentPassword'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'currentPassword' => 'Current password is incorrect.',
            ]);
        }
        if ($data['newPassword'] === $data['currentPassword']) {
            throw ValidationException::withMessages([
                'newPassword' => 'New password must be different from the current one.',
            ]);
        }

        $currentToken = $user->currentAccessToken();
        $currentTokenId = $currentToken instanceof PersonalAccessToken ? $currentToken->id : null;

        DB::transaction(function () use ($user, $data, $currentTokenId) {
            $user->forceFill(['password_hash' => Hash::make($data['newPassword'])])->save();

            // Sign out every other app/device that uses a token
            $user->tokens()
                ->when($currentTokenId, fn ($query) => $query->where('id', '!=', $currentTokenId))
                ->delete();

            Audit::log($user->id, 'change_password', 'user', $user->id);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Checks username + password with brute-force protection.
     * Always answers with the same generic error for unknown user, disabled
     * user and wrong password, and always spends the same hashing time.
     */
    private function authenticate(Request $request): User
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $username = mb_strtolower(trim($credentials['username']));
        $ip = (string) $request->ip();
        $userKey = "login:user:{$username}|{$ip}";
        $ipKey = "login:ip:{$ip}";

        foreach ([[$userKey, self::MAX_USER_ATTEMPTS], [$ipKey, self::MAX_IP_ATTEMPTS]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                abort(
                    429,
                    'Too many failed attempts. Please try again later.',
                    ['Retry-After' => RateLimiter::availableIn($key)],
                );
            }
        }

        $user = User::query()
            ->with('role')
            ->whereRaw('lower(username) = ?', [$username])
            ->first();

        // For an unknown user we still verify against a dummy hash, so the
        // response time does not reveal whether the username exists.
        $hash = $user?->password_hash
            ?? Cache::rememberForever('auth.dummy_hash', fn () => Hash::make(Str::random(32)));
        $passwordOk = $this->passwordMatches($credentials['password'], $hash);

        if (! $user || ! $user->is_active || ! $passwordOk) {
            RateLimiter::hit($userKey, self::LOCK_SECONDS);
            RateLimiter::hit($ipKey, self::LOCK_SECONDS);

            if ($user) {
                Audit::log($user->id, 'login_failed', 'user', $user->id, ['ip' => $ip]);
            }

            abort(401, 'Invalid username or password');
        }

        RateLimiter::clear($userKey);
        DB::table('users')->where('id', $user->id)->update(['last_login_at' => now()]);
        Audit::log($user->id, 'login', 'user', $user->id, ['ip' => $ip]);

        return $user;
    }

    private function passwordMatches(string $plain, string $hash): bool
    {
        try {
            return Hash::check($plain, $hash);
        } catch (\RuntimeException) {
            return false; // stored hash is in an unsupported format
        }
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing('role');

        return [
            'id' => $user->id,
            'username' => $user->username,
            'fullName' => $user->full_name,
            'role' => $user->role->name,
            'permissions' => $user->role->permissions,
        ];
    }
}
