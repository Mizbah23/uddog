<script setup>
import { computed, getCurrentInstance, nextTick, onUnmounted, ref, watch } from 'vue';
import { ChevronDown, Search } from '@lucide/vue';

const props = defineProps({
  modelValue: { type: [Number, String], default: '' },
  contacts: { type: Array, required: true },
  kind: { type: String, default: 'customer' },
  language: { type: String, default: 'en' },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
const inputId = `contact-picker-${getCurrentInstance().uid}`;
const searchText = ref('');
const open = ref(false);
const activeIndex = ref(0);
let blurTimer;

const selectedContact = computed(() => props.contacts.find(contact => contact.id === Number(props.modelValue)));
const matches = computed(() => {
  const query = searchText.value.trim().toLocaleLowerCase();
  return props.contacts.filter(contact => !query || [contact.name, contact.company, contact.phone, contact.email]
    .some(value => String(value || '').toLocaleLowerCase().includes(query))).slice(0, 80);
});
const placeholder = computed(() => props.language === 'bn'
  ? (props.kind === 'supplier' ? 'সরবরাহকারী খুঁজুন' : 'ক্রেতা খুঁজুন')
  : `Search ${props.kind} by name or phone`);

watch(selectedContact, contact => {
  if (!open.value) searchText.value = contact?.name || '';
}, { immediate: true });
watch(matches, () => { activeIndex.value = 0; });
watch(activeIndex, async () => {
  await nextTick();
  document.getElementById(`${inputId}-option-${activeIndex.value}`)?.scrollIntoView({ block: 'nearest' });
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
    searchText.value = selectedContact.value?.name || '';
  }, 150);
}
onUnmounted(() => clearTimeout(blurTimer));
function updateSearch(event) {
  searchText.value = event.target.value;
  if (props.modelValue) emit('update:modelValue', '');
  open.value = true;
}
function selectContact(contact) {
  emit('update:modelValue', contact.id);
  searchText.value = contact.name;
  open.value = false;
}
function handleKeydown(event) {
  if (event.key === 'Escape') {
    open.value = false;
    searchText.value = selectedContact.value?.name || '';
  } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault();
    if (!open.value) showOptions();
    else if (matches.value.length) activeIndex.value = (activeIndex.value + (event.key === 'ArrowDown' ? 1 : -1) + matches.value.length) % matches.value.length;
  } else if (event.key === 'Enter' && open.value) {
    event.preventDefault();
    if (matches.value[activeIndex.value]) selectContact(matches.value[activeIndex.value]);
  }
}
</script>

<template>
  <div class="contact-picker" @focusout="closeOnBlur">
    <div class="contact-picker-input">
      <Search :size="17" aria-hidden="true" />
      <input :id="inputId" :value="searchText" :disabled="disabled" type="search" autocomplete="off"
        role="combobox" :aria-expanded="open" :aria-controls="`${inputId}-options`"
        :aria-activedescendant="open && matches.length ? `${inputId}-option-${activeIndex}` : undefined"
        aria-autocomplete="list" :aria-label="placeholder" :placeholder="placeholder"
        @focus="showOptions" @input="updateSearch" @keydown="handleKeydown">
      <ChevronDown :size="17" aria-hidden="true" />
    </div>
    <div v-if="open" :id="`${inputId}-options`" class="contact-picker-options" role="listbox" :aria-label="kind === 'supplier' ? 'Suppliers' : 'Customers'">
      <button v-for="(contact, index) in matches" :id="`${inputId}-option-${index}`" :key="contact.id"
        type="button" role="option" class="contact-picker-option" :class="{ active: index === activeIndex }"
        :aria-selected="Number(modelValue) === contact.id" @mousedown.prevent @click="selectContact(contact)">
        <strong>{{ contact.name }}</strong>
        <small v-if="contact.company || contact.phone">{{ [contact.company, contact.phone].filter(Boolean).join(' · ') }}</small>
      </button>
      <p v-if="!matches.length" class="contact-picker-empty">{{ language === 'bn' ? 'কোনো মিল পাওয়া যায়নি' : `No matching ${kind}s` }}</p>
      <p v-else-if="matches.length === 80" class="contact-picker-more">{{ language === 'bn' ? 'আরও নির্দিষ্ট করে লিখুন' : 'Type to narrow the results' }}</p>
    </div>
  </div>
</template>

<style scoped>
.contact-picker { position: relative; flex: 1 1 0; min-width: 0; }
.contact-picker-input { display: flex; align-items: center; gap: 8px; min-height: 38px; padding: 0 10px; border: 1px solid #dedde8; border-radius: 7px; background: #fff; color: #8b88a3; }
.contact-picker-input:focus-within { border-color: #9a8df5; box-shadow: 0 0 0 3px rgba(115, 103, 240, .12); }
.contact-picker-input input { min-width: 0; width: 100%; height: 36px; padding: 0; border: 0; outline: 0; box-shadow: none; background: transparent; color: #3b4253; font-size: 14px; }
.contact-picker-input input:focus { border: 0; outline: 0; box-shadow: none; }
.contact-picker-input input:disabled { cursor: not-allowed; }
.contact-picker-options { position: absolute; z-index: 30; top: calc(100% + 4px); left: 0; right: 0; max-height: 260px; overflow-y: auto; border: 1px solid #dedaf4; border-radius: 8px; background: #fff; box-shadow: 0 12px 30px rgba(42, 38, 79, .16); }
.contact-picker-option { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; width: 100%; padding: 9px 12px; border: 0; border-bottom: 1px solid #f1eff7; background: #fff; color: #3b4253; text-align: left; font-size: 13px; white-space: normal; }
.contact-picker-option:last-of-type { border-bottom: 0; }
.contact-picker-option:hover, .contact-picker-option.active { background: #f1efff; color: #5e54c8; }
.contact-picker-option small, .contact-picker-empty, .contact-picker-more { color: #8c8fa0; font-size: 11px; }
.contact-picker-empty, .contact-picker-more { padding: 10px 12px; }
@media (max-width: 800px) { .contact-picker-input { min-height: 44px; } .contact-picker-input input { min-height: 42px; font-size: 16px; } .contact-picker-option { min-height: 44px; justify-content: center; } }
@media (max-width: 560px) { .contact-picker { width: 100%; flex: auto; } }
</style>
