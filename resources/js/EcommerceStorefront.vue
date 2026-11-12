<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Copy, ShoppingBag, Search, ArrowUpRight, ArrowRight, ArrowLeft, Plus, Minus, X, Phone, MapPin, Package, ShieldCheck, Truck, Check, SlidersHorizontal } from '@lucide/vue';
const productId = window.location.pathname.match(/^\/online-store\/products\/(\d+)$/)?.[1] || null;
const imageIndexes = ref({});
function productPhotos(product) { return (product.images?.length ? product.images : product.image_url ? [product.image_url] : []).filter(url => !failedImages.value.has(url)); }
function productPhoto(product) { const photos = productPhotos(product); return photos[(imageIndexes.value[product.id] || 0) % photos.length] || ''; }
function changePhoto(product, step) { const count = productPhotos(product).length; imageIndexes.value[product.id] = ((imageIndexes.value[product.id] || 0) + step + count) % count; }
const language = ref('bn');
const copy = {
  en: { store: 'Your neighborhood, online.', intro: 'Good things. Close to home.', subtitle: 'Discover your local store’s collection. Browse, call us, or order from the comfort of home.', browse: 'Explore the collection', contact: 'Contact the store', cart: 'Your bag', collections: 'THE LOCAL COLLECTION', categoryCount: 'collections', all: 'All products', search: 'Find something you need…', sort: 'Sort by', name: 'Name', low: 'Price: low to high', high: 'Price: high to low', products: 'products', add: 'Add to bag', unavailable: 'Out of stock', quote: 'Price on request', inStock: 'Available', availableQuantity: 'Available quantity', details: 'View details', description: 'Product details', backToProducts: 'Back to all products', per: 'per', empty: 'Your bag is waiting', emptyHelp: 'Pick something from the collection to get started.', continue: 'Continue shopping', remove: 'Remove', total: 'Subtotal', cod: 'Cash on delivery', codHelp: 'No online payment. The store will call to confirm availability, delivery area and any delivery charge before accepting your order.', checkout: 'Request an order', customer: 'Full name', phone: 'Phone number', address: 'Delivery address', notes: 'Notes (optional)', place: 'Send order request', sending: 'Sending…', success: 'Your request is with the store.', successHelp: 'Keep your reference below. The store will contact you to confirm your order and delivery details.', noResults: 'Nothing here just yet.', noResultsHelp: 'Try another search or check back for new products.', reset: 'Clear filters', loading: 'Opening the store…', retry: 'Try again', curated: 'Chosen by your local store', delivery: 'Order from home', safe: 'Pay when you receive', footer: 'A little closer to the things you need.', powered: 'Storefront powered by Uddog', quoteHelp: 'Please contact the store for a current price.', stockNotice: 'Availability is confirmed by the store.', failed: 'We could not load the store. Please try again.', checkoutFailed: 'Your request could not be sent. Please try again.', viewBag: 'View bag', each: 'items', quantity: 'Quantity', back: 'Back to bag' },
  bn: { store: 'আপনার চেনা দোকান, এখন অনলাইনে।', intro: 'পছন্দের সবকিছু, হাতের কাছেই।', subtitle: 'আপনার এলাকার দোকানের পণ্য দেখুন। ফোনে যোগাযোগ করুন অথবা ঘরে বসেই অর্ডার দিন।', browse: 'পণ্য দেখুন', contact: 'দোকানে যোগাযোগ করুন', cart: 'আপনার ব্যাগ', collections: 'আপনার দোকানের সংগ্রহ', categoryCount: 'টি বিভাগ', all: 'সব পণ্য', search: 'আপনার প্রয়োজনীয় পণ্য খুঁজুন…', sort: 'সাজান', name: 'নাম অনুযায়ী', low: 'দাম: কম থেকে বেশি', high: 'দাম: বেশি থেকে কম', products: 'টি পণ্য', add: 'ব্যাগে যোগ করুন', unavailable: 'স্টকে নেই', quote: 'দাম জানতে যোগাযোগ করুন', inStock: 'পাওয়া যাচ্ছে', availableQuantity: 'মজুদের পরিমাণ', details: 'বিস্তারিত দেখুন', description: 'পণ্যের বিস্তারিত', backToProducts: 'সব পণ্যে ফিরুন', per: 'প্রতি', empty: 'আপনার ব্যাগ এখনো খালি', emptyHelp: 'সংগ্রহ থেকে পছন্দের পণ্য যোগ করুন।', continue: 'আরও পণ্য দেখুন', remove: 'সরান', total: 'উপমোট', cod: 'ক্যাশ অন ডেলিভারি', codHelp: 'অনলাইনে টাকা দিতে হবে না। দোকান থেকে ফোন করে পণ্যের প্রাপ্যতা, ডেলিভারির এলাকা ও প্রযোজ্য ডেলিভারি চার্জ নিশ্চিত করা হবে।', checkout: 'অর্ডারের অনুরোধ করুন', customer: 'পুরো নাম', phone: 'ফোন নম্বর', address: 'ডেলিভারির ঠিকানা', notes: 'মন্তব্য (ঐচ্ছিক)', place: 'অর্ডারের অনুরোধ পাঠান', sending: 'পাঠানো হচ্ছে…', success: 'আপনার অনুরোধ দোকানে পৌঁছেছে।', successHelp: 'নিচের রেফারেন্স নম্বরটি রাখুন। অর্ডার ও ডেলিভারির তথ্য নিশ্চিত করতে দোকান থেকে যোগাযোগ করা হবে।', noResults: 'এখানে এখনো কোনো পণ্য নেই।', noResultsHelp: 'অন্য কিছু খুঁজুন অথবা নতুন পণ্যের জন্য পরে দেখুন।', reset: 'ফিল্টার মুছুন', loading: 'দোকান খোলা হচ্ছে…', retry: 'আবার চেষ্টা করুন', curated: 'আপনার চেনা দোকানের সংগ্রহ', delivery: 'ঘরে বসেই অর্ডার', safe: 'পণ্য পেয়ে মূল্য পরিশোধ', footer: 'আপনার প্রয়োজনের আরও কাছাকাছি।', powered: 'Uddog-এর অনলাইন স্টোর', quoteHelp: 'বর্তমান দাম জানতে দোকানে যোগাযোগ করুন।', stockNotice: 'দোকান থেকে প্রাপ্যতা নিশ্চিত করা হবে।', failed: 'দোকান লোড করা যায়নি। আবার চেষ্টা করুন।', checkoutFailed: 'অনুরোধ পাঠানো যায়নি। আবার চেষ্টা করুন।', viewBag: 'ব্যাগ দেখুন', each: 'টি পণ্য', quantity: 'পরিমাণ', back: 'ব্যাগে ফিরুন' },
};
const t = key => copy[language.value][key];
const money = value => new Intl.NumberFormat(language.value === 'bn' ? 'bn-BD' : 'en-BD', { style: 'currency', currency: 'BDT', maximumFractionDigits: 2 }).format(Number(value));
const quantity = value => new Intl.NumberFormat(language.value === 'bn' ? 'bn-BD' : 'en-BD', { maximumFractionDigits: 3 }).format(Number(value));
const initialData = JSON.parse(document.getElementById('storefront-data')?.textContent ?? 'null');
const store = ref(initialData?.store ?? null);
const products = ref([]);
const detailProduct = ref(initialData?.product ?? null);
const loading = ref(!initialData);
const error = ref('');
const search = ref(''); const category = ref(''); const sort = ref('name');
const cart = ref([]); const bagOpen = ref(false); const checkoutOpen = ref(false); const busy = ref(false); const checkoutError = ref(''); const reference = ref('');
const form = ref({ customer_name: '', phone: '', address: '', notes: '' });
let checkoutToken = crypto.randomUUID();
const trackedOrders = ref(JSON.parse(localStorage.getItem('store-orders') || '[]'));
const trackingOpen = ref(false);
const trackingError = ref('');
const copiedReference = ref('');
async function copyReference(value) {
  try { await navigator.clipboard.writeText(value); copiedReference.value = value; }
  catch { checkoutError.value = language.value === 'bn' ? 'কপি করা যায়নি। রেফারেন্সটি নির্বাচন করে কপি করুন।' : 'Unable to copy. Select the reference and copy it manually.'; }
}
const trackingSearch = ref('');
const trackingResults = ref([]);
const trackingBusy = ref(false);
const trackingPage = ref(1);
const trackingLastPage = ref(1);
async function searchOrders(page = 1) {
  if (trackingBusy.value || !trackingSearch.value.trim()) return;
  trackingBusy.value = true; trackingError.value = '';
  try {
    const response = await fetch('/online-store/order-status?' + new URLSearchParams({search:trackingSearch.value.trim(), page}), {headers:{Accept:'application/json'}});
    const data = await response.json();
    if (!response.ok) throw new Error(language.value === 'bn' ? 'অর্ডার খোঁজা যায়নি। আবার চেষ্টা করুন।' : 'Unable to find orders. Please try again.');
    trackingResults.value = data.data; trackingPage.value = data.current_page; trackingLastPage.value = data.last_page;
    if (!data.data.length) trackingError.value = language.value === 'bn' ? 'কোনো অর্ডার পাওয়া যায়নি।' : 'No matching orders found.';
  } catch(exception) { trackingError.value = exception.message; }
  finally { trackingBusy.value = false; }
}
const statusLabel = status => ({expired:'Expired', pending:'Pending', accepted:'Order confirmed', rejected:'Rejected', cancelled:'Cancelled', placed:'Order placed', delivered:'Delivered', money_received:'Money received'}[status] || status);
async function refreshOrder(order, cancel = false) {
  trackingError.value = '';
  try {
    const payload = { number: order.number, token: order.token };
    const response = await fetch('/online-store/order-status' + (cancel ? '' : '?' + new URLSearchParams(payload)), { method: cancel ? 'POST' : 'GET', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, ...(cancel ? {body: JSON.stringify(payload)} : {}) });
    const data = await response.json();
    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || 'Unable to load order status.');
    order.status = data.status;
    localStorage.setItem('store-orders', JSON.stringify(trackedOrders.value));
  } catch (exception) { trackingError.value = exception.message; }
}
let bagOpener = null;
const failedImages = ref(new Set());
const categories = computed(() => [...new Set(products.value.map(product => product.category))]);
const visible = computed(() => products.value.filter(product => (!category.value || product.category === category.value) && product.name.toLocaleLowerCase().includes(search.value.toLocaleLowerCase())).sort((a, b) => sort.value === 'low' ? Number(a.price ?? Infinity) - Number(b.price ?? Infinity) : sort.value === 'high' ? Number(b.price ?? -1) - Number(a.price ?? -1) : a.name.localeCompare(b.name)));
const total = computed(() => cart.value.reduce((sum, item) => sum + Math.round(item.quantity * Number(item.price) * 100), 0) / 100);
const itemCount = computed(() => cart.value.length);
const cartValid = computed(() => cart.value.length > 0 && cart.value.every(item => Number.isFinite(Number(item.quantity)) && Number(item.quantity) > 0 && Number(item.quantity) <= stockLimit(item) && Math.abs(Number(item.quantity) / item.quantity_step - Math.round(Number(item.quantity) / item.quantity_step)) < 0.000001));
function stockLimit(product) { return Math.min(999999, Math.max(0, Number(product.available_quantity) || 0)); }
const stockWarning = ref('');
function warnStock(product) { stockWarning.value = language.value === 'bn' ? `${product.name}: এই পণ্যের আর স্টক নেই।` : `${product.name}: This product has no more stock.`; }
function normalizeQuantity(item) {
  const value = Number(item.quantity);
  const limit = stockLimit(item);
  if (value > limit) warnStock(item);
  item.quantity = Number(Math.min(limit, Math.max(item.quantity_step, Number.isFinite(value) ? Math.floor((value + 0.0000001) / item.quantity_step) * item.quantity_step : item.quantity_step)).toFixed(3));
}
const phoneLink = computed(() => store.value?.phone ? `tel:${store.value.phone.replace(/[^+\d]/g, '')}` : null);
async function load() {
  loading.value = true; error.value = '';
  try { const response = await fetch(productId ? `/online-store/products/${productId}` : '/online-store/catalog', { headers: { Accept: 'application/json' } }); if (!response.ok) throw new Error(); const data = await response.json(); store.value = data.store; products.value = data.products || []; detailProduct.value = data.product || null; document.title = `${data.product ? `${data.product.name} | ` : ''}${data.store.name} | Online store`; }
  catch { error.value = t('failed'); }
  finally { loading.value = false; }
}
function add(product) {
  if (!product.in_stock || product.requires_quote || busy.value) return;
  const existing = cart.value.find(item => item.id === product.id);
  if (existing) { if (Number(existing.quantity) + product.quantity_step > stockLimit(product)) warnStock(product); existing.quantity = Math.min(stockLimit(product), Number((Number(existing.quantity) + product.quantity_step).toFixed(3))); }
  else cart.value.push({ ...product, quantity: Math.min(stockLimit(product), product.quantity_step) });
}
function change(item, direction) {
  if (busy.value) return;
  const next = Number((item.quantity + direction * item.quantity_step).toFixed(3));
  if (next > stockLimit(item)) warnStock(item);
  if (next <= 0) cart.value = cart.value.filter(entry => entry.id !== item.id);
  else item.quantity = Math.min(stockLimit(item), next);
}
function closeBag() { if (!busy.value) { bagOpen.value = false; checkoutOpen.value = false; checkoutError.value = ''; } }
function onKey(event) {
  if (!bagOpen.value) return;
  if (event.key === 'Escape') closeBag();
  if (event.key === 'Tab') {
    const controls = [...document.querySelectorAll('.shop-cart a[href], .shop-cart button:not([disabled]), .shop-cart input:not([disabled]), .shop-cart textarea:not([disabled])')];
    const first = controls[0]; const last = controls.at(-1);
    if (!first) { event.preventDefault(); return; }
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  }
}
watch(bagOpen, open => {
  document.body.style.overflow = open ? 'hidden' : '';
  if (open) { bagOpener = document.activeElement; nextTick(() => document.querySelector('.shop-cart-head button')?.focus()); }
  else nextTick(() => (bagOpener?.isConnected ? bagOpener : document.querySelector('.shop-bag-button'))?.focus());
});
watch(language, value => { document.documentElement.lang = value; });
async function placeOrder() {
  if (busy.value || !cartValid.value) return;
  busy.value = true; checkoutError.value = '';
  try {
    const response = await fetch('/online-store/orders', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ ...form.value, checkout_token: checkoutToken, expected_total: total.value.toFixed(2), items: cart.value.map(item => ({ product_id: item.id, quantity: item.quantity })) }) });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.errors ? Object.values(data.errors).flat().join(' ') : t('checkoutFailed'));
    trackedOrders.value.unshift({number:data.number, token:checkoutToken, status:data.status});
    localStorage.setItem('store-orders', JSON.stringify(trackedOrders.value));
    reference.value = data.number; cart.value = []; form.value = { customer_name: '', phone: '', address: '', notes: '' }; checkoutToken = crypto.randomUUID(); checkoutOpen.value = false;
  } catch (exception) { checkoutError.value = exception.message; }
  finally { busy.value = false; }
}
onMounted(() => {
  if (!initialData) {
    load();
  }
  window.addEventListener('keydown', onKey);
});
onUnmounted(() => { window.removeEventListener('keydown', onKey); document.body.style.overflow = ''; });
</script>

<template>
  <div class="shop" :style="{'--store-primary':store?.appearance?.primary_color || '#173e36','--store-hero':store?.appearance?.hero_color || '#f0eee4','--store-accent':store?.appearance?.accent_color || '#b7794a'}">
<div v-if="trackingOpen" class="shop-tracking-backdrop" @click.self="trackingOpen = false"><section class="shop-tracking-modal" role="dialog" aria-modal="true" aria-labelledby="tracking-title" @keydown.esc="trackingOpen = false"><div class="shop-tracking-head"><h2 id="tracking-title">{{ language === 'bn' ? 'অর্ডার ট্র্যাক করুন' : 'Track your order' }}</h2><button aria-label="Close" @click="trackingOpen = false"><X :size="23" /></button></div><form @submit.prevent="searchOrders()"><label for="order-search">{{ language === 'bn' ? 'মোবাইল নম্বর অথবা অর্ডার রেফারেন্স' : 'Mobile number or order reference' }}</label><input id="order-search" v-model="trackingSearch" required maxlength="100" autocomplete="off" :placeholder="language === 'bn' ? '01712345678 অথবা WEB-…' : '01712345678 or WEB-…'"><button class="shop-primary" :disabled="trackingBusy">{{ trackingBusy ? '…' : language === 'bn' ? 'অর্ডার খুঁজুন' : 'Find orders' }}</button></form><p v-if="trackingError" class="shop-error" role="status">{{ trackingError }}</p><article v-for="order in trackingResults" :key="order.number" class="shop-tracking-result"><strong>{{ order.number }}</strong><span>{{ statusLabel(order.status) }}</span><small>{{ new Date(order.created_at).toLocaleDateString() }}</small><strong>{{ money(order.total) }}</strong><details class="shop-tracking-items"><summary>{{ language === 'bn' ? 'পণ্য ও মূল্য দেখুন' : 'View products and prices' }}</summary><div v-for="(item, index) in order.items" :key="index" class="shop-tracking-item"><strong>{{ item.name }}</strong><span>{{ quantity(item.quantity) }} {{ item.unit }} × {{ money(item.unit_price) }}</span><strong>{{ money(item.line_total) }}</strong></div><div class="shop-tracking-total"><span>{{ language === 'bn' ? 'সর্বমোট' : 'Order total' }}</span><strong>{{ money(order.total) }}</strong></div></details></article><div v-if="trackingLastPage > 1" class="shop-tracking-pages"><button :disabled="trackingBusy || trackingPage <= 1" @click="searchOrders(trackingPage - 1)">←</button><span>{{ trackingPage }} / {{ trackingLastPage }}</span><button :disabled="trackingBusy || trackingPage >= trackingLastPage" @click="searchOrders(trackingPage + 1)">→</button></div></section></div>
    <div v-if="stockWarning" class="shop-stock-warning" role="alert"><span>{{ stockWarning }}</span><button type="button" :aria-label="language === 'bn' ? 'বন্ধ করুন' : 'Dismiss'" @click="stockWarning = ''"><X :size="18" /></button></div>
    <div class="shop-announcement">{{ t('store') }} <span>✦</span> {{ t('cod') }}</div>
    <header class="shop-header"><a href="/online-store" class="shop-brand"><span class="shop-brand-mark"><ShoppingBag :size="23" /></span><span>{{ store?.name || 'Online store' }}<small>LOCAL FINDS, EVERYDAY GOOD</small></span></a><div class="shop-header-actions"><a v-if="phoneLink" :href="phoneLink" class="shop-contact"><Phone :size="16" />{{ t('contact') }}</a><select v-model="language" aria-label="Language"><option value="bn">বাংলা</option><option value="en">EN</option></select><button class="shop-orders-button" :aria-expanded="trackingOpen" @click="trackingOpen = true">{{ language === 'bn' ? 'আমার অর্ডার' : 'My orders' }}</button><button class="shop-bag-button" :aria-label="`${t('cart')} (${itemCount})`" aria-haspopup="dialog" @click="bagOpen = true; reference = ''"><ShoppingBag :size="20" /><span>{{ itemCount }}</span></button></div></header>
    <main v-if="loading || error" class="shop-status" role="status"><Package :size="42" /><h1>{{ error || t('loading') }}</h1><button v-if="error" class="shop-primary" @click="load">{{ t('retry') }}</button></main>
    <main v-else-if="detailProduct" class="shop-product-page">
      <a href="/online-store#collection" class="shop-detail-back"><ArrowLeft :size="18" />{{ t('backToProducts') }}</a>
      <article class="shop-detail">
        <div class="shop-detail-visual">
          <img v-if="productPhoto(detailProduct)" :src="productPhoto(detailProduct)" :alt="detailProduct.name" referrerpolicy="no-referrer" @error="failedImages.add($event.target.getAttribute('src'))">
          <div v-else class="shop-product-placeholder" aria-hidden="true"><Package :size="90" stroke-width="0.8" /><span>{{ detailProduct.name.slice(0, 24) }}</span></div>
          <div v-if="productPhotos(detailProduct).length > 1" class="shop-image-controls"><button type="button" :aria-label="language === 'bn' ? 'আগের ছবি' : 'Previous photo'" @click="changePhoto(detailProduct, -1)">‹</button><span>{{ (imageIndexes[detailProduct.id] || 0) % productPhotos(detailProduct).length + 1 }} / {{ productPhotos(detailProduct).length }}</span><button type="button" :aria-label="language === 'bn' ? 'পরের ছবি' : 'Next photo'" @click="changePhoto(detailProduct, 1)">›</button></div>
        </div>
        <div class="shop-detail-info">
          <span class="shop-product-category">{{ detailProduct.category }}</span>
          <h1>{{ detailProduct.name }}</h1>
          <div class="shop-detail-price"><strong>{{ detailProduct.requires_quote ? t('quote') : money(detailProduct.price) }}</strong><small v-if="!detailProduct.requires_quote">{{ t('per') }} {{ detailProduct.unit }}</small></div>
          <p class="shop-detail-stock" :class="{out: !detailProduct.in_stock}"><span></span><strong>{{ detailProduct.in_stock ? t('inStock') : t('unavailable') }}</strong></p>
          <a v-if="detailProduct.requires_quote && phoneLink" :href="phoneLink" class="shop-primary"><Phone :size="18" />{{ t('contact') }}</a>
          <button v-else-if="!detailProduct.requires_quote" class="shop-primary" :disabled="!detailProduct.in_stock" @click="add(detailProduct)"><Plus :size="19" />{{ t('add') }}</button>
          <a v-if="detailProduct.description" href="#product-description" class="shop-description-jump">{{ language === 'bn' ? 'পণ্যের বিস্তারিত পড়ুন' : 'Read product details' }} ↓</a>
        </div>
        <section v-if="detailProduct.description" id="product-description" class="shop-detail-description"><h2>{{ t('description') }}</h2><p>{{ detailProduct.description }}</p></section>
      </article>
    </main>
    <main v-else>
      <section class="shop-hero"><div class="shop-hero-copy"><span class="shop-eyebrow"><span></span>{{ t('curated') }}</span><h1>{{ store.appearance?.hero || t('intro') }}</h1><p>{{ store.appearance?.subtitle || t('subtitle') }}</p><div class="shop-hero-actions"><a href="#collection" class="shop-primary">{{ t('browse') }}<ArrowRight :size="18" /></a><a v-if="phoneLink" :href="phoneLink" class="shop-hero-contact"><Phone :size="16" />{{ t('contact') }}</a></div><div class="shop-hero-caption"><span class="shop-mini-icon"><Package :size="18" /></span><span>{{ products.length }} {{ t('products') }} · {{ categories.length }} {{ t('categoryCount') }}</span></div></div><div class="shop-hero-art" aria-hidden="true"><span class="shop-art-ring"></span><div class="shop-art-label">THE EVERYDAY EDIT <span>✦</span></div><div class="shop-art-bag"><ShoppingBag :size="114" stroke-width="1" /><span>{{ store.name }}</span></div><span class="shop-art-note">A little local.<br>A lot to love.</span><span class="shop-art-star">✳</span></div></section>
      <div class="shop-promises"><span><Package :size="19" />{{ t('curated') }}</span><span><Truck :size="19" />{{ t('delivery') }}</span><span><ShieldCheck :size="19" />{{ t('safe') }}</span></div>
      <section id="collection" class="shop-collection"><div class="shop-section-title"><div><span class="shop-eyebrow">{{ t('collections') }}</span><h2>{{ category || t('all') }}<span>{{ visible.length }}</span></h2></div><div class="shop-filters"><label class="shop-search"><Search :size="18" /><input v-model="search" :placeholder="t('search')" :aria-label="t('search')"></label><label class="shop-sort"><SlidersHorizontal :size="16" /><select v-model="sort" :aria-label="t('sort')"><option value="name">{{ t('name') }}</option><option value="low">{{ t('low') }}</option><option value="high">{{ t('high') }}</option></select></label></div></div>
        <nav class="shop-categories" aria-label="Product categories"><button :class="{active: !category}" @click="category = ''">{{ t('all') }}</button><button v-for="name in categories" :key="name" :class="{active: category === name}" @click="category = name">{{ name }}</button></nav>
        <div v-if="!visible.length" class="shop-status"><Package :size="40" /><h3>{{ t('noResults') }}</h3><p>{{ t('noResultsHelp') }}</p><button v-if="search || category" class="shop-primary" @click="search = ''; category = ''">{{ t('reset') }}</button></div>
        <div class="shop-product-grid"><article v-for="(product, index) in visible" :key="product.id" class="shop-product"><div class="shop-product-visual" :class="`tone-${index % 4}`"><a :href="`/online-store/products/${product.id}`" class="shop-product-visual-link"><img v-if="productPhoto(product)" @error="failedImages.add($event.target.getAttribute('src'))" :src="productPhoto(product)" :alt="product.name" loading="lazy" referrerpolicy="no-referrer"><div v-else class="shop-product-placeholder" aria-hidden="true"><Package :size="64" stroke-width="0.8" /><span>{{ product.name.slice(0, 18) }}</span></div></a><div v-if="productPhotos(product).length > 1" class="shop-image-controls"><button type="button" :aria-label="language === 'bn' ? 'আগের ছবি' : 'Previous photo'" @click.stop="changePhoto(product, -1)">‹</button><span>{{ (imageIndexes[product.id] || 0) % productPhotos(product).length + 1 }} / {{ productPhotos(product).length }}</span><button type="button" :aria-label="language === 'bn' ? 'পরের ছবি' : 'Next photo'" @click.stop="changePhoto(product, 1)">›</button></div><span class="shop-stock-badge" :class="{out: !product.in_stock}"><i></i>{{ product.in_stock ? t('inStock') : t('unavailable') }}</span></div><div class="shop-product-info"><span class="shop-product-category">{{ product.category }}</span><h3><a :href="`/online-store/products/${product.id}`">{{ product.name }}</a></h3><p class="shop-product-availability" :class="{out: !product.in_stock}"><strong>{{ product.in_stock ? t('inStock') : t('unavailable') }}</strong></p><div class="shop-product-bottom"><div><strong>{{ product.requires_quote ? t('quote') : money(product.price) }}</strong><small v-if="!product.requires_quote">{{ t('per') }} {{ product.unit }}</small></div><a v-if="product.requires_quote && phoneLink" :href="phoneLink" class="shop-add" :aria-label="`${t('contact')}: ${product.name}`"><Phone :size="18" /></a><button v-else-if="!product.requires_quote" class="shop-add" :disabled="!product.in_stock" :aria-label="`${t('add')}: ${product.name}`" @click="add(product)"><Plus :size="22" /></button></div><a :href="`/online-store/products/${product.id}`" class="shop-details-link">{{ t('details') }}<ArrowRight :size="14" /></a><small v-if="product.requires_quote" class="shop-quote-help">{{ t('quoteHelp') }}</small></div></article></div>
      </section>
      <section class="shop-contact-banner"><div><span class="shop-eyebrow">YOUR LOCAL STORE</span><h2>{{ t('footer') }}</h2><p v-if="store.address"><MapPin :size="16" />{{ store.address }}</p></div><a v-if="phoneLink" :href="phoneLink" class="shop-primary">{{ t('contact') }}<ArrowUpRight :size="18" /></a></section>
    </main>
    <footer class="shop-footer"><strong>{{ store?.name || 'Online store' }}</strong><span>{{ t('powered') }}</span><a v-if="phoneLink" :href="phoneLink">{{ store.phone }}</a></footer>
    <button v-if="itemCount && !bagOpen" class="shop-floating-cart" @click="bagOpen = true"><ShoppingBag :size="20" />{{ t('viewBag') }} ({{ itemCount }})<strong>{{ money(total) }}</strong><ArrowRight :size="18" /></button>
    <div v-if="bagOpen" class="shop-backdrop" @click.self="closeBag"><section class="shop-cart" role="dialog" aria-modal="true" :aria-label="t('cart')"><div class="shop-cart-head"><h2>{{ t('cart') }} <span>{{ itemCount }}</span></h2><button :aria-label="t('continue')" :disabled="busy" @click="closeBag"><X :size="23" /></button></div>
      <div v-if="reference" class="shop-cart-success" role="status"><span><Check :size="32" /></span><h2>{{ t('success') }}</h2><p>{{ t('successHelp') }}</p><div class="shop-reference"><strong>{{ reference }}</strong><button type="button" :aria-label="language === 'bn' ? 'রেফারেন্স কপি করুন' : 'Copy reference'" @click="copyReference(reference)"><Copy :size="20" /></button></div><small v-if="copiedReference === reference" role="status">{{ language === 'bn' ? 'কপি হয়েছে' : 'Copied' }}</small><button class="shop-primary" @click="closeBag">{{ t('continue') }}</button></div>
      <div v-else-if="!itemCount" class="shop-status"><ShoppingBag :size="48" /><h3>{{ t('empty') }}</h3><p>{{ t('emptyHelp') }}</p><button class="shop-primary" @click="closeBag">{{ t('continue') }}</button></div>
      <template v-else><div class="shop-cart-content"><template v-if="!checkoutOpen"><article v-for="item in cart" :key="item.id" class="shop-cart-item"><div class="shop-cart-icon"><Package :size="28" /></div><div><h3>{{ item.name }}</h3><small>{{ money(item.price) }} / {{ item.unit }}</small><div class="shop-quantity"><button :disabled="busy" :aria-label="`${t('remove')}: ${item.name}`" @click="change(item, -1)"><Minus :size="14" /></button><input v-model.number="item.quantity" type="number" :min="item.quantity_step" :max="stockLimit(item)" :step="item.quantity_step" @change="normalizeQuantity(item)" :aria-label="`${t('quantity')}: ${item.name}`" :disabled="busy"><button :disabled="busy" :aria-label="`${t('add')}: ${item.name}`" @click="change(item, 1)"><Plus :size="14" /></button></div></div><strong>{{ money(item.price * item.quantity) }}</strong></article></template>
      <form v-else id="shop-checkout" @submit.prevent="placeOrder"><button type="button" class="shop-back" :disabled="busy" @click="checkoutOpen = false">← {{ t('back') }}</button><label>{{ t('customer') }}<input v-model.trim="form.customer_name" required maxlength="100" autocomplete="name" :disabled="busy"></label><label>{{ t('phone') }}<input v-model.trim="form.phone" type="tel" required pattern="\+?[0-9]{7,15}" maxlength="16" autocomplete="tel" placeholder="01712345678" :disabled="busy"></label><label>{{ t('address') }}<textarea v-model.trim="form.address" required maxlength="1000" rows="3" autocomplete="street-address" :disabled="busy"></textarea></label><label>{{ t('notes') }}<textarea v-model.trim="form.notes" maxlength="1000" rows="2" :disabled="busy"></textarea></label></form>
      <div class="shop-cod-note"><Truck :size="22" /><div><strong>{{ t('cod') }}</strong><p>{{ t('codHelp') }}</p></div></div><p v-if="checkoutError" class="shop-error" role="alert">{{ checkoutError }}</p></div><div class="shop-cart-footer"><div><span>{{ t('total') }}</span><strong>{{ money(total) }}</strong></div><button v-if="!checkoutOpen" class="shop-primary" :disabled="!cartValid" @click="checkoutOpen = true">{{ t('checkout') }}<ArrowRight :size="18" /></button><button v-else type="submit" form="shop-checkout" class="shop-primary" :disabled="busy || !cartValid">{{ busy ? t('sending') : t('place') }}<ArrowRight :size="18" /></button><small>{{ t('stockNotice') }}</small></div></template>
    </section></div>
  </div>
</template>
