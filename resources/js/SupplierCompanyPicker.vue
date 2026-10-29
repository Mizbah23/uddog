<script setup>
import { computed, getCurrentInstance, onUnmounted, ref, watch } from 'vue';
import { ChevronDown, Search } from '@lucide/vue';

const props = defineProps({ modelValue: { type: String, default: '' }, companies: { type: Array, required: true }, language: { type: String, required: true } });
const emit = defineEmits(['update:modelValue']);
const id = `supplier-company-${getCurrentInstance().uid}`;
const open = ref(false);
const active = ref(0);
let blurTimer;
const query = computed(() => props.modelValue.trim().toLocaleLowerCase());
const matches = computed(() => props.companies.filter(name => name.toLocaleLowerCase().includes(query.value)).slice(0, 60));
const canAdd = computed(() => query.value && !props.companies.some(name => name.toLocaleLowerCase() === query.value));
watch(query, () => { active.value = 0; });
function select(name) { emit('update:modelValue', name); open.value = false; }
function onKeydown(event) {
  if (event.key === 'Escape') open.value = false;
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault(); open.value = true;
    const length = matches.value.length + Number(canAdd.value);
    if (length) active.value = (active.value + (event.key === 'ArrowDown' ? 1 : -1) + length) % length;
  }
  if (event.key === 'Enter' && open.value) {
    event.preventDefault();
    select(canAdd.value && active.value === 0 ? props.modelValue.trim() : matches.value[active.value - Number(canAdd.value)] || props.modelValue.trim());
  }
}
onUnmounted(() => clearTimeout(blurTimer));
</script>

<template>
  <div class="company-picker" @focusout="blurTimer = setTimeout(() => { open = false; }, 150)">
    <div class="picker-input"><Search :size="17" aria-hidden="true" />
      <input :id="id" :value="modelValue" type="search" autocomplete="off" maxlength="255" required
        role="combobox" aria-autocomplete="list" :aria-expanded="open" :aria-controls="`${id}-options`"
        :placeholder="language === 'bn' ? 'কোম্পানি খুঁজুন বা নতুন নাম লিখুন' : 'Search or type a new company'"
        @focus="open = true" @input="emit('update:modelValue', $event.target.value); open = true" @keydown="onKeydown">
      <ChevronDown :size="17" aria-hidden="true" @click="open = !open" />
    </div>
    <div v-if="open" :id="`${id}-options`" class="picker-options" role="listbox">
      <button v-if="canAdd" type="button" role="option" class="picker-option add" :class="{ active: active === 0 }"
        @mousedown.prevent @click="select(modelValue.trim())">+ {{ language === 'bn' ? 'নতুন কোম্পানি:' : 'New company:' }} {{ modelValue.trim() }}</button>
      <button v-for="(name, index) in matches" :key="name" type="button" role="option" class="picker-option" :class="{ active: active === index + Number(canAdd) }"
        @mousedown.prevent @click="select(name)">{{ name }}</button>
      <p v-if="!matches.length && !canAdd" class="picker-empty">{{ language === 'bn' ? 'কোম্পানির নাম লিখুন' : 'Type a company name' }}</p>
    </div>
  </div>
</template>

<style scoped>
.company-picker{position:relative;min-width:0}.picker-input{display:flex;align-items:center;gap:8px;min-height:46px;padding:0 12px;border:1px solid #dedde8;border-radius:8px;background:#fff;color:#8b88a3}.picker-input:focus-within{border-color:#9a8df5;box-shadow:0 0 0 3px rgba(115,103,240,.12)}.picker-input input{width:100%;min-width:0;min-height:44px;border:0;outline:0;background:transparent;color:#3b4253;font:inherit}.picker-options{position:absolute;z-index:30;top:calc(100% + 4px);left:0;right:0;max-height:260px;overflow-y:auto;border:1px solid #dedaf4;border-radius:8px;background:#fff;box-shadow:0 12px 30px rgba(42,38,79,.16)}.picker-option{display:block;width:100%;padding:12px;border:0;border-bottom:1px solid #f1eff7;background:#fff;color:#3b4253;text-align:left;font:inherit}.picker-option:hover,.picker-option.active{background:#f1efff;color:#5e54c8}.picker-option.add{color:#6659d5}.picker-empty{padding:12px;color:#8c8fa0}@media(max-width:800px){.picker-input input{font-size:16px}}
</style>
