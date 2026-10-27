<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Wuyoo')</title>
</head>
<body>
    <main>@yield('content')</main>
    @stack('scripts')
</body>
</html>
