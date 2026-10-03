@php
    $canRetry = request()->isMethod('GET') && in_array($code, [429, 500, 502, 503, 504], true);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f7f6fd">
    <title>{{ $title }} · uddog</title>
    <style>
        :root { color-scheme: light; font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { min-width: 320px; min-height: 100vh; margin: 0; color: #303446; background: #f7f6fd; }
        button, a { font: inherit; }
        .shell { width: min(100% - 48px, 1120px); min-height: 100vh; margin: auto; display: flex; flex-direction: column; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 20px; padding: 30px 0; }
        .brand { display: inline-flex; align-items: center; gap: 12px; color: #303446; text-decoration: none; font-size: 29px; font-weight: 800; letter-spacing: -1.8px; }
        .brand-mark { display: grid; width: 44px; height: 44px; place-items: center; border-radius: 13px; color: #fff; background: #7367f0; box-shadow: 0 8px 18px #7367f033; font-size: 27px; letter-spacing: 0; }
        .brand-dot { color: #7367f0; }
        .topbar-note { color: #8b8fa3; font-size: 12px; font-weight: 700; letter-spacing: .13em; text-transform: uppercase; }
        main { flex: 1; display: grid; place-items: center; padding: 24px 0 50px; }
        .card { position: relative; display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(260px, .85fr); width: 100%; min-height: 490px; overflow: hidden; border: 1px solid #e9e7f2; border-radius: 24px; background: #fff; box-shadow: 0 22px 70px #3430530b; }
        .copy { position: relative; z-index: 1; display: flex; flex-direction: column; align-items: flex-start; justify-content: center; padding: clamp(30px, 6vw, 78px); }
        .eyebrow { display: inline-flex; align-items: center; gap: 9px; padding: 8px 12px; border: 1px solid #e3dfff; border-radius: 999px; color: #6256d4; background: #f6f4ff; font-size: 11px; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; }
        .eyebrow i { width: 7px; height: 7px; border-radius: 50%; background: #7367f0; }
        h1 { max-width: 560px; margin: 27px 0 14px; color: #303446; font-size: clamp(32px, 4vw, 52px); line-height: 1.1; letter-spacing: -.055em; }
        .description { max-width: 450px; margin: 0; color: #777b90; font-size: 16px; line-height: 1.65; }
        .actions { display: flex; flex-wrap: wrap; gap: 11px; margin-top: 31px; }
        .button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 45px; padding: 0 18px; border: 1px solid transparent; border-radius: 9px; font-size: 13px; font-weight: 750; text-decoration: none; cursor: pointer; transition: transform .15s, box-shadow .15s; }
        .button:hover { transform: translateY(-1px); }
        .button-primary { color: #fff; background: #7367f0; box-shadow: 0 8px 16px #7367f033; }
        .button-primary:hover { background: #675be1; }
        .button-secondary { color: #62677b; border-color: #e5e4ef; background: #fff; }
        .button-secondary:hover { box-shadow: 0 5px 16px #34305312; }
        .button svg { width: 17px; height: 17px; }
        .help { max-width: 470px; margin: 29px 0 0; padding-top: 18px; border-top: 1px solid #eeedf4; color: #999cab; font-size: 12px; line-height: 1.55; }
        .art { position: relative; display: grid; place-items: center; overflow: hidden; background: radial-gradient(circle at 38% 42%, #eeeaff 0, #f8f7ff 46%, #f1efff 100%); }
        .art::before, .art::after { content: ''; position: absolute; width: 380px; height: 380px; border: 1px solid #dcd7ff; border-radius: 50%; }
        .art::after { width: 280px; height: 280px; border-style: dashed; }
        .orbit { position: relative; z-index: 1; display: grid; width: 205px; height: 205px; place-items: center; border: 1px solid #dfdcff; border-radius: 49px; background: #fff; box-shadow: 0 26px 70px #6559cc22; transform: rotate(-9deg); }
        .orbit::before { content: ''; position: absolute; inset: 15px; border: 1px solid #f0eeff; border-radius: 36px; }
        .code { color: #7367f0; font-size: clamp(58px, 8vw, 90px); font-weight: 850; letter-spacing: -.09em; transform: rotate(9deg); }
        .spark { position: absolute; z-index: 1; display: grid; width: 43px; height: 43px; place-items: center; border-radius: 13px; color: #7367f0; background: #fff; box-shadow: 0 12px 25px #6559cc22; font-size: 23px; font-weight: 700; }
        .spark-one { top: 18%; right: 19%; transform: rotate(12deg); }
        .spark-two { bottom: 18%; left: 14%; transform: rotate(-10deg); }
        footer { display: flex; justify-content: space-between; gap: 20px; padding: 22px 0 28px; color: #a1a3b3; font-size: 12px; }
        html[lang="bn"] [data-bn] { font-family: "Bangla Sangam MN", "Bangla MN", "Noto Sans Bengali", sans-serif; letter-spacing: 0; }
        html[lang="bn"] h1 { font-size: clamp(30px, 3.7vw, 46px); font-weight: 700; line-height: 1.35; }
        html[lang="bn"] .description { font-size: 17px; }
        html[lang="bn"] .button span, html[lang="bn"] .eyebrow span { font-weight: 600; }
        html[lang="bn"] .help { font-size: 13px; }
        @media (max-width: 720px) { .shell { width: min(100% - 32px, 520px); } .topbar { padding: 22px 0; } .topbar-note { display: none; } .card { grid-template-columns: 1fr; } .copy { order: 2; padding: 34px 28px 38px; } .art { min-height: 225px; } .art::before { width: 260px; height: 260px; } .art::after { width: 190px; height: 190px; } .orbit { width: 122px; height: 122px; border-radius: 31px; } .orbit::before { inset: 9px; border-radius: 23px; } .code { font-size: 54px; } .spark { width: 32px; height: 32px; border-radius: 9px; font-size: 17px; } .spark-one { right: 23%; } .spark-two { left: 21%; } h1 { margin-top: 22px; } .description { font-size: 14px; } .help { margin-top: 25px; } }
        @media (prefers-reduced-motion: reduce) { .button { transition: none; } }
    </style>
</head>
<body>
<div class="shell">
    <header class="topbar">
        <a class="brand" href="{{ url('/') }}" aria-label="uddog home"><span class="brand-mark">U</span><span>uddog<span class="brand-dot">.</span></span></a>
        <span class="topbar-note" data-en="Inventory workspace" data-bn="ইনভেন্টরি কর্মক্ষেত্র">Inventory workspace</span>
    </header>
    <main>
        <section class="card" aria-labelledby="error-title">
            <div class="copy">
                <span class="eyebrow"><i aria-hidden="true"></i><span data-en="Error {{ $code }}" data-bn="ত্রুটি {{ $code }}">Error {{ $code }}</span></span>
                <h1 id="error-title" data-en="{{ $title }}" data-bn="{{ $titleBn }}">{{ $title }}</h1>
                <p class="description" data-en="{{ $description }}" data-bn="{{ $descriptionBn }}">{{ $description }}</p>
                <div class="actions">
                    <a class="button button-primary" href="{{ url('/') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/><path d="M9 21v-7h6v7"/></svg>
                        <span data-en="Go to workspace" data-bn="কর্মক্ষেত্রে যান">Go to workspace</span>
                    </a>
                    @if ($canRetry)
                        <button class="button button-secondary" type="button" onclick="window.location.reload()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 11a8 8 0 1 1-2.4-5.7L20 8"/><path d="M20 3v5h-5"/></svg>
                            <span data-en="Try again" data-bn="আবার চেষ্টা করুন">Try again</span>
                        </button>
                    @endif
                </div>
                <p class="help" data-en="{{ $suggestion }}" data-bn="{{ $suggestionBn }}">{{ $suggestion }}</p>
            </div>
            <div class="art" aria-hidden="true"><span class="spark spark-one">✦</span><span class="orbit"><span class="code">{{ $code }}</span></span><span class="spark spark-two">✦</span></div>
        </section>
    </main>
    <footer><span>© {{ date('Y') }} uddog.</span><span data-en="A clearer way to run your business" data-bn="ব্যবসা পরিচালনার আরও সহজ উপায়">A clearer way to run your business</span></footer>
</div>
<script>
    try {
        if (localStorage.getItem('uddog-language') === 'bn') {
            document.documentElement.lang = 'bn';
            document.querySelectorAll('[data-bn]').forEach(function (element) { element.textContent = element.dataset.bn; });
            document.title = @json($titleBn) + ' · uddog';
        }
    } catch (error) { /* The page remains readable if storage is unavailable. */ }
</script>
</body>
</html>
