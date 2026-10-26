<script setup>
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps({ request: { type: Function, required: true }, branchId: { type: [Number, String], required: true }, contacts: { type: Array, required: true }, documents: { type: Array, required: true }, money: { type: Function, required: true }, date: { type: Function, required: true }, language: { type: String, required: true } });
const emit = defineEmits(['refresh']);
const advances = ref([]);
const error = ref('');
const busy = ref(false);
const form = ref({ contact_id: '', amount: '', expected_installments: 3, advance_date: new Date().toLocaleDateString('en-CA'), payment_method: 'bank_transfer', reference: '', notes: '' });
const delivery = ref({ supplier_advance_id: '', document_id: '', installment_code: '', notes: '' });
const supplierOptions = computed(() => props.contacts.filter(contact => contact.active && ['supplier', 'both'].includes(contact.type)));
const selectedAdvance = computed(() => advances.value.find(item => item.id === Number(delivery.value.supplier_advance_id)));
const eligiblePurchases = computed(() => props.documents.filter(document => document.type === 'purchase' && document.contact_id === selectedAdvance.value?.contact_id && Number(document.amount_paid) === 0 && document.payment_type === 'due' && !advances.value.some(advance => advance.deliveries?.some(item => item.document_id === document.id))));
const totalSent = computed(() => advances.value.reduce((sum, item) => sum + Number(item.amount), 0));
const totalReceived = computed(() => advances.value.reduce((sum, item) => sum + (item.deliveries || []).reduce((amount, delivery) => amount + Number(delivery.amount), 0), 0));
function remaining(advance) { return Number(advance.amount) - (advance.deliveries || []).reduce((sum, item) => sum + Number(item.amount), 0); }
async function load() {
  if (!props.branchId) return;
  try { advances.value = await props.request(`supplier-advances?branch_id=${props.branchId}`); } catch (e) { error.value = e.message; }
}
async function saveAdvance() {
  error.value = ''; busy.value = true;
  try {
    await props.request('supplier-advances', { method: 'POST', body: { ...form.value, branch_id: props.branchId } });
    form.value.amount = ''; form.value.reference = ''; form.value.notes = '';
    await load();
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function saveDelivery() {
  error.value = ''; busy.value = true;
  try {
    await props.request(`supplier-advances/${delivery.value.supplier_advance_id}/deliveries`, { method: 'POST', body: { branch_id: props.branchId, document_id: delivery.value.document_id, installment_code: delivery.value.installment_code.trim(), notes: delivery.value.notes } });
    delivery.value.document_id = ''; delivery.value.installment_code = ''; delivery.value.notes = '';
    await load();
    emit('refresh');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function reverseDelivery(advance, item) {
  if (!confirm(`Reverse installment ${item.installment_code}? The purchase will become unpaid again.`)) return;
  error.value = ''; busy.value = true;
  try { await props.request(`supplier-advances/${advance.id}/deliveries/${item.id}`, { method: 'DELETE' }); await load(); emit('refresh'); }
  catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function deleteAdvance(advance) {
  if (!confirm(`Delete advance ${advance.reference || `ADV-${advance.id}`}?`)) return;
  error.value = ''; busy.value = true;
  try { await props.request(`supplier-advances/${advance.id}`, { method: 'DELETE' }); await load(); emit('refresh'); }
  catch (e) { error.value = e.message; } finally { busy.value = false; }
}
watch(() => props.branchId, load);
onMounted(load);
</script>

<template>
  <div class="page-heading"><div><span class="eyebrow">{{ language === 'bn' ? 'সরবরাহকারী হিসাব' : 'SUPPLIER ACCOUNT' }}</span><h1>{{ language === 'bn' ? 'সরবরাহকারী অগ্রিম' : 'Supplier Advances' }}</h1><p>{{ language === 'bn' ? 'অগ্রিম টাকা এবং কিস্তিতে পাওয়া পণ্য একই হিসাবে দেখুন।' : 'Track money sent in advance and products received in coded installments.' }}</p></div><a class="btn-secondary-app" href="/reports?report_view=1&report=supplier_tt" target="_blank">TT Report</a></div>
  <div v-if="error" class="alert-box error">{{ error }}</div>
  <div class="category-stats"><div class="stat-card"><div class="stat-top"><span>Advance sent</span></div><strong>{{ money(totalSent) }}</strong></div><div class="stat-card"><div class="stat-top"><span>Products received</span></div><strong>{{ money(totalReceived) }}</strong></div><div class="stat-card"><div class="stat-top"><span>Balance with suppliers</span></div><strong>{{ money(totalSent - totalReceived) }}</strong></div></div>
  <div class="supplier-advance-forms">
    <form class="panel supplier-advance-form" @submit.prevent="saveAdvance"><h2>Send an advance</h2><p>Record money actually sent to a supplier.</p><div class="form-grid"><label class="field"><span>Supplier *</span><select v-model="form.contact_id" required><option value="">Select supplier</option><option v-for="supplier in supplierOptions" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option></select></label><label class="field"><span>Amount *</span><input v-model="form.amount" type="number" min="0.01" step="0.01" required></label><label class="field"><span>Planned installments *</span><select v-model.number="form.expected_installments" required><option v-for="count in [3,4,5,6]" :key="count" :value="count">{{ count }}</option></select></label><label class="field"><span>Date *</span><input v-model="form.advance_date" type="date" required></label><label class="field"><span>Payment method *</span><select v-model="form.payment_method"><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option></select></label><label class="field"><span>TT / transfer reference</span><input v-model.trim="form.reference" maxlength="100"></label><label class="field"><span>Notes</span><input v-model.trim="form.notes" maxlength="2000"></label></div><button class="btn-primary-app" :disabled="busy">Save advance</button></form>
    <form class="panel supplier-advance-form" @submit.prevent="saveDelivery"><h2>Record a product installment</h2><p>First create an unpaid purchase invoice. Then link it here; its stock is already recorded by that purchase.</p><div class="form-grid"><label class="field"><span>Advance *</span><select v-model="delivery.supplier_advance_id" required @change="delivery.document_id = ''"><option value="">Select advance</option><option v-for="advance in advances.filter(item => remaining(item) > 0 && item.deliveries.length < item.expected_installments)" :key="advance.id" :value="advance.id">{{ advance.supplier?.name }} · {{ advance.reference || `ADV-${advance.id}` }} · {{ money(remaining(advance)) }} left ({{ advance.deliveries.length }}/{{ advance.expected_installments }})</option></select></label><label class="field"><span>Unpaid purchase *</span><select v-model="delivery.document_id" required><option value="">Select purchase</option><option v-for="purchase in eligiblePurchases" :key="purchase.id" :value="purchase.id">{{ purchase.number }} · {{ money(purchase.total) }} · {{ date(purchase.document_date) }}</option></select></label><label class="field"><span>Installment code *</span><input v-model.trim="delivery.installment_code" maxlength="100" required placeholder="e.g. LOT-01"></label><label class="field"><span>Notes</span><input v-model.trim="delivery.notes" maxlength="2000"></label></div><button class="btn-primary-app" :disabled="busy || !delivery.supplier_advance_id">Link delivery</button></form>
  </div>
  <section class="panel table-panel"><div class="table-toolbar"><div><h2>Advance accounts</h2><p>Each purchase is linked once and settled from its supplier advance.</p></div></div><div class="table-scroll"><table><thead><tr><th>DATE</th><th>SUPPLIER</th><th>REFERENCE</th><th>ADVANCE</th><th>RECEIVED</th><th>BALANCE</th><th>INSTALLMENTS</th><th></th></tr></thead><tbody><tr v-for="advance in advances" :key="advance.id"><td>{{ date(advance.advance_date) }}</td><td>{{ advance.supplier?.name }}</td><td>{{ advance.reference || `ADV-${advance.id}` }}</td><td>{{ money(advance.amount) }}</td><td>{{ money(Number(advance.amount) - remaining(advance)) }}</td><td><strong>{{ money(remaining(advance)) }}</strong></td><td><div v-for="item in advance.deliveries" :key="item.id" class="advance-installment-row">{{ item.installment_code }} · {{ item.purchase?.number }} · {{ money(item.amount) }} <button class="btn-secondary-app small" type="button" :disabled="busy" @click="reverseDelivery(advance, item)">Reverse</button></div><span v-if="!advance.deliveries?.length">—</span></td><td><button v-if="!advance.deliveries?.length" class="btn-secondary-app small" type="button" :disabled="busy" @click="deleteAdvance(advance)">Delete</button></td></tr></tbody></table></div><div v-if="!advances.length" class="empty-state compact"><strong>No advances recorded yet</strong></div></section>
</template>

<style scoped>
.supplier-advance-forms{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin:20px 0}.supplier-advance-form{padding:24px}.supplier-advance-form p{margin:4px 0 20px;color:#8b8e9f}.supplier-advance-form .btn-primary-app{margin-top:20px}@media(max-width:900px){.supplier-advance-forms{grid-template-columns:1fr}}
.advance-installment-row{display:flex;align-items:center;gap:8px;white-space:nowrap;margin:4px 0}
</style>
