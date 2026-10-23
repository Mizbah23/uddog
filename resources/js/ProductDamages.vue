<script setup>
import { computed, ref, watch } from 'vue';
import { AlertTriangle, ArrowDownRight, ArrowUpRight, Search, Package, Clock3 } from '@lucide/vue';

const props = defineProps({
  branchId: { type: [Number, String], default: '' },
  products: { type: Array, default: () => [] },
  api: { type: Function, required: true },
  language: { type: String, default: 'en' },
});
const emit = defineEmits(['saved']);
const purchases = ref([]);
const damages = ref([]);
const damagePage = ref(1);
const damageLastPage = ref(1);
const timeline = ref([]);
const timelinePage = ref(1);
const timelineLastPage = ref(1);
const selectedProductId = ref('');
const purchaseSearch = ref('');
const error = ref('');
const notice = ref('');
const loading = ref(false);
const saving = ref(false);
const form = ref({ purchase_id: '', product_id: '', quantity: '', damaged_on: new Date().toLocaleDateString('en-CA'), reason: '', notes: '' });

const selectedPurchase = computed(() => purchases.value.find(purchase => purchase.id === Number(form.value.purchase_id)));
const purchaseItems = computed(() => selectedPurchase.value?.items.filter(item => Number(item.remaining_quantity) > 0) || []);
const selectedItem = computed(() => purchaseItems.value.find(item => item.product?.id === Number(form.value.product_id)));
const selectedProduct = computed(() => props.products.find(product => product.id === Number(selectedProductId.value)));
const maxQuantity = computed(() => selectedItem.value ? Math.min(Number(selectedItem.value.remaining_quantity), Number(props.products.find(product => product.id === selectedItem.value.product?.id)?.quantity_on_hand || 0)) : 0);
const locale = computed(() => props.language === 'bn' ? 'bn-BD' : 'en-BD');

function qty(value) { return Number(value || 0).toLocaleString(locale.value, { maximumFractionDigits: 3 }); }
function date(value) { return value ? new Date(value.includes('T') ? value : `${value}T00:00:00`).toLocaleDateString(locale.value, { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
function time(value) { return value ? new Date(value).toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' }) : '—'; }
function movementLabel(type) { return ({ purchase: 'Purchase received', purchase_return: 'Returned to supplier', sale: 'Sale', resale: 'Resale', sale_return: 'Customer return', damage: 'Damaged stock', adjustment: 'Stock adjustment', stock_check: 'Stock check', transfer_in: 'Transfer in', transfer_out: 'Transfer out' })[type] || type; }
function movementSource(movement) { return movement.product_damage?.purchase?.number || movement.document?.number || movement.stock_check?.number || movement.stock_transfer?.number || 'Manual entry'; }
function changePurchase() { form.value.product_id = ''; form.value.quantity = ''; }

async function loadPurchases() {
  const branchId = props.branchId;
  const query = new URLSearchParams({ branch_id: branchId, search: purchaseSearch.value.trim() });
  const result = await props.api(`product-damages/purchases?${query}`);
  if (branchId !== props.branchId) return;
  purchases.value = result;
  if (!result.some(purchase => purchase.id === Number(form.value.purchase_id))) {
    form.value.purchase_id = '';
    changePurchase();
  }
}

async function loadDamages(page = 1) {
  const branchId = props.branchId;
  const result = await props.api(`product-damages?branch_id=${branchId}&page=${page}`);
  if (branchId !== props.branchId) return;
  damages.value = page === 1 ? result.data : [...damages.value, ...result.data];
  damagePage.value = result.current_page;
  damageLastPage.value = result.last_page;
}

async function loadTimeline(page = 1) {
  if (!selectedProductId.value) { timeline.value = []; timelinePage.value = 1; timelineLastPage.value = 1; return; }
  const productId = selectedProductId.value;
  const branchId = props.branchId;
  const result = await props.api(`product-damages/timeline/${productId}?branch_id=${branchId}&page=${page}`);
  if (productId !== selectedProductId.value || branchId !== props.branchId) return;
  timeline.value = page === 1 ? result.data : [...timeline.value, ...result.data];
  timelinePage.value = result.current_page;
  timelineLastPage.value = result.last_page;
}

async function load() {
  if (!props.branchId) return;
  error.value = '';
  notice.value = '';
  loading.value = true;
  selectedProductId.value = '';
  form.value.purchase_id = '';
  changePurchase();
  try { await Promise.all([loadPurchases(), loadDamages()]); }
  catch (exception) { error.value = exception.message; }
  finally { loading.value = false; }
}

async function searchPurchases() {
  error.value = '';
  try { await loadPurchases(); }
  catch (exception) { error.value = exception.message; }
}

async function chooseProduct(productId) {
  selectedProductId.value = productId;
  error.value = '';
  try { await loadTimeline(); }
  catch (exception) { error.value = exception.message; }
}

async function save() {
  error.value = '';
  notice.value = '';
  saving.value = true;
  try {
    const result = await props.api('product-damages', { method: 'POST', body: { ...form.value, branch_id: props.branchId } });
    selectedProductId.value = result.product_id;
    form.value.quantity = '';
    form.value.reason = '';
    form.value.notes = '';
    notice.value = `${qty(result.quantity)} ${result.product?.unit || ''} of ${result.product?.name || 'product'} recorded as damaged.`;
    emit('saved');
    await Promise.all([loadPurchases(), loadDamages(), loadTimeline()]);
  } catch (exception) { error.value = exception.message; }
  finally { saving.value = false; }
}

watch(() => props.branchId, load, { immediate: true });
</script>

<template>
  <div class="page-heading"><div><span class="eyebrow">STOCK CONTROL</span><h1>Damaged products</h1><p>Record damaged stock against its purchase and follow each product's full movement history.</p></div></div>
  <div v-if="error" class="alert-box error" role="alert">{{ error }}</div>
  <div v-if="notice" class="damage-notice" role="status">{{ notice }}</div>
  <div class="damage-layout">
    <section class="panel damage-entry">
      <div class="panel-heading"><div><h2>Record damage</h2><p>Stock is deducted from the active branch when you save.</p></div><AlertTriangle :size="20" /></div>
      <form @submit.prevent="save">
        <label class="field"><span>Find purchase</span><div class="damage-search"><input v-model="purchaseSearch" placeholder="Invoice number or supplier" @keydown.enter.prevent="searchPurchases"><button type="button" class="btn-secondary-app small" @click="searchPurchases"><Search :size="16" /> Search</button></div></label>
        <label class="field"><span>Purchase *</span><select v-model="form.purchase_id" required @change="changePurchase"><option value="" disabled>Select a purchase</option><option v-for="purchase in purchases" :key="purchase.id" :value="purchase.id">{{ purchase.number }} · {{ date(purchase.document_date) }} · {{ purchase.supplier || 'Supplier' }}</option></select><small>Search results show up to 100 purchases from this branch.</small></label>
        <label class="field"><span>Product from purchase *</span><select v-model="form.product_id" required :disabled="!form.purchase_id"><option value="" disabled>Select a product</option><option v-for="item in purchaseItems" :key="item.product.id" :value="item.product.id">{{ item.product.name }}{{ item.product.sku ? ` · ${item.product.sku}` : '' }} · {{ qty(item.remaining_quantity) }} {{ item.product.unit }} remaining</option></select></label>
        <div v-if="selectedItem" class="damage-availability"><span>Purchase remaining <strong>{{ qty(selectedItem.remaining_quantity) }} {{ selectedItem.product.unit }}</strong></span><span>Branch stock <strong>{{ qty(products.find(product => product.id === selectedItem.product.id)?.quantity_on_hand) }} {{ selectedItem.product.unit }}</strong></span></div>
        <div class="damage-form-grid"><label class="field"><span>Damaged quantity *</span><input v-model="form.quantity" type="number" :min="['pc', 'pcs'].includes(selectedItem?.product?.unit) ? 1 : 0.001" :max="maxQuantity" :step="['pc', 'pcs'].includes(selectedItem?.product?.unit) ? 1 : 0.001" required :disabled="!selectedItem"><small v-if="selectedItem">Maximum {{ qty(maxQuantity) }} {{ selectedItem.product.unit }}</small></label><label class="field"><span>Damage date *</span><input v-model="form.damaged_on" type="date" :min="selectedPurchase?.document_date" :max="new Date().toLocaleDateString('en-CA')" required></label></div>
        <label class="field"><span>Reason *</span><input v-model.trim="form.reason" list="damage-reasons" maxlength="150" required placeholder="e.g. Broken during handling"><datalist id="damage-reasons"><option value="Broken during handling"></option><option value="Expired"></option><option value="Spoiled"></option><option value="Water or fire damage"></option><option value="Defective on arrival"></option></datalist></label>
        <label class="field"><span>Notes</span><textarea v-model.trim="form.notes" rows="3" maxlength="2000" placeholder="Condition, location, or other details"></textarea></label>
        <button class="btn-primary-app damage-submit" :disabled="saving || loading || !selectedItem || maxQuantity <= 0"><AlertTriangle :size="17" /> {{ saving ? 'Recording...' : 'Record damaged stock' }}</button>
      </form>
    </section>

    <section class="panel damage-timeline">
      <div class="panel-heading"><div><h2>Product timeline</h2><p>Every stock movement for the chosen product in this branch, newest first.</p></div><Clock3 :size="20" /></div>
      <label class="field"><span>Product</span><select :value="selectedProductId" @change="chooseProduct($event.target.value)"><option value="">Choose a product</option><option v-for="product in products" :key="product.id" :value="product.id">{{ product.name }}{{ product.sku ? ` · ${product.sku}` : '' }}</option></select></label>
      <div v-if="selectedProduct" class="damage-product-heading"><span class="product-avatar">{{ selectedProduct.name?.charAt(0) }}</span><div><strong>{{ selectedProduct.name }}</strong><small>Current branch stock {{ qty(selectedProduct.quantity_on_hand) }} {{ selectedProduct.unit }}</small></div></div>
      <div v-if="timeline.length" class="damage-events"><div v-for="movement in timeline" :key="movement.id" class="damage-event"><span class="damage-event-icon" :class="Number(movement.quantity_change) < 0 ? 'out' : 'in'"><ArrowUpRight v-if="Number(movement.quantity_change) < 0" :size="16" /><ArrowDownRight v-else :size="16" /></span><div class="damage-event-body"><strong>{{ movementLabel(movement.type) }}</strong><small>{{ date(movement.created_at) }} · {{ time(movement.created_at) }} · {{ movementSource(movement) }}</small><span v-if="movement.product_damage">{{ movement.product_damage.reason }} · Purchase {{ movement.product_damage.purchase?.number }} · Damaged {{ date(movement.product_damage.damaged_on) }}</span><span v-if="movement.product_damage?.notes">{{ movement.product_damage.notes }}</span><span v-else-if="movement.notes && movement.type !== 'damage'">{{ movement.notes }}</span></div><div class="damage-event-quantity" :class="Number(movement.quantity_change) < 0 ? 'negative' : 'positive'"><strong>{{ Number(movement.quantity_change) > 0 ? '+' : '' }}{{ qty(movement.quantity_change) }}</strong><small>Balance {{ qty(movement.balance_after) }}</small></div></div></div>
      <div v-else class="empty-state compact"><Package :size="30" /><strong>{{ selectedProduct ? 'No movements yet' : 'Choose a product' }}</strong><span>{{ selectedProduct ? 'Its stock events will appear here.' : 'See purchases, damage, sales, returns and adjustments in order.' }}</span></div>
      <button v-if="timelinePage < timelineLastPage" type="button" class="btn-secondary-app damage-more" @click="loadTimeline(timelinePage + 1)">Load older movements</button>
    </section>
  </div>

  <section class="panel table-panel damage-history"><div class="table-toolbar"><div><h2>Damage log</h2><p>Purchase-linked records for the active branch</p></div></div><div class="table-scroll"><table><thead><tr><th>DATE</th><th>PRODUCT</th><th>PURCHASE</th><th>DAMAGED</th><th>REASON / NOTES</th><th>RECORDED BY</th></tr></thead><tbody><tr v-for="damage in damages" :key="damage.id"><td><strong>{{ date(damage.damaged_on) }}</strong><small class="damage-muted">Logged {{ date(damage.created_at) }} · {{ time(damage.created_at) }}</small></td><td><button type="button" class="damage-product-link" @click="chooseProduct(damage.product_id)">{{ damage.product?.name }}</button><small class="damage-muted">{{ damage.product?.sku || 'No SKU' }}</small></td><td><strong>{{ damage.purchase?.number }}</strong><small class="damage-muted">{{ date(damage.purchase?.document_date) }}</small></td><td><strong class="damage-loss">−{{ qty(damage.quantity) }} {{ damage.product?.unit }}</strong></td><td><strong>{{ damage.reason }}</strong><small v-if="damage.notes" class="damage-muted">{{ damage.notes }}</small></td><td>{{ damage.creator?.name || '—' }}</td></tr></tbody></table></div><div v-if="!damages.length" class="empty-state compact"><AlertTriangle :size="30" /><strong>No damage recorded</strong><span>New damage records will appear here with their purchase references.</span></div><button v-if="damagePage < damageLastPage" type="button" class="btn-secondary-app damage-more" @click="loadDamages(damagePage + 1)">Load older records</button></section>
</template>

<style scoped>
.damage-layout{display:grid;grid-template-columns:minmax(340px,.86fr) minmax(390px,1.14fr);gap:18px;align-items:start;margin-bottom:18px}.damage-entry,.damage-timeline{padding:20px}.damage-entry .panel-heading,.damage-timeline .panel-heading{margin-bottom:18px}.damage-entry form{display:flex;flex-direction:column;gap:14px}.damage-entry .field,.damage-timeline .field{margin:0}.damage-search{display:flex;gap:8px}.damage-search input{flex:1;min-width:0}.damage-search button{flex:none}.damage-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.damage-availability{display:flex;gap:14px;flex-wrap:wrap;padding:11px 13px;background:#f7f6ff;border:1px solid #e9e5ff;border-radius:8px;font-size:11px;color:#7a7592}.damage-availability strong{display:block;color:#3d3758;margin-top:3px;font-size:13px}.damage-submit{justify-content:center;width:100%;margin-top:4px}.damage-notice{background:#e8f8f0;border:1px solid #bce9d1;color:#237b55;border-radius:7px;padding:11px 13px;margin-bottom:16px;font-size:12px;font-weight:650}.damage-product-heading{display:flex;align-items:center;gap:10px;margin:17px 0 4px;padding:12px;background:#f8f7fc;border-radius:8px}.damage-product-heading strong,.damage-product-heading small{display:block}.damage-product-heading strong{font-size:13px}.damage-product-heading small{font-size:11px;color:#85899a;margin-top:3px}.damage-events{margin-top:15px}.damage-event{display:grid;grid-template-columns:30px minmax(0,1fr) auto;gap:10px;padding:13px 0;border-top:1px solid #eeeef4}.damage-event-icon{display:grid;place-items:center;width:28px;height:28px;border-radius:50%}.damage-event-icon.out{background:#fff1ec;color:#ce6855}.damage-event-icon.in{background:#e9f8f0;color:#268b67}.damage-event-body{min-width:0}.damage-event-body strong,.damage-event-body small,.damage-event-body span{display:block}.damage-event-body strong{font-size:12px;color:#303446}.damage-event-body small,.damage-event-body span{font-size:10.5px;line-height:1.5;color:#85899a;margin-top:3px;overflow-wrap:anywhere}.damage-event-quantity{text-align:right;white-space:nowrap}.damage-event-quantity strong,.damage-event-quantity small{display:block}.damage-event-quantity strong{font-size:13px}.damage-event-quantity small{font-size:10px;color:#8a8d9c;margin-top:3px}.damage-event-quantity.negative,.damage-loss{color:#d16053}.damage-event-quantity.positive{color:#258b65}.damage-history td{vertical-align:top}.damage-muted{display:block;margin-top:4px;color:#9498a6;font-size:10.5px}.damage-product-link{border:0;background:none;color:#5e50cb;font:inherit;font-weight:700;padding:0;cursor:pointer;text-align:left}.damage-product-link:hover{text-decoration:underline}.damage-more{display:flex;justify-content:center;margin:15px auto 2px}@media(max-width:1100px){.damage-layout{grid-template-columns:1fr}}@media(max-width:560px){.damage-entry,.damage-timeline{padding:15px}.damage-form-grid{grid-template-columns:1fr}.damage-search{flex-direction:column}.damage-event{grid-template-columns:28px minmax(0,1fr)}.damage-event-quantity{grid-column:2;text-align:left}}
</style>
