<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class AdminAuth
{
    /** 长效登录 cookie 名（经 EncryptCookies 加密后落盘） */
    public const REMEMBER_COOKIE = 'admin_remember';

    /** 免密续期时长：30 天 */
    private const REMEMBER_MINUTES = 60 * 24 * 30;

    public function handle(Request $request, Closure $next)
    {
        if ($request->session()->get('admin_authenticated')) {
            return $next($request);
        }

        // session 生命周期只有几小时，过期后靠长效 cookie 免密续期，避免反复输密码
        if (self::tokenMatches($request->cookie(self::REMEMBER_COOKIE))) {
            $request->session()->put('admin_authenticated', true);

            return $next($request);
        }

        return redirect()->route('admin.login');
    }

    /**
     * 登录凭据派生的 HMAC —— 不落明文密码；改 ADMIN_PASSWORD 或换 APP_KEY 后旧 cookie 自动失效。
     * 未配置密码时返回 null，此时任何 cookie 都不该通过。
     */
    public static function rememberToken(): ?string
    {
        $password = config('app.admin_password');

        if (!$password) {
            return null;
        }

        return hash_hmac('sha256', $password, (string) config('app.key'));
    }

    public static function tokenMatches($token): bool
    {
        $expected = self::rememberToken();

        return $expected !== null && is_string($token) && hash_equals($expected, $token);
    }

    public static function rememberCookie(): Cookie
    {
        return cookie(self::REMEMBER_COOKIE, (string) self::rememberToken(), self::REMEMBER_MINUTES);
    }

    public static function forgetCookie(): Cookie
    {
        return cookie()->forget(self::REMEMBER_COOKIE);
    }
}
