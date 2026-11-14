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
    @if ($initialData['store']['appearance']['logo_url'])
        <link rel="icon" href="{{ $initialData['store']['appearance']['logo_url'] }}" referrerpolicy="no-referrer">
    @else
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @endif
    @vite(['resources/css/ecommerce.css', 'resources/js/ecommerce.js'])
</head>
<body>
    <div id="storefront">
        @php
            $appearance = $initialData['store']['appearance'];
            $colors = array_filter([
                '--store-primary' => $appearance['primary_color'],
                '--store-hero' => $appearance['hero_color'],
                '--store-accent' => $appearance['accent_color'],
            ], fn ($color) => is_string($color) && preg_match('/\A#[0-9a-f]{6}\z/i', $color));
        @endphp
        <div class="shop" style="{{ collect($colors)->map(fn ($color, $property) => $property.':'.$color)->implode(';') }}">
            <div class="shop-announcement">আপনার চেনা দোকান, এখন অনলাইনে। <span>✦</span> ক্যাশ অন ডেলিভারি</div>
            <div class="shop-header-band">
                <header class="shop-header">
                    <a href="{{ $storefrontUrl }}" class="shop-brand">
                        <span class="shop-brand-mark">
                            <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z M3 6h18 M16 10a4 4 0 0 1-8 0"/>
                            </svg>
                            @if ($appearance['logo_url'])
                                <img class="shop-brand-logo" src="{{ $appearance['logo_url'] }}" alt="" referrerpolicy="no-referrer" onload="this.classList.add('is-loaded')">
                            @endif
                        </span>
                        <span>{{ $storeName }}<small>LOCAL FINDS, EVERYDAY GOOD</small></span>
                    </a>
                </header>
            </div>
            @isset($product)
                <main class="shop-product-page">
                    <a href="{{ $storefrontUrl }}#collection" class="shop-detail-back">সব পণ্যে ফিরুন</a>
                    <article class="shop-detail">
                        <div class="shop-detail-visual">
                            @if ($product['image_url'])
                                <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" referrerpolicy="no-referrer">
                            @else
                                <div class="shop-product-placeholder" aria-hidden="true">{{ $product['name'] }}</div>
                            @endif
                        </div>
                        <div class="shop-detail-info">
                            <span class="shop-product-category">{{ $product['category'] }}</span>
                            <h1>{{ $product['name'] }}</h1>
                            <div class="shop-detail-price">
                                @if ($product['requires_quote'])
                                    <strong>দাম জানতে যোগাযোগ করুন</strong>
                                @else
                                    <strong>৳{{ $product['price'] }}</strong>
                                    <small>প্রতি {{ $product['unit'] }}</small>
                                @endif
                            </div>
                            <p @class(['shop-detail-stock', 'out' => ! $product['in_stock']])>
                                <span></span><strong>{{ $product['in_stock'] ? 'পাওয়া যাচ্ছে' : 'স্টকে নেই' }}</strong>
                            </p>
                        </div>
                        @if ($product['description'])
                            <section class="shop-detail-description" id="product-description">
                                <h2>পণ্যের বিস্তারিত</h2>
                                <p>{{ $product['description'] }}</p>
                            </section>
                        @endif
                    </article>
                </main>
            @else
                <main>
                    @php
                        $selectedCategory = request()->query('category');
                        $selectedCategory = $initialData['categories']->contains('name', $selectedCategory) ? $selectedCategory : null;
                    @endphp
                    @if (count($initialData['categories']))
                        <nav class="shop-category-tiles" aria-label="পণ্যের বিভাগ">
                            @foreach ($initialData['categories'] as $categoryTile)
                                <a href="?{{ http_build_query(['category' => $categoryTile['name']]) }}#collection">
                                    <span>
                                        @if ($categoryTile['image'])
                                            <img src="{{ $categoryTile['image'] }}" alt="" loading="lazy" referrerpolicy="no-referrer">
                                        @else
                                            <span aria-hidden="true">◇</span>
                                        @endif
                                    </span>
                                    {{ $categoryTile['name'] }}
                                </a>
                            @endforeach
                        </nav>
                    @endif
                    @if ($appearance['hero_images'])
                        <section class="shop-banner-carousel" aria-label="দোকানের ব্যানার">
                            <img src="{{ $appearance['hero_images'][0] }}" alt="{{ $storeName }}" fetchpriority="high">
                        </section>
                    @else
                    <section class="shop-hero">
                        <div class="shop-hero-copy">
                            <span class="shop-eyebrow"><span></span>আপনার চেনা দোকানের সংগ্রহ</span>
                            <h1>{{ $appearance['hero'] ?: 'পছন্দের সবকিছু, হাতের কাছেই।' }}</h1>
                            <p>{{ $appearance['subtitle'] ?: 'আপনার এলাকার দোকানের পণ্য দেখুন। ফোনে যোগাযোগ করুন অথবা ঘরে বসেই অর্ডার দিন।' }}</p>
                            <div class="shop-hero-actions">
                                <a href="#collection" class="shop-primary">পণ্য দেখুন <span aria-hidden="true">→</span></a>
                            </div>
                        </div>
                        <div class="shop-hero-art" aria-hidden="true">
                            <span class="shop-art-ring"></span>
                            <div class="shop-art-label">THE EVERYDAY EDIT <span>✦</span></div>
                            <div class="shop-art-bag">
                                <svg width="114" height="114" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z M3 6h18 M16 10a4 4 0 0 1-8 0"/>
                                </svg>
                                <span>{{ $storeName }}</span>
                            </div>
                            <span class="shop-art-note">A little local.<br>A lot to love.</span>
                            <span class="shop-art-star">✳</span>
                        </div>
                    </section>
                    @endif
                    <section id="collection" class="shop-collection">
                        <div class="shop-section-title"><h2>সব পণ্য</h2></div>
                        <div class="shop-product-grid">
                            @forelse ($initialData['products']->filter(fn ($item) => ! $selectedCategory || $item['category'] === $selectedCategory) as $item)
                                @php($productUrl = route('ecommerce.products.show', ['product' => $item['id']], false))
                                <article class="shop-product">
                                    <div class="shop-product-visual">
                                        <div class="shop-product-visual-link">
                                            @if ($item['image_url'])
                                                <img src="{{ $item['image_url'] }}" alt="" loading="lazy" referrerpolicy="no-referrer">
                                            @else
                                                <span class="shop-product-placeholder" aria-hidden="true">{{ $item['name'] }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="shop-product-info">
                                        <span class="shop-product-category">{{ $item['category'] }}</span>
                                        <h3><a href="{{ $productUrl }}">{{ $item['name'] }}</a></h3>
                                    </div>
                                </article>
                            @empty
                                <p>এখানে এখনো কোনো পণ্য নেই।</p>
                            @endforelse
                        </div>
                    </section>
                </main>
            @endisset
        </div>
    </div>
    <script id="storefront-data" type="application/json">{!! \Illuminate\Support\Js::encode($initialData) !!}</script>
</body>
</html>
