<script setup>
import { onMounted, ref } from 'vue';
import { translate } from './localization';
const props = defineProps({ api: Function, money: Function, date: Function, language: String });
const emit = defineEmits(['updated']);
const orders = ref([]); const currentPage = ref(1); const lastPage = ref(1); const busy = ref(false); const error = ref('');
const t = value => translate(value, props.language);
async function load(page = 1) {
  busy.value = true; error.value = '';
  try { const result = await props.api(`ecommerce/orders?page=${page}`); orders.value = result.data; currentPage.value = result.current_page; lastPage.value = result.last_page; }
  catch (exception) { error.value = exception.message; }
  finally { busy.value = false; }
}
async function process(order, action) {
  if (busy.value || !confirm(t(action === 'accept' ? 'Accept this order and create a due sale? Stock will be deducted. Record the payment after collecting it.' : 'Cancel this online order?'))) return;
  busy.value = true; error.value = '';
  try { await props.api(`ecommerce/orders/${order.id}/process`, { method: 'POST', body: { action } }); await load(currentPage.value); emit('updated'); }
  catch (exception) { error.value = exception.message; }
  finally { busy.value = false; }
}
onMounted(() => load());
</script>
<template>
  <section class="online-orders">
    <div class="page-heading"><div><span class="eyebrow">E-COMMERCE</span><h1>{{ t('Online orders') }}</h1><p>{{ t('Cash-on-delivery requests wait here for your confirmation. Accepting an order creates a due sale and deducts stock.') }}</p></div><a class="btn-primary-app" href="/ecomm" target="_blank" rel="noopener">{{ t('Open online store') }} ↗</a></div>
    <p class="payment-plan-note">{{ t('Your storefront uses your active default branch. Set the branch phone and address so customers can contact your store. Product visibility is managed in Products.') }}</p>
    <div v-if="error" class="alert-box error" role="alert">{{ error }}</div>
    <div class="table-toolbar"><h2>{{ t('Online orders') }}</h2><button class="btn-secondary-app small" :disabled="busy" @click="load(currentPage)">{{ t('Refresh') }}</button></div>
    <div v-if="!orders.length" class="panel empty-state"><strong>{{ t('No online orders yet') }}</strong><span>{{ t('Show products in your online store and share the storefront link with customers.') }}</span></div>
    <article v-for="order in orders" :key="order.id" class="panel online-order">
      <div class="online-order-heading"><div><strong>{{ order.number }}</strong><small>{{ date(order.created_at) }} · {{ order.customer_name }}</small></div><span class="stock-pill" :class="order.status === 'accepted' ? 'good' : order.status === 'pending' ? 'pending' : 'low'">{{ t(order.status) }}</span></div>
      <p><a :href="`tel:${order.phone}`">{{ order.phone }}</a> · {{ order.address }}</p><p v-if="order.notes">{{ order.notes }}</p>
      <div v-for="item in order.items" :key="item.product_id" class="detail-line"><span>{{ item.name }}</span><span>{{ item.quantity }} {{ item.unit }} × {{ money(item.unit_price) }}</span><strong>{{ money(item.line_total) }}</strong></div>
      <div class="online-order-actions"><strong>{{ t('Total') }}: {{ money(order.total) }}</strong><span v-if="order.document_id">{{ t('Sale') }} #{{ order.document_id }}</span><template v-if="order.status === 'pending'"><button class="btn-secondary-app small" :disabled="busy" @click="process(order, 'cancel')">{{ t('Cancel order') }}</button><button class="btn-primary-app small" :disabled="busy" @click="process(order, 'accept')">{{ t('Accept and create sale') }}</button></template></div>
    </article>
    <div v-if="lastPage > 1" class="online-order-actions"><button class="btn-secondary-app small" :disabled="busy || currentPage <= 1" @click="load(currentPage - 1)">←</button><span>{{ currentPage }} / {{ lastPage }}</span><button class="btn-secondary-app small" :disabled="busy || currentPage >= lastPage" @click="load(currentPage + 1)">→</button></div>
  </section>
</template>
<style scoped>
.online-order{padding:22px;margin:16px 0}.online-order-heading,.online-order-actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap;justify-content:space-between}.online-order-heading small{display:block;margin-top:5px;color:#898b9d}.online-order p{margin:16px 0;white-space:pre-wrap;overflow-wrap:anywhere;font-size:13px;color:#62677a}.online-order-actions{margin-top:18px}.online-order-actions>strong{margin-right:auto}.online-orders>.payment-plan-note{margin-bottom:18px}
</style>
