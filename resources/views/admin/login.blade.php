<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台登录 - API Log</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-sm">
        <h1 class="text-2xl font-bold text-center text-gray-800 mb-6">后台登录</h1>

        @if ($errors->any())
            <div class="bg-red-50 text-red-600 p-3 rounded mb-4 text-sm">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ url('/admin/login') }}">
            @csrf
            <div class="mb-4">
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">密码</label>
                <input type="password" name="password" id="password" required autofocus
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    placeholder="请输入后台密码">
            </div>
            <button type="submit"
                class="w-full bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 transition">
                登录
            </button>
        </form>
    </div>
</body>
</html>
