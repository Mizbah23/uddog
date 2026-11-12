@php echo '<?xml version="1.0" encoding="UTF-8"?>'; @endphp
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>{{ $storefrontUrl }}</loc>
    </url>
    @foreach ($productIds as $productId)
        <url>
            <loc>{{ $origin.route('ecommerce.products.show', ['product' => $productId], false) }}</loc>
        </url>
    @endforeach
</urlset>
