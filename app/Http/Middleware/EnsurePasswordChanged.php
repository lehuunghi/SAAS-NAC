<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->fresh()?->must_change_password && ! $request->routeIs('account.me', 'account.password', 'account.logout')) {
            return response()->json(['message' => 'Cần đổi mật khẩu ban đầu trước khi sử dụng dịch vụ.', 'code' => 'password_change_required'], 423);
        }

        return $next($request);
    }
}
