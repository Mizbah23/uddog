<script setup>
import { computed, getCurrentInstance, nextTick, onUnmounted, ref, watch } from 'vue';
import { ChevronDown, Search } from '@lucide/vue';

const props = defineProps({
  modelValue: { type: [Number, String], default: '' },
  products: { type: Array, required: true },
  disabled: { type: Boolean, default: false },
  stockMode: { type: String, default: 'available' },
  language: { type: String, default: 'en' },
});
const emit = defineEmits(['update:modelValue', 'select']);
const listboxId = `product-picker-${getCurrentInstance().uid}`;
const searchText = ref('');
const open = ref(false);
const activeIndex = ref(0);
let blurTimer;
const selectedProduct = computed(() => props.products.find(product => product.id === Number(props.modelValue)));
const stockLabel = computed(() => props.language === 'bn'
  ? (props.stockMode === 'on_hand' ? 'স্টকে আছে' : 'বিক্রয়ের জন্য আছে')
  : (props.stockMode === 'on_hand' ? 'On hand' : 'Available'));
function stockText(product) {
  const value = props.stockMode === 'on_hand' ? product.quantity_on_hand : product.available_quantity;
  if (value === undefined || value === null) return null;
  const number = Number(value);
  if (!Number.isFinite(number)) return null;
  return `${number.toLocaleString(props.language === 'bn' ? 'bn-BD' : 'en-US', { maximumFractionDigits: 3 })} ${product.unit || ''}`.trim();
}
const matches = computed(() => {
  const query = searchText.value.trim().toLocaleLowerCase();
  return props.products.filter(product => !query || [product.name, product.sku, product.barcode]
    .some(value => String(value || '').toLocaleLowerCase().includes(query))).slice(0, 80);
});

watch(selectedProduct, product => {
  if (!open.value) searchText.value = product?.name || '';
}, { immediate: true });
watch(matches, () => { activeIndex.value = 0; });
watch(activeIndex, async () => {
  await nextTick();
  document.getElementById(`${listboxId}-option-${activeIndex.value}`)?.scrollIntoView({ block: 'nearest' });
});

function showOptions() {
  if (props.disabled) return;
  clearTimeout(blurTimer);
  searchText.value = '';
  open.value = true;
  activeIndex.value = 0;
}
function closeOnBlur() {
  blurTimer = setTimeout(() => {
    open.value = false;
    if (selectedProduct.value) searchText.value = selectedProduct.value.name;
  }, 150);
}
onUnmounted(() => clearTimeout(blurTimer));
function updateSearch(event) {
  searchText.value = event.target.value;
  if (props.modelValue) emit('update:modelValue', '');
  open.value = true;
}
function selectProduct(product) {
  emit('update:modelValue', product.id);
  emit('select', product.id);
  searchText.value = product.name;
  open.value = false;
}
function handleKeydown(event) {
  if (event.key === 'Escape') {
    open.value = false;
    searchText.value = selectedProduct.value?.name || '';
  } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault();
    if (!open.value) showOptions();
    else if (matches.value.length) activeIndex.value = (activeIndex.value + (event.key === 'ArrowDown' ? 1 : -1) + matches.value.length) % matches.value.length;
  } else if (event.key === 'Enter' && open.value) {
    event.preventDefault();
    if (matches.value[activeIndex.value]) selectProduct(matches.value[activeIndex.value]);
  }
}
</script>

<template>
  <div class="field product-picker" @focusout="closeOnBlur">
    <label :for="listboxId">Product</label>
    <div class="product-picker-input">
      <Search :size="17" aria-hidden="true" />
      <input :id="listboxId" :value="searchText" :disabled="disabled" type="search" autocomplete="off"
        role="combobox" :aria-expanded="open" :aria-controls="`${listboxId}-options`"
        :aria-activedescendant="open && matches.length ? `${listboxId}-option-${activeIndex}` : undefined"
        aria-autocomplete="list" aria-label="Search products by name, SKU or barcode"
        placeholder="Search product, SKU or barcode" @focus="showOptions" @input="updateSearch" @keydown="handleKeydown">
      <ChevronDown :size="17" aria-hidden="true" />
    </div>
    <small v-if="selectedProduct && stockText(selectedProduct)" class="product-picker-selected-stock">{{ stockLabel }}: {{ stockText(selectedProduct) }}</small>
    <div v-if="open" :id="`${listboxId}-options`" class="product-picker-options" role="listbox" aria-label="Products">
      <button v-for="(product, index) in matches" :id="`${listboxId}-option-${index}`" :key="product.id"
        type="button" role="option" class="product-picker-option" :class="{ active: index === activeIndex }"
        :aria-selected="Number(modelValue) === product.id" @mousedown.prevent @click="selectProduct(product)">
        <span>{{ product.name }}</span><small v-if="product.sku || product.barcode">{{ [product.sku, product.barcode].filter(Boolean).join(' · ') }}</small>
        <small v-if="stockText(product)" class="product-picker-option-stock">{{ stockLabel }}: {{ stockText(product) }}</small>
      </button>
      <p v-if="!matches.length" class="product-picker-empty">No matching products</p>
      <p v-else-if="matches.length === 80" class="product-picker-more">Type to narrow the results</p>
    </div>
  </div>
</template>

<style scoped>
.product-picker { position: relative; min-width: 0; }
.product-picker-input { display: flex; align-items: center; gap: 8px; min-height: 38px; padding: 0 10px; border: 1px solid #dedde8; border-radius: 7px; background: #fff; color: #8b88a3; }
.product-picker-input:focus-within { border-color: #9a8df5; box-shadow: 0 0 0 3px rgba(115, 103, 240, .12); }
.product-picker-input input { min-width: 0; width: 100%; height: 36px; padding: 0; border: 0; outline: 0; box-shadow: none; background: transparent; color: #3b4253; font-size: 14px; }
.product-picker-input input:focus { border: 0; outline: 0; box-shadow: none; }
.product-picker-input input:disabled { cursor: not-allowed; }
.product-picker-selected-stock { display: block; margin-top: 5px; color: #686389; font-size: 11px; font-weight: 600; }
.product-picker-options { position: absolute; z-index: 30; top: calc(100% + 4px); left: 0; right: 0; max-height: 260px; overflow-y: auto; border: 1px solid #dedaf4; border-radius: 8px; background: #fff; box-shadow: 0 12px 30px rgba(42, 38, 79, .16); }
.product-picker-option { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; width: 100%; padding: 9px 12px; border: 0; border-bottom: 1px solid #f1eff7; background: #fff; color: #3b4253; text-align: left; font-size: 13px; white-space: normal; }
.product-picker-option:last-of-type { border-bottom: 0; }
.product-picker-option:hover, .product-picker-option.active { background: #f1efff; color: #5e54c8; }
.product-picker-option small, .product-picker-empty, .product-picker-more { color: #8c8fa0; font-size: 11px; }
.product-picker-option .product-picker-option-stock { color: #6154bc; font-weight: 600; }
.product-picker-empty, .product-picker-more { padding: 10px 12px; }
@media (max-width: 800px) { .product-picker-input { min-height: 44px; } .product-picker-input input { min-height: 42px; font-size: 16px; } .product-picker-option { min-height: 44px; justify-content: center; } }
</style>
