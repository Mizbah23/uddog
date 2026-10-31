@php
    $siteUrl = rtrim((string) config('marketing.url'), '/');
    $pageTitle = 'Uddog | ব্যাবসা পরিচালনার সফটওয়্যার';
    $pageDescription = 'বিক্রয়, কেনাকাটা, ইনভেন্টরি, ক্রেতা ও সরবরাহকারীর বকেয়া এবং ব্যবসার রিপোর্ট—সব এক জায়গায়। বাংলাদেশি ব্যবসার জন্য Uddog-Soft-এর পরিকল্পনা দেখুন।';
    $whatsappNumber = '8801630066059';
    $whatsappUrl = 'https://wa.me/'.$whatsappNumber.'?text='.rawurlencode('আসসালামু আলাইকুম, Uddog-Soft সম্পর্কে বিস্তারিত জানতে চাই।');
    $features = [
        ['number' => '01', 'icon' => '↗', 'name' => 'বিক্রয় থাকুক নিয়ন্ত্রণে', 'copy' => 'বিক্রয়, POS ও পেমেন্টের তথ্য নথিভুক্ত করুন। প্রয়োজনের সময় লেনদেন খুঁজে পান সহজে।'],
        ['number' => '02', 'icon' => '▦', 'name' => 'স্টকের পরিষ্কার হিসাব', 'copy' => 'পণ্য, কেনাকাটা, স্টক চলাচল ও স্টক পরীক্ষার তথ্য একই জায়গায় দেখুন।'],
        ['number' => '03', 'icon' => '◎', 'name' => 'পাওনা ও বকেয়া নজরে', 'copy' => 'ক্রেতার কাছে পাওনা এবং সরবরাহকারীকে পরিশোধযোগ্য অর্থের হিসাব রাখুন।'],
        ['number' => '04', 'icon' => '◫', 'name' => 'সিদ্ধান্ত নিন তথ্য দেখে', 'copy' => 'বিক্রয়, কেনাকাটা, ইনভেন্টরি, খরচ ও লেজারের রিপোর্ট পর্যালোচনা করুন।'],
    ];
    $plans = [
        ['name' => 'Starter', 'price' => '৳২০,০০০', 'audience' => 'ক্ষুদ্র ও মাঝারি উদ্যোগ এবং ছোট ব্যবসার জন্য', 'description' => 'এক শাখার ব্যবসার দৈনন্দিন হিসাব গুছিয়ে নেওয়ার সহজ শুরু।', 'branches' => '১টি', 'users' => '২ জন', 'invoices' => '১,০০০টি', 'accent' => 'starter'],
        ['name' => 'Standard', 'price' => '৳৩০,০০০', 'audience' => 'মাঝারি আকারের ব্যবসার জন্য', 'description' => 'দুই শাখা ও বড় দলের কাজ এক জায়গা থেকে পরিচালনা করুন।', 'branches' => '২টি', 'users' => '৫ জন', 'invoices' => '৫,০০০টি', 'accent' => 'standard'],
        ['name' => 'Premium', 'price' => '৳৫০,০০০', 'audience' => 'বড় ব্যবসা, একাধিক শাখা ও শোরুমের জন্য', 'description' => 'বেশি শাখা, বড় দল ও সীমাহীন বিক্রয় চালানে এগিয়ে চলুন।', 'branches' => '৫টি', 'users' => '২০ জন', 'invoices' => 'সীমাহীন', 'accent' => 'premium'],
    ];
    $renewalFees = App\Models\Organization::ANNUAL_RENEW_FEES;
    $banglaMoney = fn (int $amount): string => '৳'.strtr(number_format($amount), ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']);
    $ecommerceContactUrl = 'https://wa.me/'.$whatsappNumber.'?text='.rawurlencode('আসসালামু আলাইকুম, আমার ব্যবসার জন্য নিজস্ব ই-কমার্স মার্কেটপ্লেস অ্যাড-অনের মূল্য ও বিস্তারিত জানতে চাই।');
    $faqs = [
        ['question' => 'Uddog-Soft দিয়ে কী কী কাজ করা যায়?', 'answer' => 'বিক্রয়, POS, কেনাকাটা, পণ্য ও ইনভেন্টরি, ক্রেতা ও সরবরাহকারীর হিসাব, খরচ, শাখা এবং রিপোর্ট একই সফটওয়্যারে পরিচালনা করা যায়।'],
        ['question' => 'মোবাইল থেকেও কি ব্যবহার করা যায়?', 'answer' => 'হ্যাঁ। মোবাইল, ল্যাপটপ ও বড় পর্দায় ব্যবহার করার উপযোগী করে Uddog-Soft তৈরি করা হয়েছে।'],
        ['question' => 'ক্রেতার পাওনা ও সরবরাহকারীর বকেয়া দেখা যাবে?', 'answer' => 'হ্যাঁ। বিক্রয় ও কেনাকাটার বিপরীতে পেমেন্টের হিসাব রেখে বকেয়া পরিমাণ দেখা যায়।'],
        ['question' => 'একাধিক শাখার হিসাব রাখা যাবে?', 'answer' => 'Starter-এ ১টি, Standard-এ ২টি এবং Premium-এ ৫টি শাখার সুবিধা আছে। আপনার ব্যবসার আকার অনুযায়ী প্যাকেজ বেছে নিতে পারবেন।'],
        ['question' => 'প্যাকেজমূল্য ও বার্ষিক নবায়ন ফি কত?', 'answer' => 'Starter-এর প্যাকেজমূল্য ৳২০,০০০ ও বার্ষিক নবায়ন ৳৪,০০০; Standard-এর প্যাকেজমূল্য ৳৩০,০০০ ও বার্ষিক নবায়ন ৳৭,০০০; Premium-এর প্যাকেজমূল্য ৳৫০,০০০ ও বার্ষিক নবায়ন ৳১২,০০০। প্যাকেজমূল্য ও বার্ষিক নবায়ন ফি আলাদাভাবে দেখানো হয়েছে।'],
        ['question' => 'আমার নিজের ই-কমার্স মার্কেটপ্লেস থাকবে?', 'answer' => 'হ্যাঁ। যেকোনো প্যাকেজের সঙ্গে আলাদা পেইড অ্যাড-অন হিসেবে নিজস্ব ই-কমার্স মার্কেটপ্লেস নিতে পারবেন। নিজের দোকানের লিংকে পণ্য দেখানো, ক্রেতার যোগাযোগ এবং ক্যাশ অন ডেলিভারি অর্ডারের অনুরোধ নেওয়ার সুবিধা থাকবে। অ্যাড-অনের মূল্য ও চালু করার বিস্তারিত জানতে যোগাযোগ করুন।'],
    ];
@endphp
<!doctype html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7f5ef">
    <meta name="description" content="{{ $pageDescription }}">
    <meta name="robots" content="index,follow,max-image-preview:large">
    <link rel="canonical" href="{{ $siteUrl }}/">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v=3">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Uddog-Soft">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ $siteUrl }}/">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $pageDescription }}">
    <title>{{ $pageTitle }}</title>
    <script type="application/ld+json">{!! json_encode([
        chr(64).'context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'Uddog-Soft',
        'url' => $siteUrl.'/',
        'description' => $pageDescription,
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
        'inLanguage' => 'bn',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    @vite('resources/css/marketing.css')
</head>
<body>
    <a class="skip-link" href="#main">মূল বিষয়বস্তুতে যান</a>
    <div class="site-shell">
        <header class="site-header">
            <div class="container header-inner">
                <a class="wordmark" href="{{ route('marketing.home') }}" aria-label="Uddog-Soft হোম">
                    <img class="wordmark-image" src="{{ asset('uddog-soft-logo.png') }}" alt="Uddog-Soft" width="918" height="808">
                </a>
                <nav class="desktop-nav" aria-label="প্রধান নেভিগেশন">
                    <a href="#features">সুবিধাসমূহ</a>
                    <a href="#how-it-works">যেভাবে কাজ করে</a>
                    <a href="#plans">মূল্য পরিকল্পনা</a>
                    <a href="#ecommerce-addon">ই-কমার্স অ্যাড-অন</a>
                    <a href="#questions">সাধারণ প্রশ্ন</a>
                </nav>
                <a class="header-login" href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">বিস্তারিত জানুন <span aria-hidden="true">↗</span></a>
                <details class="mobile-nav">
                    <summary aria-label="নেভিগেশন খুলুন"><span></span><span></span><span></span></summary>
                    <nav aria-label="মোবাইল নেভিগেশন">
                        <a href="#features">সুবিধাসমূহ</a>
                        <a href="#how-it-works">যেভাবে কাজ করে</a>
                        <a href="#plans">মূল্য পরিকল্পনা</a>
                    <a href="#ecommerce-addon">ই-কমার্স অ্যাড-অন</a>
                        <a href="#questions">সাধারণ প্রশ্ন</a>
                        <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">বিস্তারিত জানুন ↗</a>
                    </nav>
                </details>
            </div>
        </header>

        <main id="main">
            <section class="hero" aria-labelledby="hero-title">
                <div class="container hero-grid">
                    <div class="hero-copy">
                        <p class="kicker"><span class="kicker-dot"></span> বাংলাদেশি ব্যবসার জন্য স্মার্ট সফটওয়্যার</p>
                        <h1 id="hero-title">আপনার ব্যবসা চলুক <em>আরও গুছিয়ে।</em></h1>
                        <p class="hero-description">বিক্রয়, স্টক, পাওনা-বকেয়া ও রিপোর্টের হিসাব এক জায়গায় রাখুন। প্রতিদিনের কাজে স্বচ্ছতা আনুন, ব্যবসার গুরুত্বপূর্ণ সিদ্ধান্ত নিন আত্মবিশ্বাসের সঙ্গে।</p>
                        <div class="hero-actions">
                            <a class="button button-primary" href="#plans">মূল্য পরিকল্পনা দেখুন <span aria-hidden="true">↗</span></a>
                            <a class="button button-text" href="#features">সুবিধাগুলো জানুন <span aria-hidden="true">↓</span></a>
                        </div>
                        <div class="hero-note"><span class="note-stars" aria-hidden="true">✳</span><span>দোকান থেকে শোরুম—ব্যবসার দৈনন্দিন হিসাব<br>গুছিয়ে রাখার জন্য তৈরি Uddog-Soft।</span></div>
                    </div>
                    <div class="hero-visual" aria-label="Uddog-Soft ব্যবসা ড্যাশবোর্ডের নমুনা চিত্র">
                        <div class="orbit orbit-one"></div><div class="orbit orbit-two"></div>
                        <div class="floating-tag tag-top"><span class="tag-symbol">✓</span> সব হিসাব এক জায়গায়</div>
                        <div class="mock-window">
                            <div class="mock-sidebar">
                                <div class="mock-logo" role="img" aria-label="Uddog-Soft logo"></div>
                                <span class="mock-side-active">▦</span><span>◫</span><span>▤</span><span>◎</span>
                                <span class="mock-side-bottom">⚙</span>
                            </div>
                            <div class="mock-content">
                                <div class="mock-topline"><span>ব্যবসার সারাংশ <span class="mock-chevron">⌄</span></span><span class="mock-avatar">D</span></div>
                                <p class="mock-overline">আপনার ব্যবসা</p>
                                <h2>শুভ সকাল, ডেমো <span>✳</span></h2>
                                <p class="mock-subtitle">আজকের গুরুত্বপূর্ণ তথ্য এক নজরে।</p>
                                <div class="mock-highlight"><div><small>ব্যবসার সারাংশ</small><strong>সব তথ্য আপনার হাতে</strong><span>বিক্রয় · স্টক · পেমেন্ট</span></div><div class="mock-highlight-icon">↗</div></div>
                                <div class="mock-card-row"><div class="mock-card"><span class="mock-card-icon purple">↗</span><small>বিক্রয়</small><b>লেনদেন দেখুন</b><i></i></div><div class="mock-card"><span class="mock-card-icon peach">▦</span><small>ইনভেন্টরি</small><b>স্টক দেখুন</b><i></i></div></div>
                                <div class="mock-bottom"><span class="mock-card-icon mint">◎</span><div><small>পাওনা ও বকেয়া</small><strong>হিসাব থাকুক পরিষ্কার</strong></div><span>→</span></div>
                            </div>
                        </div>
                        <div class="floating-tag tag-bottom"><span class="tag-symbol amber">↗</span> সিদ্ধান্ত নিন তথ্য দেখে</div>
                        <p class="visual-caption">ড্যাশবোর্ডের নমুনা চিত্র</p>
                    </div>
                </div>
                <div class="container hero-foot"><span>এক জায়গায় ব্যবসার হিসাব</span><span>বিক্রয় থেকে পূর্ণাঙ্গ রিপোর্ট</span><span>বাংলাদেশি ব্যবসার জন্য তৈরি</span></div>
            </section>

            <section class="intro-section" id="features" aria-labelledby="features-title">
                <div class="container">
                    <div class="section-heading"><div><p class="kicker kicker-dark">প্রতিদিনের ব্যবসা, সহজ ব্যবস্থাপনা</p><h2 id="features-title">কম ঝামেলা।<br><em>বেশি নিয়ন্ত্রণ।</em></h2></div><p>ব্যবসার আলাদা আলাদা কাজের হিসাব আর ছড়িয়ে রাখতে হবে না। প্রয়োজনীয় তথ্য পান একই সফটওয়্যারে।</p></div>
                    <div class="feature-grid">
                        @foreach ($features as $feature)
                            <article class="feature-card feature-{{ $feature['number'] }}">
                                <div class="feature-top"><span class="feature-icon" aria-hidden="true">{{ $feature['icon'] }}</span><span class="feature-number">{{ $feature['number'] }}</span></div>
                                <h3>{{ $feature['name'] }}</h3><p>{{ $feature['copy'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="flow-section" id="how-it-works" aria-labelledby="flow-title">
                <div class="container flow-grid">
                    <div class="flow-copy"><p class="kicker kicker-light">একটি সংযুক্ত কর্মপ্রবাহ</p><h2 id="flow-title">দৈনন্দিন কাজ থেকেই ব্যবসার পরিষ্কার চিত্র।</h2><p>আপনার ব্যবসায় যে কাজগুলো প্রতিদিন হয়, সেগুলো নথিভুক্ত করুন। Uddog-Soft তথ্যগুলো একসঙ্গে রাখে, যাতে প্রয়োজনের সময় হিসাব বুঝতে সুবিধা হয়।</p><a class="button button-light" href="#plans">পরিকল্পনা তুলনা করুন <span aria-hidden="true">↗</span></a></div>
                    <div class="flow-list"><div><span>01</span><div><h3>লেনদেন নথিভুক্ত করুন</h3><p>বিক্রয়, কেনাকাটা, পণ্য ও পেমেন্টের তথ্য রাখুন।</p></div></div><div><span>02</span><div><h3>শাখার কার্যক্রম দেখুন</h3><p>একাধিক অবস্থানের তথ্য শাখা অনুযায়ী পর্যালোচনা করুন।</p></div></div><div><span>03</span><div><h3>রিপোর্ট দেখে সিদ্ধান্ত নিন</h3><p>স্টক, পাওনা-বকেয়া ও অন্যান্য রিপোর্ট প্রয়োজনমতো দেখুন।</p></div></div></div>
                </div>
            </section>

            <section class="mobile-section" aria-labelledby="mobile-title">
                <div class="container mobile-grid">
                    <figure class="mobile-art">
                        <div class="mobile-device">
                            <img src="{{ asset('uddog-mobile-dashboard.jpg') }}" alt="Uddog-Soft-এর আসল মোবাইল ড্যাশবোর্ড: ব্যবসার সারসংক্ষেপ, দ্রুত কাজ ও নিচের নেভিগেশন" width="381" height="726" loading="lazy" decoding="async">
                        </div>
                    </figure>
                    <div class="mobile-copy">
                        <p class="kicker kicker-dark">মোবাইলেও আপনার ব্যবসা</p>
                        <h2 id="mobile-title">দোকানে, পথে কিংবা অফিসে—<em>হিসাব থাকুক সঙ্গে।</em></h2>
                        <p>মোবাইল থেকে প্রয়োজনীয় তথ্য দেখুন। বিস্তারিত কাজের জন্য ল্যাপটপ বা বড় পর্দাতেও ব্যবহার করুন।</p>
                        <a class="inline-link" href="#plans">আপনার ব্যবসার পরিকল্পনা দেখুন <span aria-hidden="true">↗</span></a>
                    </div>
                </div>
            </section>

            <section class="plans-section" id="plans" aria-labelledby="plans-title">
                <div class="container">
                    <div class="plans-heading"><p class="kicker kicker-dark">মূল্য পরিকল্পনা</p><h2 id="plans-title">আপনার ব্যবসার আকার অনুযায়ী <em>পরিকল্পনা বেছে নিন।</em></h2><p>কতটি শাখা, কতজন ব্যবহারকারী, কতটি বিক্রয় চালান—এক নজরে মিলিয়ে নিন। প্যাকেজমূল্য ও বার্ষিক নবায়ন ফি দুটোই জানুন আগে।</p></div>
                    <div class="plans-grid">
                        @foreach ($plans as $plan)
                            <article class="plan-card plan-{{ $plan['accent'] }}">
                                <div class="plan-card-top"><h3 class="plan-name">{{ $plan['name'] }}</h3><span class="plan-mark" aria-hidden="true">✳</span></div>
                                <p class="plan-price-label">প্যাকেজমূল্য</p>
                                <p class="plan-price">{{ $plan['price'] }}</p>
                                <div class="plan-renewal"><span>বার্ষিক নবায়ন ফি</span><strong>{{ $banglaMoney($renewalFees[$plan['name']]) }}<small> / বছর</small></strong></div>
                                <p class="plan-description">{{ $plan['description'] }}</p>
                                <dl class="plan-limits" aria-label="{{ $plan['name'] }} প্যাকেজের সুবিধা">
                                    <div><dt><span aria-hidden="true">✓</span> শাখা</dt><dd>{{ $plan['branches'] }}</dd></div>
                                    <div><dt><span aria-hidden="true">✓</span> ব্যবহারকারী</dt><dd>{{ $plan['users'] }}</dd></div>
                                    <div><dt><span aria-hidden="true">✓</span> বিক্রয় চালান</dt><dd>{{ $plan['invoices'] }}</dd></div>
                                </dl>
                                <div class="plan-rule"></div>
                                <p class="plan-fit-label">যাদের জন্য উপযোগী</p>
                                <p class="plan-fit">{{ $plan['audience'] }}</p>
                                <a class="plan-contact" href="https://wa.me/{{ $whatsappNumber }}?text={{ rawurlencode('আসসালামু আলাইকুম, Uddog-Soft '.$plan['name'].' প্যাকেজ (প্যাকেজমূল্য '.$plan['price'].', বার্ষিক নবায়ন '.$banglaMoney($renewalFees[$plan['name']]).') সম্পর্কে বিস্তারিত জানতে চাই।') }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $plan['name'] }} পরিকল্পনা সম্পর্কে WhatsApp-এ যোগাযোগ করুন">যোগাযোগ করুন <span aria-hidden="true">↗</span></a>
                            </article>
                        @endforeach
                    </div>
                    <p class="plans-note">ই-কমার্স মার্কেটপ্লেস মূল প্যাকেজের অন্তর্ভুক্ত নয়। আগ্রহী হলে আলাদা পেইড সার্ভিস হিসেবে যোগ করুন।</p>
                </div>
            </section>

            <section class="marketplace-addon-section" id="ecommerce-addon" aria-labelledby="marketplace-addon-title">
                <div class="container marketplace-addon">
                    <div class="marketplace-addon-copy">
                        <span class="marketplace-addon-badge">নিজের অনলাইন মার্কেটপ্লেস · সব প্যাকেজের সঙ্গে</span>
                        <h2 id="marketplace-addon-title">দোকানের গণ্ডি ছাড়িয়ে,<br><em>নিজের অনলাইন মার্কেটপ্লেস।</em></h2>
                        <p>আপনার ব্যবসার নামেই অনলাইন দোকান। ক্রেতা পণ্য দেখবেন, যোগাযোগ করবেন, অথবা ঘরে বসেই অর্ডারের অনুরোধ পাঠাবেন।</p>
                        <ul><li>নিজস্ব দোকানের লিংক ও পণ্যের অনলাইন ক্যাটালগ</li><li>কোন পণ্য অনলাইনে দেখাবেন, নিয়ন্ত্রণ আপনার হাতে</li><li>ক্রেতার যোগাযোগ ও ক্যাশ অন ডেলিভারি অর্ডার অনুরোধ</li><li>অর্ডার গ্রহণের পর বিক্রয় ও স্টকের হিসাব একসঙ্গে</li></ul>
                        <a class="button button-primary marketplace-addon-contact" href="{{ $ecommerceContactUrl }}" target="_blank" rel="noopener noreferrer">ই-কমার্স সাইটের মূল্য জানুন <span aria-hidden="true">↗</span></a>
                        <small>আলাদাভাবে কিনতে হবে। আপনার প্যাকেজের সঙ্গে চালু করতে যোগাযোগ করুন।</small>
                    </div>
                    <div class="marketplace-preview" aria-label="অনলাইন দোকানের উদাহরণ">
                        <div class="marketplace-preview-bar"><span aria-hidden="true">● ● ●</span><code>apnar-dokan.uddog-soft.com/ecomm</code></div>
                        <div class="marketplace-preview-content"><span class="marketplace-preview-label">আপনার নিজের অনলাইন দোকান</span><h3>আপনার পণ্য।<br>আপনার পরিচয়ে।</h3><p>ক্রেতার হাতের মুঠোয়, এখন আপনার দোকানও।</p><div class="marketplace-preview-products" aria-hidden="true"><div><span>▧</span><i></i><i></i></div><div><span>♧</span><i></i><i></i></div><div><span>◈</span><i></i><i></i></div></div><div class="marketplace-preview-footer"><span>পণ্য দেখুন →</span><span>ক্যাশ অন ডেলিভারি</span></div><small>নমুনা প্রদর্শন · দোকানের লিংকের উদাহরণ</small></div>
                    </div>
                </div>
            </section>

            <section class="faq-section" id="questions" aria-labelledby="faq-title"><div class="container faq-grid"><div><p class="kicker kicker-dark">জানতে চাইতে পারেন</p><h2 id="faq-title">সাধারণ প্রশ্নের উত্তর।</h2><p>Uddog-Soft আপনার ব্যবসার জন্য মানানসই কি না, তা বুঝতে কিছু প্রয়োজনীয় তথ্য।</p></div><div class="faq-list">@foreach ($faqs as $faq)<details><summary>{{ $faq['question'] }}<span aria-hidden="true">+</span></summary><p>{{ $faq['answer'] }}</p></details>@endforeach</div></div></section>

            <section class="closing-section"><div class="container closing-inner"><span class="closing-mark" aria-hidden="true">✳</span><p class="kicker">পরবর্তী ধাপ</p><h2>আপনার ব্যবসার জন্য<br>সঠিক পরিকল্পনাটি দেখুন।</h2><p>দৈনন্দিন হিসাবকে আরও সহজ ও সংগঠিত করার শুরু এখানেই।</p><a class="button button-white" href="#plans">তিনটি পরিকল্পনা দেখুন <span aria-hidden="true">↑</span></a></div></section>
        </main>
        <footer class="site-footer"><div class="container footer-inner"><a class="wordmark" href="{{ route('marketing.home') }}" aria-label="Uddog-Soft হোম"><img class="wordmark-image" src="{{ asset('uddog-soft-logo.png') }}" alt="Uddog-Soft" width="918" height="808"></a><p>বাংলাদেশি ব্যবসার হিসাব গুছিয়ে রাখার সফটওয়্যার।</p><a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">মেসেজ দিন ↗</a><span>© {{ date('Y') }} Uddog-Soft</span></div></footer>
    </div>
    <script>
        document.querySelector('.mobile-nav nav')?.addEventListener('click', (event) => {
            if (event.target.closest('a')) {
                event.currentTarget.closest('details').open = false;
            }
        });
    </script>
</body>
</html>
