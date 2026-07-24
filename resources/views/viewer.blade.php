<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name', 'Laravel') }}</title>

    @fonts

    @vite(['resources/css/viewer/app.css', 'resources/js/viewer/app.js'])

</head>
<body class="body-marketing body-gradient">
<div id="app"></div>

</body>
</html>
