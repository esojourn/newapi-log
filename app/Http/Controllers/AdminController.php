<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function showLogin()
    {
        if (session('admin_authenticated')) {
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

        $request->session()->put('admin_authenticated', true);

        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('admin_authenticated');

        return redirect()->route('admin.login');
    }
}
