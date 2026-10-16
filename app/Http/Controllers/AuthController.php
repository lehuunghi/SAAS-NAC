<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $credentials['email'] = Str::lower(trim($credentials['email']));
        $credentials['is_active'] = true;
        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'Email hoặc mật khẩu không đúng.']);
        }
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie($request->user()->getAuthPassword()));

        return response()->json(['name' => $request->user()->name]);
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed', 'different:current_password'],
            'user_id' => ['prohibited'],
        ], [
            'current_password.current_password' => 'Mật khẩu hiện tại không đúng.',
            'password.min' => 'Mật khẩu mới cần ít nhất 12 ký tự.',
            'password.confirmed' => 'Mật khẩu nhập lại chưa khớp.',
            'password.different' => 'Mật khẩu mới cần khác mật khẩu hiện tại.',
        ]);

        DB::transaction(function () use ($request, $data): void {
            $request->user()->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            if ($request->user()->is_platform_admin) {
                DB::table('admin_audit_events')->insert([
                    'user_id' => $request->user()->id, 'tenant_id' => null,
                    'action' => 'account.password_changed', 'subject' => (string) $request->user()->id,
                    'details' => '{}', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        $request->session()->regenerate(true);

        return response()->json(['message' => 'Đã đổi mật khẩu. Các phiên đăng nhập khác sẽ phải đăng nhập lại.', 'csrf_token' => csrf_token()]);
    }

    public function logout(Request $request): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
