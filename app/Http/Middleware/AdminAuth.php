<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->session()->get('admin_authenticated')) {
            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
