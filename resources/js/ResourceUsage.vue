<script setup>
import { computed, onMounted, ref } from 'vue';

const props = defineProps({ api: { type: Function, required: true } });
const usage = ref(null);
const loading = ref(false);
const metrics = [
  { label: 'CPU (%)', peak: 'mCPU', limit: 'lCPU', faults: 'CPUf' },
  { label: 'Physical memory', peak: 'mPMem', limit: 'lPMem', faults: 'PMemF' },
  { label: 'Virtual memory (bytes)', peak: 'mVMem', alternate: 'mVmem', limit: 'lVMem', faults: 'VMemF' },
  { label: 'Entry processes', peak: 'mEP', limit: 'lEP', faults: 'EPf' },
  { label: 'Processes', peak: 'mNproc', limit: 'lNproc', faults: 'NprocF' },
  { label: 'Disk I/O', peak: 'mIO', limit: 'lIO', faults: 'IOf' },
  { label: 'IOPS', peak: 'mIOPS', limit: 'lIOPS', faults: 'IOPSf' },
];
const samples = computed(() => usage.value?.samples ?? []);
const columns = computed(() => metrics.filter(metric => samples.value.some(sample =>
  [metric.peak, metric.alternate, metric.limit, metric.faults].some(key => sample[key] != null))));
const fetchedAt = computed(() => usage.value?.fetched_at ? new Date(usage.value.fetched_at).toLocaleString() : '');

async function refreshUsage() {
  loading.value = true;
  try {
    usage.value = await props.api('resource-usage');
  } catch {
    usage.value = { status: 'unavailable' };
  } finally {
    loading.value = false;
  }
}

onMounted(refreshUsage);
</script>

<template>
  <div class="page-heading">
    <div><h1>Resource Usage</h1><p>CloudLinux usage for your cPanel hosting account.</p></div>
    <button type="button" class="btn-secondary-app" :disabled="loading" @click="refreshUsage">Refresh</button>
  </div>
  <section class="panel table-panel" :aria-busy="loading">
    <div class="table-toolbar">
      <p v-if="loading" role="status">Loading resource usage…</p>
      <p v-else-if="usage?.status === 'not_configured'">Resource usage is not configured for this installation.</p>
      <p v-else-if="usage?.status !== 'ready' || !columns.length">Hosting metrics are currently unavailable.</p>
      <p v-else>Last fetched {{ fetchedAt }}. Each column shows peak usage / limit, followed by faults if reported.</p>
    </div>
    <div v-if="columns.length" class="table-scroll">
      <table>
        <thead><tr><th scope="col">Sample period</th><th v-for="metric in columns" :key="metric.peak" scope="col">{{ metric.label }}</th></tr></thead>
        <tbody>
          <tr v-for="(sample, index) in samples" :key="index">
            <th scope="row">{{ sample.From ?? sample.from ?? '—' }} – {{ sample.To ?? sample.to ?? '—' }}</th>
            <td v-for="metric in columns" :key="metric.peak">
              {{ sample[metric.peak] ?? sample[metric.alternate] ?? '—' }} / {{ sample[metric.limit] ?? '—' }}
              <small v-if="sample[metric.faults] != null"> · {{ sample[metric.faults] }} faults</small>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
