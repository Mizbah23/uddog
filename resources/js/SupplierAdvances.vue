<script setup>
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps({
  request: { type: Function, required: true },
  branchId: { type: [Number, String], required: true },
  money: { type: Function, required: true },
  date: { type: Function, required: true },
  language: { type: String, required: true },
});
const advances = ref([]);
const error = ref('');
const busy = ref(false);
const currentDate = () => new Date().toLocaleDateString('en-CA');
const form = ref({ company_name: '', amount: '', expected_installments: 3, advance_date: currentDate(), payment_method: 'bank_transfer', reference: '', notes: '' });
const delivery = ref({ supplier_advance_id: '', installment_code: '', product_details: '', amount: '', delivery_date: currentDate(), notes: '' });
const selectedAdvance = computed(() => advances.value.find(item => item.id === Number(delivery.value.supplier_advance_id)));
const totalSent = computed(() => advances.value.reduce((sum, item) => sum + Number(item.amount), 0));
const totalReceived = computed(() => advances.value.reduce((sum, item) => sum + (item.deliveries || []).reduce((value, part) => value + Number(part.amount), 0), 0));
function remaining(advance) { return Number(advance.amount) - (advance.deliveries || []).reduce((sum, part) => sum + Number(part.amount), 0); }
function label(en, bn) { return props.language === 'bn' ? bn : en; }

async function load() {
  if (!props.branchId) return;
  try { advances.value = await props.request(`supplier-advances?branch_id=${props.branchId}`); }
  catch (exception) { error.value = exception.message; }
}
async function saveAdvance() {
  error.value = ''; busy.value = true;
  try {
    await props.request('supplier-advances', { method: 'POST', body: { ...form.value, branch_id: props.branchId } });
    form.value = { ...form.value, amount: '', reference: '', notes: '' };
    await load();
  } catch (exception) { error.value = exception.message; }
  finally { busy.value = false; }
}
async function saveDelivery() {
  error.value = ''; busy.value = true;
  try {
    await props.request(`supplier-advances/${delivery.value.supplier_advance_id}/deliveries`, {
      method: 'POST', body: { ...delivery.value, branch_id: props.branchId },
    });
    delivery.value = { ...delivery.value, installment_code: '', product_details: '', amount: '', notes: '' };
    await load();
  } catch (exception) { error.value = exception.message; }
  finally { busy.value = false; }
}
async function removeDelivery(advance, part) {
  if (!confirm(label(`Remove installment ${part.installment_code}?`, `${part.installment_code} কিস্তিটি মুছবেন?`))) return;
  error.value = ''; busy.value = true;
  try { await props.request(`supplier-advances/${advance.id}/deliveries/${part.id}`, { method: 'DELETE' }); await load(); }
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
watch(() => props.branchId, load);
onMounted(load);
</script>

<template>
  <div class="page-heading">
    <div><span class="eyebrow">{{ label('STANDALONE TT REGISTER', 'স্বতন্ত্র টিটি হিসাব') }}</span>
      <h1>{{ label('Supplier Advances', 'সরবরাহকারী অগ্রিম') }}</h1>
      <p>{{ label('Record advance transfers and coded product installments separately from purchases and stock.', 'ক্রয় ও মজুদ থেকে আলাদাভাবে অগ্রিম টাকা এবং কোডসহ পণ্যের কিস্তি নথিভুক্ত করুন।') }}</p>
    </div>
    <a class="btn-secondary-app" href="/reports?report_view=1&report=supplier_tt" target="_blank" rel="noopener">{{ label('TT Report', 'টিটি প্রতিবেদন') }}</a>
  </div>
  <div v-if="error" class="alert-box error">{{ error }}</div>
  <div class="category-stats">
    <div class="stat-card"><div class="stat-top"><span>{{ label('Advance sent', 'পাঠানো অগ্রিম') }}</span></div><strong>{{ money(totalSent) }}</strong></div>
    <div class="stat-card"><div class="stat-top"><span>{{ label('Products received', 'পাওয়া পণ্য') }}</span></div><strong>{{ money(totalReceived) }}</strong></div>
    <div class="stat-card"><div class="stat-top"><span>{{ label('Balance with companies', 'কোম্পানির কাছে অবশিষ্ট') }}</span></div><strong>{{ money(totalSent - totalReceived) }}</strong></div>
  </div>
  <div class="supplier-advance-forms">
    <form class="panel supplier-advance-form" @submit.prevent="saveAdvance">
      <h2>{{ label('Send an advance', 'অগ্রিম পাঠান') }}</h2>
      <p>{{ label('Enter the company name here; this record is not linked to the supplier directory.', 'এখানে কোম্পানির নাম লিখুন; এই হিসাব সরবরাহকারী তালিকার সঙ্গে যুক্ত নয়।') }}</p>
      <div class="form-grid">
        <label class="field"><span>{{ label('Company name', 'কোম্পানির নাম') }} *</span><input v-model.trim="form.company_name" maxlength="255" required></label>
        <label class="field"><span>{{ label('Amount', 'টাকার পরিমাণ') }} *</span><input v-model="form.amount" type="number" min="0.01" step="0.01" required></label>
        <label class="field"><span>{{ label('Planned installments', 'নির্ধারিত কিস্তি') }} *</span><select v-model.number="form.expected_installments" required><option v-for="count in [3, 4, 5, 6]" :key="count" :value="count">{{ count }}</option></select></label>
        <label class="field"><span>{{ label('Date', 'তারিখ') }} *</span><input v-model="form.advance_date" type="date" required></label>
        <label class="field"><span>{{ label('Payment method', 'পেমেন্ট পদ্ধতি') }} *</span><select v-model="form.payment_method" required><option value="cash">{{ label('Cash', 'নগদ') }}</option><option value="bank_transfer">{{ label('Bank transfer', 'ব্যাংক ট্রান্সফার') }}</option><option value="card">{{ label('Card', 'কার্ড') }}</option><option value="mobile_banking">{{ label('Mobile banking', 'মোবাইল ব্যাংকিং') }}</option></select></label>
        <label class="field"><span>{{ label('TT / transfer reference', 'টিটি / ট্রান্সফার রেফারেন্স') }}</span><input v-model.trim="form.reference" maxlength="100"></label>
        <label class="field"><span>{{ label('Notes', 'নোট') }}</span><input v-model.trim="form.notes" maxlength="2000"></label>
      </div>
      <button class="btn-primary-app" :disabled="busy">{{ label('Save advance', 'অগ্রিম সংরক্ষণ করুন') }}</button>
    </form>
    <form class="panel supplier-advance-form" @submit.prevent="saveDelivery">
      <h2>{{ label('Record a product installment', 'পণ্যের কিস্তি নথিভুক্ত করুন') }}</h2>
      <p>{{ label('Enter the delivery value and product details manually; this does not change inventory.', 'পণ্যের বিবরণ ও মূল্য নিজে লিখুন; এতে মজুদ পরিবর্তন হবে না।') }}</p>
      <div class="form-grid">
        <label class="field"><span>{{ label('Advance', 'অগ্রিম') }} *</span><select v-model="delivery.supplier_advance_id" required><option value="">{{ label('Select advance', 'অগ্রিম নির্বাচন করুন') }}</option><option v-for="advance in advances.filter(item => remaining(item) > 0 && item.deliveries.length < item.expected_installments)" :key="advance.id" :value="advance.id">{{ advance.company_name }} · {{ advance.reference || `ADV-${advance.id}` }} · {{ money(remaining(advance)) }} ({{ advance.deliveries.length }}/{{ advance.expected_installments }})</option></select></label>
        <label class="field"><span>{{ label('Installment code', 'কিস্তির কোড') }} *</span><input v-model.trim="delivery.installment_code" maxlength="100" required placeholder="LOT-01"></label>
        <label class="field"><span>{{ label('Product details', 'পণ্যের বিবরণ') }} *</span><input v-model.trim="delivery.product_details" maxlength="255" required></label>
        <label class="field"><span>{{ label('Delivery value', 'সরবরাহের মূল্য') }} *</span><input v-model="delivery.amount" type="number" min="0.01" :max="selectedAdvance ? remaining(selectedAdvance) : undefined" step="0.01" required></label>
        <label class="field"><span>{{ label('Delivery date', 'সরবরাহের তারিখ') }} *</span><input v-model="delivery.delivery_date" type="date" :min="selectedAdvance?.advance_date?.slice(0, 10)" required></label>
        <label class="field"><span>{{ label('Notes', 'নোট') }}</span><input v-model.trim="delivery.notes" maxlength="2000"></label>
      </div>
      <button class="btn-primary-app" :disabled="busy || !selectedAdvance">{{ label('Save installment', 'কিস্তি সংরক্ষণ করুন') }}</button>
    </form>
  </div>
  <section class="panel table-panel">
    <div class="table-toolbar"><div><h2>{{ label('Advance accounts', 'অগ্রিমের হিসাব') }}</h2><p>{{ label('Each installment code is unique within an advance.', 'প্রতিটি অগ্রিমের কিস্তির কোড আলাদা হতে হবে।') }}</p></div></div>
    <div class="table-scroll"><table><thead><tr><th>{{ label('DATE', 'তারিখ') }}</th><th>{{ label('COMPANY', 'কোম্পানি') }}</th><th>{{ label('REFERENCE', 'রেফারেন্স') }}</th><th>{{ label('ADVANCE', 'অগ্রিম') }}</th><th>{{ label('RECEIVED', 'গৃহীত') }}</th><th>{{ label('BALANCE', 'অবশিষ্ট') }}</th><th>{{ label('INSTALLMENTS', 'কিস্তি') }}</th><th></th></tr></thead>
      <tbody><tr v-for="advance in advances" :key="advance.id"><td>{{ date(advance.advance_date) }}</td><td>{{ advance.company_name }}</td><td>{{ advance.reference || `ADV-${advance.id}` }}</td><td>{{ money(advance.amount) }}</td><td>{{ money(Number(advance.amount) - remaining(advance)) }}</td><td><strong>{{ money(remaining(advance)) }}</strong></td><td><div v-for="part in advance.deliveries" :key="part.id" class="advance-installment-row"><span>{{ part.installment_code }} · {{ part.product_details }} · {{ date(part.delivery_date) }} · {{ money(part.amount) }}</span><button type="button" class="btn-secondary-app small" :disabled="busy" @click="removeDelivery(advance, part)">{{ label('Remove', 'মুছুন') }}</button></div><span v-if="!advance.deliveries?.length">—</span></td><td><button v-if="!advance.deliveries?.length" type="button" class="btn-secondary-app small" :disabled="busy" @click="removeAdvance(advance)">{{ label('Delete', 'মুছুন') }}</button></td></tr></tbody>
    </table></div>
    <div v-if="!advances.length" class="empty-state compact"><strong>{{ label('No advances recorded yet', 'এখনও অগ্রিম নথিভুক্ত হয়নি') }}</strong></div>
  </section>
</template>

<style scoped>
.supplier-advance-forms{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin:20px 0}.supplier-advance-form{padding:24px}.supplier-advance-form p{margin:4px 0 20px;color:#8b8e9f}.supplier-advance-form .btn-primary-app{margin-top:20px}.advance-installment-row{display:flex;align-items:center;gap:8px;margin:4px 0;min-width:340px}.advance-installment-row span{white-space:normal}@media(max-width:900px){.supplier-advance-forms{grid-template-columns:1fr}}
</style>
