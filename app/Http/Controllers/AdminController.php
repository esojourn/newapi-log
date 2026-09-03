<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AdminAuth;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function showLogin(Request $request)
    {
        if (session('admin_authenticated') || AdminAuth::tokenMatches($request->cookie(AdminAuth::REMEMBER_COOKIE))) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $adminPassword = config('app.admin_password');

        if (!$adminPassword) {
            return back()->withErrors(['password' => '后台密码未配置，请联系管理员']);
        }

        if ($request->password !== $adminPassword) {
            return back()->withErrors(['password' => '密码错误']);
        }

        $request->session()->regenerate();
        $request->session()->put('admin_authenticated', true);

        // 同时下发长效 cookie：session 过期后 AdminAuth 会据此免密续期
        return redirect()->route('admin.dashboard')->withCookie(AdminAuth::rememberCookie());
    }

    public function logout(Request $request)
    {
        $request->session()->forget('admin_authenticated');

        return redirect()->route('admin.login')->withCookie(AdminAuth::forgetCookie());
    }
}
