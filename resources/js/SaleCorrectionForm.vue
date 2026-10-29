<script setup>
import { computed, ref } from 'vue';
import { translate } from './localization';

const props = defineProps({ sale: { type: Object, required: true }, busy: Boolean, language: String, money: Function, qty: Function });
const emit = defineEmits(['save', 'cancel']);
const t = text => translate(text, props.language);
const form = ref({
  correction_version: props.sale.correction_version,
  discount: props.sale.discount, tax: props.sale.tax, reason: '',
  items: props.sale.items.map(item => ({ id: item.id, quantity: item.entered_quantity ?? item.quantity, unit_price: item.entered_unit_price ?? item.unit_price })),
});
const cents = value => Math.round(Number(value || 0) * 100);
const lineCents = item => Math.round(Number(item.quantity || 0) * cents(item.unit_price));
const subtotal = computed(() => form.value.items.reduce((sum, item) => sum + lineCents(item), 0));
const total = computed(() => subtotal.value - cents(form.value.discount) + cents(form.value.tax));
const due = computed(() => total.value - cents(props.sale.amount_paid));
const changes = computed(() => form.value.items.some((item, index) => {
  const original = props.sale.items[index];
  return Number(item.quantity) !== Number(original.entered_quantity ?? original.quantity)
    || cents(item.unit_price) !== cents(original.entered_unit_price ?? original.unit_price);
}) || cents(form.value.discount) !== cents(props.sale.discount) || cents(form.value.tax) !== cents(props.sale.tax));
const blocked = computed(() => props.sale.payment_type === 'installment' || props.sale.installments?.length
  || Number(props.sale.interest_amount) > 0 || Number(props.sale.gold_exchange_value) > 0 || props.sale.items.some(item => item.product?.is_gold_item || item.gold_purity != null || item.gold_gross_weight != null));
const valid = computed(() => !blocked.value && changes.value && form.value.reason.trim() && due.value >= 0 && total.value >= 0
  && cents(form.value.discount) <= subtotal.value && form.value.items.every(item => Number(item.quantity) > 0 && Number(item.unit_price) >= 0));
function stockDifference(item, index) {
  const original = props.sale.items[index];
  const oldStock = Number(original.stock_quantity ?? original.quantity);
  const base = Number(item.quantity) * Number(original.unit_conversion || 1);
  return oldStock - Math.round(base / Number(original.quantity) * oldStock * 1000) / 1000;
}
</script>

<template>
  <form class="sale-correction" @submit.prevent="valid && !busy && emit('save', form)">
    <p class="payment-plan-note">{{ sale.number }} · {{ sale.contact?.name }}<br>{{ t('Corrects the original invoice, stock balances and reports. Every change is recorded with your reason.') }}</p>
    <p v-if="blocked" class="alert-box error" role="alert">{{ t('Installment and gold sales require separate reconciliation and cannot be corrected here.') }}</p>
    <div v-for="(item, index) in form.items" :key="item.id" class="correction-line">
      <div class="correction-product"><strong>{{ sale.items[index].product?.name }}</strong><small>{{ t('Original') }}: {{ qty(sale.items[index].entered_quantity ?? sale.items[index].quantity) }} {{ sale.items[index].entered_unit || sale.items[index].product?.unit }} × {{ money(sale.items[index].entered_unit_price ?? sale.items[index].unit_price) }}</small></div>
      <label class="field"><span>{{ t('Correct quantity') }} ({{ sale.items[index].entered_unit || sale.items[index].product?.unit }})</span><input v-model="item.quantity" type="number" min="0.001" max="99999999999.999" step="0.001" required :disabled="busy || blocked"></label>
      <label class="field"><span>{{ t('Correct unit price') }}</span><input v-model="item.unit_price" type="number" min="0" max="999999999999.99" step="0.01" required :disabled="busy || blocked"></label>
      <div class="correction-line-total"><strong>{{ money(lineCents(item) / 100) }}</strong><small>{{ t('Stock adjustment') }}: {{ stockDifference(item, index) > 0 ? '+' : '' }}{{ qty(stockDifference(item, index)) }} {{ t('base units') }}</small></div>
    </div>
    <div class="form-grid">
      <label class="field"><span>{{ t('Discount') }}</span><input v-model="form.discount" type="number" min="0" :max="subtotal / 100" step="0.01" required :disabled="busy || blocked"></label>
      <label class="field"><span>{{ t('Tax') }}</span><input v-model="form.tax" type="number" min="0" max="999999999999.99" step="0.01" required :disabled="busy || blocked"></label>
      <label class="field span-2"><span>{{ t('Correction reason') }} *</span><textarea v-model="form.reason" maxlength="1000" rows="3" required :disabled="busy || blocked" :placeholder="t('Example: Entered 45 instead of 4 by mistake.')"></textarea></label>
    </div>
    <div class="correction-preview" aria-live="polite">
      <div><span>{{ t('Original total') }}</span><strong>{{ money(sale.total) }}</strong></div>
      <div><span>{{ t('Corrected total') }}</span><strong>{{ money(total / 100) }}</strong></div>
      <div><span>{{ t('Paid') }}</span><strong>{{ money(sale.amount_paid) }}</strong></div>
      <div><span>{{ t('Corrected due') }}</span><strong>{{ money(Math.max(0, due) / 100) }}</strong></div>
    </div>
    <p v-if="due < 0" class="alert-box error" role="alert">{{ t('Payments exceed the corrected total. Remove the excess related payments before correcting this sale.') }}</p>
    <p class="correction-help">{{ t('Discount and tax are fixed amounts. Review them before saving. Existing payments remain recorded.') }}</p>
    <div class="modal-actions"><button type="button" class="btn-secondary-app" :disabled="busy" @click="emit('cancel')">{{ t('Cancel') }}</button><button class="btn-primary-app" :disabled="busy || !valid">{{ t(busy ? 'Saving...' : 'Save correction') }}</button></div>
  </form>
</template>

<style scoped>
.correction-line{display:grid;grid-template-columns:1fr 150px 150px;gap:14px;padding:16px 0;border-bottom:1px solid #ececf2;margin-bottom:16px}.correction-product{grid-column:1/-1;display:flex;flex-direction:column;gap:5px}.correction-product small,.correction-line-total small,.correction-help{color:#73788a;font-size:12px;line-height:1.5}.correction-line-total{display:flex;flex-direction:column;justify-content:center;gap:5px}.correction-preview{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;padding:18px;background:#f5f4ff;border-radius:10px;margin:18px 0}.correction-preview>div{display:flex;flex-direction:column;gap:6px}.correction-preview span{color:#73788a;font-size:12px}.correction-preview strong{font-size:18px}.sale-correction textarea{width:100%;resize:vertical}@media(max-width:600px){.correction-line{grid-template-columns:1fr 1fr}.correction-line-total{grid-column:1/-1}}
</style>
