<!doctype html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Shop online at {{ $storeName }}. Browse products, contact the store, or request cash-on-delivery.">
    <meta name="theme-color" content="#173e36">
    <title>{{ $storeName }} | Online store</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite('resources/js/ecommerce.js')
</head>
<body><div id="storefront"></div></body>
</html>
