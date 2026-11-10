<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Search } from '@lucide/vue';
import { translate } from './localization';

const props = defineProps({ api: Function, date: Function, language: String });
const emit = defineEmits(['open-orders']);
const customers = ref([]);
const search = ref('');
const currentPage = ref(1);
const pageSize = 25;
const loading = ref(true);
const error = ref('');
const flagsAvailable = ref(true);
const t = value => translate(value, props.language);
const matches = computed(() => {
  const term = search.value.trim().toLowerCase();
  return customers.value.filter(customer =>
    [customer.customer_name, customer.phone, customer.last_order_number].some(value => value.toLowerCase().includes(term)),
  );
});
const pageCount = computed(() => Math.max(1, Math.ceil(matches.value.length / pageSize)));
const visibleCustomers = computed(() => {
  const start = (currentPage.value - 1) * pageSize;
  return matches.value.slice(start, start + pageSize);
});
watch(search, () => { currentPage.value = 1; });

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const result = await props.api('ecommerce/flagged-customers');
    customers.value = result.data;
    flagsAvailable.value = result.flags_available;
    currentPage.value = 1;
  } catch (exception) {
    error.value = exception.message;
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <section>
    <div class="page-heading">
      <div>
        <span class="eyebrow">E-COMMERCE</span>
        <h1>{{ t('Fake Orders') }}</h1>
        <p>{{ t('Customers with orders flagged as suspicious, grouped by phone number.') }}</p>
      </div>
      <button class="btn-secondary-app" type="button" @click="emit('open-orders')">{{ t('Online orders') }}</button>
    </div>

    <section class="panel table-panel">
      <div class="table-toolbar">
        <h2>{{ t('Flagged customers') }}</h2>
        <div class="search-box">
          <Search :size="16" aria-hidden="true" />
          <input v-model="search" :aria-label="t('Search by name, phone, or order number')" :placeholder="t('Search by name, phone, or order number')">
        </div>
      </div>
      <div v-if="loading" class="empty-state" role="status">{{ t('Loading…') }}</div>
      <div v-else-if="error" class="empty-state" role="alert">
        <span>{{ error }}</span>
        <button class="btn-secondary-app small" type="button" @click="load">{{ t('Retry') }}</button>
      </div>
      <div v-else-if="!flagsAvailable" class="empty-state">{{ t('Apply the database update to see flagged customers.') }}</div>
      <div v-else-if="!matches.length" class="empty-state">
        {{ t(search.trim() ? 'No flagged customers match your search.' : 'No flagged customers yet.') }}
      </div>
      <ul v-else class="flagged-list">
        <li v-for="customer in visibleCustomers" :key="customer.phone" class="flagged-row">
          <div class="flagged-customer">
            <strong>{{ customer.customer_name }}</strong>
            <a :href="`tel:${customer.phone}`">{{ customer.phone }}</a>
          </div>
          <span class="stock-pill low">{{ t('Flagged orders') }} · {{ customer.flag_count }}</span>
          <div class="flagged-latest">
            <strong>{{ t('Latest flagged order') }} · {{ customer.last_order_number }}</strong>
            <time :datetime="customer.last_flagged_at">{{ date(customer.last_flagged_at) }}</time>
            <p v-if="customer.last_reason">{{ customer.last_reason }}</p>
          </div>
        </li>
      </ul>
      <nav v-if="!loading && !error && pageCount > 1" class="expense-pagination" :aria-label="t('Fake Orders pagination')">
        <button type="button" class="btn-secondary-app small" :disabled="currentPage === 1" @click="currentPage--">{{ t('Previous') }}</button>
        <span aria-live="polite">{{ currentPage }} / {{ pageCount }}</span>
        <button type="button" class="btn-secondary-app small" :disabled="currentPage === pageCount" @click="currentPage++">{{ t('Next') }}</button>
      </nav>
    </section>
  </section>
</template>

<style scoped>
.flagged-list { list-style: none; padding: 0; margin: 0; }
.flagged-row { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1.4fr); gap: 12px 20px; align-items: start; padding: 13px 20px; border-top: 1px solid #efedf6; overflow-wrap: anywhere; font-size: 13px; }
.flagged-customer, .flagged-latest { display: grid; gap: 4px; min-width: 0; }
.flagged-customer a, .flagged-latest time { font-size: 12px; }
.flagged-latest p { color: #666b80; margin: 0; }
@media (max-width: 700px) {
  .flagged-row { grid-template-columns: minmax(0, 1fr) auto; gap: 8px; padding-inline: 12px; }
  .flagged-latest { grid-column: 1 / -1; }
}
</style>
