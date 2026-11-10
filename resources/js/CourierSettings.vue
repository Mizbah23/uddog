<script setup>
import { ChevronDown, KeyRound, ShieldCheck, Truck } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { translate } from './localization';

const props = defineProps({
  api: { type: Function, required: true },
  language: String,
  disabled: Boolean,
});
const emit = defineEmits(['loaded', 'saving']);
const configured = ref(false);
const enabled = ref(false);
const form = ref({ enabled: false, api_key: '', secret_key: '' });
const loading = ref(true);
const ready = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const t = value => translate(value, props.language);

function applySettings(settings) {
  configured.value = settings.configured;
  enabled.value = settings.enabled;
  form.value = { enabled: settings.enabled, api_key: '', secret_key: '' };
  emit('loaded', settings.enabled);
}

async function load() {
  loading.value = true;
  error.value = '';
  try {
    applySettings(await props.api('ecommerce/courier'));
    ready.value = true;
  } catch (exception) {
    error.value = exception.message;
  } finally {
    loading.value = false;
  }
}

async function save() {
  if (!ready.value || saving.value || props.disabled) return;
  saving.value = true;
  emit('saving', true);
  notice.value = '';
  error.value = '';
  try {
    applySettings(await props.api('ecommerce/courier', { method: 'PUT', body: form.value }));
    notice.value = t('Courier settings saved.');
  } catch (exception) {
    error.value = exception.message;
  } finally {
    saving.value = false;
    emit('saving', false);
  }
}

onMounted(load);
</script>

<template>
  <details class="panel courier-panel">
    <summary class="courier-summary">
      <span class="courier-icon"><Truck :size="21" aria-hidden="true" /></span>
      <span class="courier-heading">
        <strong>{{ t('Steadfast courier settings') }}</strong>
        <small>{{ t('Book shipments and save tracking codes from Online Orders.') }}</small>
      </span>
      <span v-if="ready" class="courier-badge" :class="{ enabled }">
        {{ t(enabled ? 'Booking enabled' : 'Booking disabled') }}
      </span>
      <ChevronDown class="courier-chevron" :size="19" aria-hidden="true" />
    </summary>

    <div class="courier-body" :aria-busy="loading || saving">
      <p v-if="loading" role="status">{{ t('Loading courier settings…') }}</p>
      <div v-if="error" class="alert-box error" role="alert">{{ error }}</div>
      <button v-if="!loading && !ready" type="button" class="btn-secondary-app small" @click="load">{{ t('Retry') }}</button>

      <form v-if="ready" class="courier-form" @submit.prevent="save">
        <label class="courier-toggle">
          <input v-model="form.enabled" type="checkbox" role="switch" :disabled="saving || disabled">
          <span>{{ t('Book with Steadfast when an order is placed') }}</span>
        </label>
        <div class="courier-credentials">
          <label class="field">
            <span>{{ t('API key') }}</span>
            <input v-model.trim="form.api_key" type="password" autocomplete="new-password" maxlength="255" :disabled="saving || disabled">
          </label>
          <label class="field">
            <span>{{ t('Secret key') }}</span>
            <input v-model.trim="form.secret_key" type="password" autocomplete="new-password" maxlength="255" :disabled="saving || disabled">
          </label>
        </div>
        <p class="courier-hint">
          <ShieldCheck :size="16" aria-hidden="true" />
          {{ t(configured ? 'Credentials saved. Leave both fields blank to keep them.' : 'Enter both keys from your Steadfast merchant account.') }}
        </p>
        <div class="courier-footer">
          <span v-if="notice" class="courier-notice" role="status">{{ notice }}</span>
          <button class="btn-primary-app" type="submit" :disabled="saving || disabled">
            <KeyRound :size="16" aria-hidden="true" />
            {{ t(saving ? 'Saving...' : 'Save courier settings') }}
          </button>
        </div>
      </form>
    </div>
  </details>
</template>

<style scoped>
.courier-panel { margin-block: 16px; border: 1px solid #e8e4fa; border-radius: 16px; }
.courier-summary {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto auto;
  align-items: center;
  gap: 12px;
  padding: 16px;
  cursor: pointer;
  list-style: none;
  border-radius: inherit;
  background: linear-gradient(105deg, #f3efff, #fff 80%);
}
.courier-summary::-webkit-details-marker { display: none; }
.courier-summary:focus-visible { outline: 2px solid #7367f0; outline-offset: 3px; }
.courier-icon {
  display: grid;
  place-items: center;
  width: 42px;
  height: 42px;
  border-radius: 12px;
  background: #7367f0;
  color: #fff;
}
.courier-heading { grid-column: 2; }
.courier-heading strong, .courier-heading small { display: block; }
.courier-heading strong { color: #34364d; font-size: 15px; }
.courier-heading small { margin-top: 4px; color: #666b80; font-size: 12px; }
.courier-badge { grid-column: 3; padding: 4px 8px; border-radius: 100px; background: #eeedf5; color: #555970; font-size: 11px; font-weight: 700; }
.courier-badge.enabled { background: #e3f7ed; color: #187149; }
.courier-chevron { grid-column: 4; color: #6456cf; transition: transform .18s ease; }
.courier-panel[open] > .courier-summary { border-radius: 15px 15px 0 0; }
.courier-panel[open] .courier-chevron { transform: rotate(180deg); }
.courier-body { padding: 16px; border-top: 1px solid #eeeaf8; }
.courier-form { display: grid; gap: 14px; }
.courier-toggle { display: flex; align-items: center; gap: 10px; padding: 12px; background: #f8f7fd; border-radius: 10px; font-size: 13px; font-weight: 650; cursor: pointer; }
.courier-toggle input { appearance: none; flex: none; width: 36px; height: 21px; margin: 0; border-radius: 100px; background: #797c91; cursor: pointer; display: flex; align-items: center; padding: 3px; }
.courier-toggle input::before { content: ''; width: 15px; height: 15px; border-radius: 50%; background: #fff; }
.courier-toggle input:checked { background: #6858dc; justify-content: flex-end; }
.courier-toggle input:focus-visible { outline: 2px solid #7367f0; outline-offset: 3px; }
.courier-toggle input:disabled { opacity: .6; cursor: default; }
.courier-credentials { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.courier-hint, .courier-footer { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 12px; }
.courier-hint { color: #666b80; }
.courier-footer { justify-content: flex-end; }
.courier-notice { color: #187149; }
@media (max-width: 600px) {
  .courier-summary { grid-template-columns: auto minmax(0, 1fr) auto; gap: 8px 10px; }
  .courier-badge { grid-column: 2; grid-row: 2; justify-self: start; }
  .courier-chevron { grid-column: 3; grid-row: 1; }
  .courier-credentials { grid-template-columns: 1fr; }
}
@media (prefers-reduced-motion: reduce) {
  .courier-chevron { transition: none; }
}
</style>
