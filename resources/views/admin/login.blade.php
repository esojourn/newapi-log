<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台登录 - API Log</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        body { background-color: #E7F8FF; }
        .alz-btn {
            background-color: #1D93AB;
            color: white;
            width: 100%;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            transition: background-color 0.15s;
            cursor: pointer;
            border: none;
            font-size: 0.875rem;
        }
        .alz-btn:hover { background-color: #177b8f; }
        .alz-input {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
        }
        .alz-input:focus {
            outline: none;
            box-shadow: 0 0 0 2px #1D93AB;
            border-color: transparent;
        }
        .alz-card {
            background: white;
            border-top: 3px solid #1D93AB;
            border-radius: 0.5rem;
            box-shadow: 0 4px 16px rgba(29,147,171,0.12);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center">
    <div class="alz-card p-8 w-full max-w-sm">
        <h1 class="text-2xl font-bold text-center mb-6" style="color:#1D93AB;">后台登录</h1>

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
                    class="alz-input"
                    placeholder="请输入后台密码">
            </div>
            <button type="submit" class="alz-btn">
                登录
            </button>
        </form>
    </div>
</body>
</html>
