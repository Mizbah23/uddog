<script setup>
import { onMounted, ref } from 'vue';
import CourierSettings from './CourierSettings.vue';
import { translate } from './localization';
const props = defineProps({ api: Function, money: Function, date: Function, language: String });
const emit = defineEmits(['updated']);
const orders = ref([]); const currentPage = ref(1); const lastPage = ref(1); const busy = ref(false); const error = ref('');
const courierEnabled = ref(null);
const courierSaving = ref(false);
const trackingCodes = ref({});
const flagsAvailable = ref(false);
const flaggedOnly = ref(false);
const flagReasons = ref({});
const t = value => translate(value, props.language);
function needsCourierReview(order) {
  const booking = order.courier_booking;
  return booking?.state === 'review' || (booking?.state === 'booking' && Date.now() / 1000 - booking.started_at >= 60);
}
async function load(page = 1) {
  busy.value = true; error.value = '';
  try {
    const result = await props.api(`ecommerce/orders?page=${page}&flagged_only=${flaggedOnly.value ? 1 : 0}`);
    orders.value = result.data;
    currentPage.value = result.current_page;
    lastPage.value = result.last_page;
    flagsAvailable.value = result.flags_available;
  }
  catch (exception) { error.value = exception.message; }
  finally { busy.value = false; }
}
async function updateFlag(order, flagged) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await props.api(`ecommerce/orders/${order.id}/flag`, {
      method: 'PUT',
      body: { flagged, reason: flagged ? flagReasons.value[order.id]?.trim() : null },
    });
    delete flagReasons.value[order.id];
    await load(flaggedOnly.value && !flagged ? 1 : currentPage.value);
  } catch (exception) {
    error.value = exception.message;
  } finally {
    busy.value = false;
  }
}
async function process(order, action) {
  const confirmation = {
    place: courierEnabled.value ? t('Book this shipment with Steadfast?') : t('Mark this order as placed?'),
    confirm_courier: t('Use this tracking code for the shipment you verified in Steadfast?'),
    reset_courier: t('Confirm you checked Steadfast and no shipment exists for this invoice.'),
    receive: 'Confirm cash has been received? This records the remaining sale balance as a cash payment and marks the order Money received.',
    accept: t('Accept this order and create a due sale? Stock will be deducted. Record the payment after collecting it.'),
  };
  if (busy.value || courierSaving.value || (action === 'place' && courierEnabled.value === null) || !confirm(confirmation[action] || `Update order: ${action}?`)) return;
  busy.value = true; error.value = '';
  try {
    const body = { action };
    if (action === 'confirm_courier') body.tracking_code = trackingCodes.value[order.id]?.trim();
    await props.api(`ecommerce/orders/${order.id}/process`, { method: 'POST', body });
    delete trackingCodes.value[order.id];
    await load(currentPage.value);
    emit('updated');
  }
  catch (exception) { error.value = exception.message; }
  finally { busy.value = false; }
}
onMounted(() => load());
</script>
<template>
  <section class="online-orders">
    <div class="page-heading"><div><span class="eyebrow">E-COMMERCE</span><h1>{{ t('Online orders') }}</h1><p>{{ t('Cash-on-delivery requests wait here for your confirmation. Accepting an order creates a due sale and deducts stock.') }}</p></div><a class="btn-primary-app" href="/online-store" target="_blank" rel="noopener">{{ t('Open online store') }} ↗</a></div>
    <p class="payment-plan-note">{{ t('Your storefront uses your active default branch. Set the branch phone and address so customers can contact your store. Product visibility is managed in Products.') }}</p>
    <div v-if="error" class="alert-box error" role="alert">{{ error }}</div>
    <CourierSettings :api="api" :language="language" :disabled="busy" @loaded="courierEnabled = $event" @saving="courierSaving = $event" />
    <div class="table-toolbar">
      <h2>{{ t('Online orders') }}</h2>
      <div class="order-toolbar-actions">
        <label v-if="flagsAvailable"><input v-model="flaggedOnly" type="checkbox" :disabled="busy" @change="load(1)"> {{ t('Flagged orders only') }}</label>
        <button class="btn-secondary-app small" :disabled="busy" @click="load(currentPage)">{{ t('Refresh') }}</button>
      </div>
    </div>
    <div v-if="!busy && !orders.length" class="panel empty-state">
      <strong>{{ t(flaggedOnly ? 'No flagged orders' : 'No online orders yet') }}</strong>
      <span v-if="!flaggedOnly">{{ t('Show products in your online store and share the storefront link with customers.') }}</span>
    </div>
    <article v-for="order in orders" :key="order.id" class="panel online-order">
      <div class="online-order-heading"><div><strong>{{ order.number }}</strong><small>{{ date(order.created_at) }} · {{ order.customer_name }}</small></div><span class="stock-pill" :class="order.status === 'accepted' ? 'good' : order.status === 'pending' ? 'pending' : 'low'">{{ t(order.status) }}</span></div>
      <p><a :href="`tel:${order.phone}`">{{ order.phone }}</a> · {{ order.address }}</p><p v-if="order.notes">{{ order.notes }}</p>
      <div v-if="order.flagged_at || order.other_flagged_orders" class="order-review">
        <strong v-if="order.flagged_at">{{ t('Flagged as suspicious') }} · {{ date(order.flagged_at) }}</strong>
        <span v-if="order.flag_reason">{{ order.flag_reason }}</span>
        <span v-if="order.other_flagged_orders">{{ t('Other flagged orders with this phone number') }} ({{ order.other_flagged_orders }})</span>
      </div>
      <p v-if="order.courier_booking?.tracking_code"><strong>{{ t('Steadfast tracking code') }}</strong> · {{ order.courier_booking.tracking_code }}</p>
      <div v-else-if="needsCourierReview(order)" class="alert-box">
        <p>{{ t('Check Steadfast for this invoice before retrying:') }} <strong>{{ order.courier_booking.invoice }}</strong></p>
        <form class="online-order-actions" @submit.prevent="process(order, 'confirm_courier')">
          <label class="field"><span>{{ t('Steadfast tracking code') }}</span><input v-model.trim="trackingCodes[order.id]" required maxlength="100" pattern="[A-Za-z0-9_\-]+"></label>
          <button class="btn-secondary-app small" type="submit" :disabled="busy">{{ t('Save existing booking') }}</button>
          <button class="btn-secondary-app small" type="button" :disabled="busy" @click="process(order, 'reset_courier')">{{ t('No booking found') }}</button>
        </form>
      </div>
      <p v-else-if="order.courier_booking?.state === 'booking'">{{ t('Booking in progress. Refresh to check the result.') }}</p>
      <p v-else-if="order.courier_booking?.state === 'credentials_rejected'">{{ t('Steadfast rejected the credentials. Update courier settings before retrying.') }}</p>
      <button v-if="!order.processed_by && ['expired', 'cancelled'].includes(order.status)" class="btn-secondary-app small" :disabled="busy" @click="process(order, 'cancel')">Close order</button>
      <div class="online-order-actions"><button v-if="order.status === 'delivered'" class="btn-primary-app small" :disabled="busy" @click="process(order, 'receive')">Money received (cash)</button><strong v-if="order.status === 'money_received'">Money received</strong><button v-if="order.status === 'pending'" class="btn-secondary-app small" :disabled="busy" @click="process(order, 'reject')">Reject order</button><button v-if="order.status === 'accepted' && !needsCourierReview(order) && order.courier_booking?.state !== 'booking'" class="btn-primary-app small" :disabled="busy || courierSaving || courierEnabled === null" @click="process(order, 'place')">{{ t(courierEnabled ? 'Book courier' : 'Order placed') }}</button><button v-if="order.status === 'placed'" class="btn-primary-app small" :disabled="busy" @click="process(order, 'deliver')">Delivered</button><strong v-if="order.status === 'accepted'">Order confirmed</strong></div>
      <div v-for="item in order.items" :key="item.product_id" class="detail-line"><span>{{ item.name }}</span><span>{{ item.quantity }} {{ item.unit }} × {{ money(item.unit_price) }}</span><strong>{{ money(item.line_total) }}</strong></div>
      <div v-if="flagsAvailable" class="order-flag-actions">
        <button v-if="order.flagged_at" type="button" class="btn-secondary-app small" :disabled="busy" @click="updateFlag(order, false)">{{ t('Remove flag') }}</button>
        <details v-else>
          <summary>{{ t('Flag suspicious order') }}</summary>
          <form class="order-flag-form" @submit.prevent="updateFlag(order, true)">
            <label class="field"><span>{{ t('Reason for flagging (optional)') }}</span><input v-model="flagReasons[order.id]" maxlength="255" :disabled="busy"></label>
            <button type="submit" class="btn-secondary-app small" :disabled="busy">{{ t('Save flag') }}</button>
          </form>
        </details>
      </div>
      <div class="online-order-actions"><strong>{{ t('Total') }}: {{ money(order.total) }}</strong><span v-if="order.document_id">{{ t('Sale') }} #{{ order.document_id }}</span><template v-if="order.status === 'pending'"><button class="btn-secondary-app small" :disabled="busy" @click="process(order, 'cancel')">{{ t('Cancel order') }}</button><button class="btn-primary-app small" :disabled="busy" @click="process(order, 'accept')">{{ t('Accept and create sale') }}</button></template></div>
    </article>
    <div v-if="lastPage > 1" class="online-order-actions"><button class="btn-secondary-app small" :disabled="busy || currentPage <= 1" @click="load(currentPage - 1)">←</button><span>{{ currentPage }} / {{ lastPage }}</span><button class="btn-secondary-app small" :disabled="busy || currentPage >= lastPage" @click="load(currentPage + 1)">→</button></div>
  </section>
</template>
<style scoped>
.order-toolbar-actions, .order-flag-form { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
.order-toolbar-actions label { display: flex; align-items: center; gap: 6px; font-size: 12px; }
.order-review { display: grid; gap: 5px; padding: 10px 12px; margin-block: 12px; border-radius: 8px; color: #713d11; background: #fff3e3; font-size: 13px; overflow-wrap: anywhere; }
.order-flag-actions { margin-top: 14px; }
.order-flag-actions summary { cursor: pointer; color: #713d11; font-size: 12px; font-weight: 700; }
.order-flag-form { align-items: end; margin-top: 8px; }
.order-flag-form .field { flex: 1 1 200px; min-width: 0; }
.online-order{padding:22px;margin:16px 0}.online-order-heading,.online-order-actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap;justify-content:space-between}.online-order-heading small{display:block;margin-top:5px;color:#898b9d}.online-order p{margin:16px 0;white-space:pre-wrap;overflow-wrap:anywhere;font-size:13px;color:#62677a}.online-order-actions{margin-top:18px}.online-order-actions>strong{margin-right:auto}.online-orders>.payment-plan-note{margin-bottom:18px}
</style>
