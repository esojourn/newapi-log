<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API 用量查询</title>
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
        <h1 class="text-2xl font-bold text-center mb-6" style="color:#1D93AB;">API 用量查询</h1>

        @if ($errors->any())
            <div class="bg-red-50 text-red-600 p-3 rounded mb-4 text-sm">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="/">
            @csrf
            <div class="mb-4">
                <label for="api_key" class="block text-sm font-medium text-gray-700 mb-1">API Key</label>
                <input type="text" name="api_key" id="api_key" required autofocus
                    class="alz-input"
                    placeholder="请输入 API Key"
                    value="{{ old('api_key') }}">
            </div>
            <button type="submit" class="alz-btn">
                查询
            </button>
        </form>
    </div>
</body>
</html>
