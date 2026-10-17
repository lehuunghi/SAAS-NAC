<?php

namespace App\Http\Controllers;

use App\Services\PasswordRecovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordRecoveryController extends Controller
{
    public function options(PasswordRecovery $recovery): JsonResponse
    {
        return response()->json(['password_recovery_enabled' => $recovery->available()]);
    }

    public function send(Request $request, PasswordRecovery $recovery): JsonResponse
    {
        abort_unless($recovery->available(), 503, 'Khôi phục qua email chưa sẵn sàng. Vui lòng liên hệ TN20.');
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $recovery->send(Str::lower(trim($data['email'])));

        return response()->json(['message' => 'Nếu email thuộc tài khoản đang hoạt động, bạn sẽ nhận được liên kết khôi phục. Vui lòng kiểm tra cả thư rác.']);
    }

    public function reset(Request $request, PasswordRecovery $recovery): JsonResponse
    {
        abort_unless($recovery->available(), 503, 'Khôi phục qua email chưa sẵn sàng. Vui lòng liên hệ TN20.');
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'], 'token' => ['required', 'string', 'max:256'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'], 'user_id' => ['prohibited'],
        ], ['password.min' => 'Mật khẩu mới cần ít nhất 12 ký tự.', 'password.confirmed' => 'Mật khẩu nhập lại chưa khớp.']);
        $data['email'] = Str::lower(trim($data['email']));
        $data['password_confirmation'] = $request->string('password_confirmation')->toString();
        if ($recovery->reset($data) !== Password::PasswordReset) {
            throw ValidationException::withMessages(['token' => 'Liên kết không hợp lệ hoặc đã hết hạn. Vui lòng yêu cầu liên kết mới.']);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Đã đặt lại mật khẩu. Vui lòng đăng nhập bằng mật khẩu mới.', 'csrf_token' => csrf_token()]);
    }
}
