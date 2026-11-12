<!doctype html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ isset($product) ? \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($product['description'] ?: "{$product['name']} — {$storeName}"), 160) : "{$storeName} — পণ্য দেখুন এবং ক্যাশ অন ডেলিভারিতে অর্ডার করুন।" }}">
    <meta name="theme-color" content="#173e36">
    <title>{{ isset($product) ? $product['name'].' | ' : '' }}{{ $storeName }} | Online store</title>
    <link rel="canonical" href="{{ $canonicalUrl ?? $storefrontUrl }}">
    @isset($structuredData)
        <script type="application/ld+json">{!! \Illuminate\Support\Js::encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endisset
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite('resources/js/ecommerce.js')
</head>
<body>
    @isset($initialData)
        <script id="storefront-data" type="application/json">{!! \Illuminate\Support\Js::encode($initialData) !!}</script>
    @endisset
    <div id="storefront">
        <main>
            @isset($product)
                <a href="{{ $storefrontUrl }}" class="shop-detail-back">সব পণ্যে ফিরুন</a>
                <article>
                    <h1>{{ $product['name'] }}</h1>
                    @if ($product['image_url'])
                        <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" width="240" referrerpolicy="no-referrer">
                    @endif
                    @if ($product['requires_quote'])
                        <p>দাম জানতে যোগাযোগ করুন</p>
                    @else
                        <p>৳{{ $product['price'] }} / {{ $product['unit'] }}</p>
                    @endif
                    <p>{{ $product['in_stock'] ? 'পাওয়া যাচ্ছে' : 'স্টকে নেই' }}</p>
                    @if ($product['description'])
                        <p>{{ $product['description'] }}</p>
                    @endif
                </article>
            @else
                <h1>{{ $storeName }}</h1>
                <ul>
                    @foreach ($products as $item)
                        <li><a href="{{ route('ecommerce.products.show', ['product' => $item->id], false) }}">{{ $item->name }}</a></li>
                    @endforeach
                </ul>
            @endisset
        </main>
    </div>
</body>
</html>
