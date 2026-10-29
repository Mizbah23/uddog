<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import SupplierCompanyPicker from './SupplierCompanyPicker.vue';

const props = defineProps({
  request: { type: Function, required: true },
  branchId: { type: [Number, String], required: true },
  money: { type: Function, required: true },
  date: { type: Function, required: true },
  language: { type: String, required: true },
  canViewReport: { type: Boolean, required: true },
});
const advances = ref([]);
const deliveries = ref([]);
const entries = ref([]);
const error = ref('');
const busy = ref(false);
const currentDate = () => new Date().toLocaleDateString('en-CA');
const form = ref({ company_name: '', amount: '', advance_date: currentDate(), payment_method: 'bank_transfer', reference: '', notes: '' });
const delivery = ref({ company_name: '', installment_code: '', product_details: '', amount: '', delivery_date: currentDate(), notes: '' });
const advanceType = ref('advance');
const receiptType = ref('delivery');
const advanceParticular = ref('');
const receiptParticular = ref('');
const paidEntryTypes = ['opening_paid', 'incentive', 'commission', 'credit'];
const companies = computed(() => [...new Map([...advances.value, ...entries.value].map(item => [item.company_name.trim().toLocaleLowerCase(), item.company_name.trim()])).values()].sort((a, b) => a.localeCompare(b)));
const totalSent = computed(() => advances.value.reduce((sum, item) => sum + Number(item.amount), 0) + entries.value.filter(item => paidEntryTypes.includes(item.entry_type)).reduce((sum, item) => sum + Number(item.amount), 0));
const totalReceived = computed(() => deliveries.value.reduce((sum, item) => sum + Number(item.amount), 0) + entries.value.filter(item => !paidEntryTypes.includes(item.entry_type)).reduce((sum, item) => sum + Number(item.amount), 0));
const companyRows = computed(() => companies.value.map(name => {
  const key = name.toLocaleLowerCase();
  const sent = advances.value.filter(item => item.company_name.trim().toLocaleLowerCase() === key);
  const received = deliveries.value.filter(item => item.company_name?.trim().toLocaleLowerCase() === key);
  const adjustments = entries.value.filter(item => item.company_name.trim().toLocaleLowerCase() === key);
  const paid = adjustments.filter(item => paidEntryTypes.includes(item.entry_type));
  const receivedAdjustments = adjustments.filter(item => !paidEntryTypes.includes(item.entry_type));
  return { name, sent, received, adjustments, amount: sent.reduce((sum, item) => sum + Number(item.amount), 0) + paid.reduce((sum, item) => sum + Number(item.amount), 0), delivered: received.reduce((sum, item) => sum + Number(item.amount), 0) + receivedAdjustments.reduce((sum, item) => sum + Number(item.amount), 0) };
}));
function label(en, bn) { return props.language === 'bn' ? bn : en; }

async function load() {
  if (!props.branchId) return;
  try { const data = await props.request(`supplier-advances?branch_id=${props.branchId}`); advances.value = data.advances; deliveries.value = data.deliveries; entries.value = data.entries ?? []; }
  catch (exception) { error.value = exception.message; }
}
async function saveAdvance() {
  error.value = ''; busy.value = true;
  try {
    if (advanceType.value === 'advance') {
      await props.request('supplier-advances', { method: 'POST', body: { ...form.value, branch_id: props.branchId } });
    } else {
      await props.request('supplier-tt-entries', { method: 'POST', body: {
        company_name: form.value.company_name, entry_type: advanceType.value, particular: advanceParticular.value,
        amount: form.value.amount, entry_date: form.value.advance_date, notes: form.value.notes, branch_id: props.branchId,
      } });
    }
    form.value = { ...form.value, amount: '', reference: '', notes: '' };
    advanceParticular.value = '';
    await load();
  } catch (exception) { error.value = exception.message; }
  finally { busy.value = false; }
}
async function saveDelivery() {
  error.value = ''; busy.value = true;
  try {
    if (receiptType.value === 'delivery') {
      await props.request('supplier-advance-deliveries', {
        method: 'POST', body: { ...delivery.value, branch_id: props.branchId },
      });
    } else {
      await props.request('supplier-tt-entries', { method: 'POST', body: {
        company_name: delivery.value.company_name, entry_type: receiptType.value, particular: receiptParticular.value,
        amount: delivery.value.amount, entry_date: delivery.value.delivery_date, notes: delivery.value.notes, branch_id: props.branchId,
      } });
    }
    delivery.value = { ...delivery.value, installment_code: '', product_details: '', amount: '', notes: '' };
    receiptParticular.value = '';
    await load();
  } catch (exception) { error.value = exception.message; }
  finally { busy.value = false; }
}
async function removeDelivery(part) {
  if (!confirm(label(`Remove installment ${part.installment_code}?`, `${part.installment_code} কিস্তিটি মুছবেন?`))) return;
  error.value = ''; busy.value = true;
  try { await props.request(`supplier-advance-deliveries/${part.id}`, { method: 'DELETE' }); await load(); }
  catch (exception) { error.value = exception.message; }
  finally { busy.value = false; }
}
async function removeAdvance(advance) {
  if (!confirm(label(`Delete advance ${advance.reference || `ADV-${advance.id}`}?`, 'অগ্রিমের হিসাবটি মুছবেন?'))) return;
  error.value = ''; busy.value = true;
  try { await props.request(`supplier-advances/${advance.id}`, { method: 'DELETE' }); await load(); }
  catch (exception) { error.value = exception.message; }
  finally { busy.value = false; }
}
async function removeEntry(item) {
  if (!confirm(label('Delete this TT entry?', 'এই টিটি এন্ট্রিটি মুছবেন?'))) return;
  error.value = ''; busy.value = true;
  try { await props.request(`supplier-tt-entries/${item.id}`, { method: 'DELETE' }); await load(); }
  catch (exception) { error.value = exception.message; }
  finally { busy.value = false; }
}
function entryTypeLabel(type) {
  return {
    opening_paid: label('Opening advance paid', 'প্রারম্ভিক পাঠানো অগ্রিম'),
    opening_received: label('Opening products received', 'প্রারম্ভিক প্রাপ্ত পণ্য'),
    incentive: label('Incentive', 'ইনসেনটিভ'),
    commission: label('Commission', 'কমিশন'),
    credit: label('Other credit', 'অন্যান্য প্রাপ্য'),
    received_adjustment: label('Other products received', 'অন্যান্য প্রাপ্ত পণ্য'),
  }[type] ?? type;
}
watch(() => props.branchId, load);
onMounted(load);
</script>

<template>
  <div class="page-heading">
    <div><span class="eyebrow">{{ label('STANDALONE TT REGISTER', 'স্বতন্ত্র টিটি হিসাব') }}</span>
      <h1>{{ label('Supplier Advances', 'সরবরাহকারী অগ্রিম') }}</h1>
      <p>{{ label('Record advance transfers and coded product installments separately from purchases and stock.', 'ক্রয় ও মজুদ থেকে আলাদাভাবে অগ্রিম টাকা এবং কোডসহ পণ্যের কিস্তি নথিভুক্ত করুন।') }}</p>
    </div>
    <a v-if="canViewReport" class="btn-secondary-app" href="/reports?report_view=1&report=supplier_tt" target="_blank" rel="noopener">{{ label('TT Report', 'টিটি প্রতিবেদন') }}</a>
  </div>
  <div v-if="error" class="alert-box error">{{ error }}</div>
  <div class="category-stats">
    <div class="stat-card"><div class="stat-top"><span>{{ label('Advances and credits', 'অগ্রিম ও প্রাপ্য') }}</span></div><strong>{{ money(totalSent) }}</strong></div>
    <div class="stat-card"><div class="stat-top"><span>{{ label('Products and other values received', 'প্রাপ্ত পণ্য ও অন্যান্য মূল্য') }}</span></div><strong>{{ money(totalReceived) }}</strong></div>
    <div class="stat-card"><div class="stat-top"><span>{{ label('Balance with companies', 'কোম্পানির কাছে অবশিষ্ট') }}</span></div><strong>{{ money(totalSent - totalReceived) }}</strong></div>
  </div>
  <div class="supplier-advance-forms">
    <form class="panel supplier-advance-form" @submit.prevent="saveAdvance">
      <h2>{{ label('Send an advance', 'অগ্রিম পাঠান') }}</h2>
      <p>{{ label('Record money sent or an opening balance, incentive, or credit for a company.', 'কোম্পানিকে পাঠানো টাকা, প্রারম্ভিক জের, ইনসেনটিভ বা প্রাপ্য লিখুন।') }}</p>
      <div class="form-grid">
        <label class="field"><span>{{ label('Record type', 'হিসাবের ধরন') }} *</span><select v-model="advanceType"><option value="advance">{{ label('Advance sent', 'পাঠানো অগ্রিম') }}</option><option value="opening_paid">{{ entryTypeLabel('opening_paid') }}</option><option value="incentive">{{ entryTypeLabel('incentive') }}</option><option value="commission">{{ entryTypeLabel('commission') }}</option><option value="credit">{{ entryTypeLabel('credit') }}</option></select></label>
        <div class="field"><span>{{ label('Company name', 'কোম্পানির নাম') }} *</span><SupplierCompanyPicker v-model="form.company_name" :companies="companies" :language="language" /></div>
        <label class="field"><span>{{ label('Amount', 'টাকার পরিমাণ') }} *</span><input v-model="form.amount" type="number" min="0.01" step="0.01" required></label>
        <label class="field"><span>{{ label('Date', 'তারিখ') }} *</span><input v-model="form.advance_date" type="date" required></label>
        <label v-if="advanceType === 'advance'" class="field"><span>{{ label('Payment method', 'পেমেন্ট পদ্ধতি') }} *</span><select v-model="form.payment_method" required><option value="cash">{{ label('Cash', 'নগদ') }}</option><option value="bank_transfer">{{ label('Bank transfer', 'ব্যাংক ট্রান্সফার') }}</option><option value="card">{{ label('Card', 'কার্ড') }}</option><option value="mobile_banking">{{ label('Mobile banking', 'মোবাইল ব্যাংকিং') }}</option></select></label>
        <label v-if="advanceType === 'advance'" class="field"><span>{{ label('TT / transfer reference', 'টিটি / ট্রান্সফার রেফারেন্স') }}</span><input v-model.trim="form.reference" maxlength="100"></label>
        <label v-else class="field"><span>{{ label('Particular', 'বিবরণ') }}</span><input v-model.trim="advanceParticular" maxlength="255" :placeholder="label('Optional description', 'ঐচ্ছিক বিবরণ')"></label>
        <label class="field"><span>{{ label('Notes', 'নোট') }}</span><input v-model.trim="form.notes" maxlength="2000"></label>
      </div>
      <button class="btn-primary-app" :disabled="busy || !form.company_name.trim()">{{ advanceType === 'advance' ? label('Save advance', 'অগ্রিম সংরক্ষণ করুন') : label('Save record', 'হিসাব সংরক্ষণ করুন') }}</button>
    </form>
    <form class="panel supplier-advance-form" @submit.prevent="saveDelivery">
      <h2>{{ label('Record products received', 'প্রাপ্ত পণ্য নথিভুক্ত করুন') }}</h2>
      <p>{{ label('Record products received or an opening or other received value. This does not change inventory.', 'প্রাপ্ত পণ্য, প্রারম্ভিক বা অন্যান্য প্রাপ্ত মূল্য লিখুন। এতে মজুদ পরিবর্তন হবে না।') }}</p>
      <div class="form-grid">
        <label class="field"><span>{{ label('Record type', 'হিসাবের ধরন') }} *</span><select v-model="receiptType"><option value="delivery">{{ label('Products received', 'পাওয়া পণ্য') }}</option><option value="opening_received">{{ entryTypeLabel('opening_received') }}</option><option value="received_adjustment">{{ entryTypeLabel('received_adjustment') }}</option></select></label>
        <div class="field"><span>{{ label('Company name', 'কোম্পানির নাম') }} *</span><SupplierCompanyPicker v-model="delivery.company_name" :companies="companies" :language="language" /></div>
        <label v-if="receiptType === 'delivery'" class="field"><span>{{ label('Installment code', 'কিস্তির কোড') }} *</span><input v-model.trim="delivery.installment_code" maxlength="100" required placeholder="LOT-01"></label>
        <label v-if="receiptType === 'delivery'" class="field"><span>{{ label('Product details', 'পণ্যের বিবরণ') }} *</span><input v-model.trim="delivery.product_details" maxlength="255" required></label>
        <label v-else class="field"><span>{{ label('Particular', 'বিবরণ') }}</span><input v-model.trim="receiptParticular" maxlength="255" :placeholder="label('Optional description', 'ঐচ্ছিক বিবরণ')"></label>
        <label class="field"><span>{{ receiptType === 'delivery' ? label('Delivery value', 'সরবরাহের মূল্য') : label('Amount', 'টাকার পরিমাণ') }} *</span><input v-model="delivery.amount" type="number" min="0.01" step="0.01" required></label>
        <label class="field"><span>{{ receiptType === 'delivery' ? label('Delivery date', 'সরবরাহের তারিখ') : label('Date', 'তারিখ') }} *</span><input v-model="delivery.delivery_date" type="date" required></label>
        <label class="field"><span>{{ label('Notes', 'নোট') }}</span><input v-model.trim="delivery.notes" maxlength="2000"></label>
      </div>
      <button class="btn-primary-app" :disabled="busy || !delivery.company_name.trim()">{{ receiptType === 'delivery' ? label('Save received products', 'প্রাপ্ত পণ্য সংরক্ষণ করুন') : label('Save record', 'হিসাব সংরক্ষণ করুন') }}</button>
    </form>
  </div>
  <section class="panel table-panel">
    <div class="table-toolbar"><div><h2>{{ label('Company accounts', 'কোম্পানির হিসাব') }}</h2><p>{{ label('Advances and coded product installments are grouped by company.', 'কোম্পানি অনুযায়ী অগ্রিম ও কোডসহ পণ্যের কিস্তি দেখানো হয়েছে।') }}</p></div></div>
    <div class="table-scroll"><table><thead><tr><th>{{ label('COMPANY', 'কোম্পানি') }}</th><th>{{ label('ADVANCES & CREDITS', 'অগ্রিম ও প্রাপ্য') }}</th><th>{{ label('RECEIVED VALUE', 'প্রাপ্ত মূল্য') }}</th><th>{{ label('BALANCE', 'অবশিষ্ট') }}</th><th>{{ label('TRANSACTIONS', 'লেনদেন') }}</th></tr></thead>
      <tbody><tr v-for="company in companyRows" :key="company.name"><td><strong>{{ company.name }}</strong></td><td>{{ money(company.amount) }}</td><td>{{ money(company.delivered) }}</td><td><strong>{{ money(company.amount - company.delivered) }}</strong></td><td><div v-for="advance in company.sent" :key="`a-${advance.id}`" class="advance-installment-row"><span>{{ date(advance.advance_date) }} · {{ advance.reference || `ADV-${advance.id}` }} · {{ money(advance.amount) }}</span><button type="button" class="btn-secondary-app small" :disabled="busy" @click="removeAdvance(advance)">{{ label('Delete', 'মুছুন') }}</button></div><div v-for="part in company.received" :key="`d-${part.id}`" class="advance-installment-row"><span>{{ part.installment_code }} · {{ part.product_details }} · {{ date(part.delivery_date) }} · {{ money(part.amount) }}</span><button type="button" class="btn-secondary-app small" :disabled="busy" @click="removeDelivery(part)">{{ label('Remove', 'মুছুন') }}</button></div><div v-for="item in company.adjustments" :key="`e-${item.id}`" class="advance-installment-row"><span>{{ date(item.entry_date) }} · {{ entryTypeLabel(item.entry_type) }}{{ item.particular ? ` · ${item.particular}` : '' }} · {{ money(item.amount) }}</span><button type="button" class="btn-secondary-app small" :disabled="busy" @click="removeEntry(item)">{{ label('Delete', 'মুছুন') }}</button></div></td></tr></tbody>
    </table></div>
    <div v-if="!advances.length && !entries.length" class="empty-state compact"><strong>{{ label('No TT records yet', 'এখনও টিটি হিসাব নথিভুক্ত হয়নি') }}</strong></div>
  </section>
</template>

<style scoped>
.supplier-advance-forms{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin:20px 0}.supplier-advance-form{padding:24px}.supplier-advance-form p{margin:4px 0 20px;color:#8b8e9f}.supplier-advance-form .btn-primary-app{margin-top:20px}.advance-installment-row{display:flex;align-items:center;gap:8px;margin:4px 0;min-width:340px}.advance-installment-row span{white-space:normal}@media(max-width:900px){.supplier-advance-forms{grid-template-columns:1fr}}
</style>
