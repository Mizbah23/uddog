<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Plus, Users, Banknote, CalendarDays, Pencil, Trash2, X, Check, ReceiptText } from '@lucide/vue';

const props = defineProps({
  branchId: { type: [Number, String], required: true },
  request: { type: Function, required: true },
  money: { type: Function, required: true },
  date: { type: Function, required: true },
  paymentLabel: { type: Function, required: true },
  language: { type: String, required: true },
});
const emit = defineEmits(['payroll-recorded']);
const staff = ref([]);
const runs = ref([]);
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const modal = ref('');
const editingId = ref(null);
const today = () => new Date().toLocaleDateString('en-CA');
const payroll = ref({ period: today().slice(0, 7), payment_date: today(), payment_method: 'cash' });
const form = ref(emptyStaff());
const expandedRun = ref(null);

function emptyStaff() {
  return { name: '', job_title: '', phone: '', email: '', monthly_salary: '', start_date: today(), active: true };
}

async function load() {
  if (!props.branchId) return;
  loading.value = true;
  error.value = '';
  try {
    const branch = encodeURIComponent(props.branchId);
    [staff.value, runs.value] = await Promise.all([
      props.request(`staff?branch_id=${branch}`),
      props.request(`payroll?branch_id=${branch}`),
    ]);
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}

onMounted(load);
watch(() => props.branchId, load);

const eligibleStaff = computed(() => {
  const monthEnd = new Date(`${payroll.value.period}-01T00:00:00`);
  monthEnd.setMonth(monthEnd.getMonth() + 1, 0);
  const end = [monthEnd.getFullYear(), String(monthEnd.getMonth() + 1).padStart(2, '0'), String(monthEnd.getDate()).padStart(2, '0')].join('-');
  return staff.value.filter(member => member.active && member.start_date <= end);
});
const payrollTotal = computed(() => eligibleStaff.value.reduce((sum, member) => sum + Number(member.monthly_salary || 0), 0));
const selectedRun = computed(() => runs.value.find(run => run.period === payroll.value.period));
const activeCount = computed(() => staff.value.filter(member => member.active).length);
const monthlyTotal = computed(() => staff.value.filter(member => member.active).reduce((sum, member) => sum + Number(member.monthly_salary || 0), 0));

function openStaff(member = null) {
  error.value = '';
  editingId.value = member?.id || null;
  form.value = member ? {
    name: member.name, job_title: member.job_title, phone: member.phone || '', email: member.email || '',
    monthly_salary: member.monthly_salary, start_date: member.start_date, active: member.active,
  } : emptyStaff();
  modal.value = 'staff';
}

async function saveStaff() {
  busy.value = true;
  error.value = '';
  try {
    await props.request(editingId.value ? `staff/${editingId.value}` : 'staff', {
      method: editingId.value ? 'PUT' : 'POST',
      body: { ...form.value, branch_id: props.branchId },
    });
    modal.value = '';
    notice.value = editingId.value ? 'Staff profile updated.' : 'Staff member added.';
    await load();
  } catch (e) { error.value = e.message; }
  finally { busy.value = false; }
}

async function removeStaff(member) {
  const prompt = props.language === 'bn'
    ? `${member.name}-কে কর্মীর তালিকা থেকে সরাবেন? আগের বেতনের তথ্য সংরক্ষিত থাকবে।`
    : `Remove ${member.name} from the staff list? Past salary records will be kept.`;
  if (!confirm(prompt)) return;
  busy.value = true;
  error.value = '';
  try {
    await props.request(`staff/${member.id}`, { method: 'DELETE' });
    notice.value = 'Staff member removed. Past payroll and expenses are unchanged.';
    await load();
  } catch (e) { error.value = e.message; }
  finally { busy.value = false; }
}

async function generatePayroll() {
  if (!eligibleStaff.value.length || selectedRun.value) return;
  const prompt = props.language === 'bn'
    ? `${payroll.value.period} মাসের ${eligibleStaff.value.length} জন কর্মীর বেতন নথিভুক্ত করবেন? ${props.money(payrollTotal.value)} বেতন খরচ হিসেবে যোগ হবে। এই শাখা ও মাসের জন্য দ্বিতীয়বার করা যাবে না।`
    : `Generate ${payroll.value.period} salary for ${eligibleStaff.value.length} staff? This will record ${props.money(payrollTotal.value)} as Payroll expenses and cannot be run twice for this branch and month.`;
  if (!confirm(prompt)) return;
  busy.value = true;
  error.value = '';
  try {
    await props.request('payroll', { method: 'POST', body: { ...payroll.value, branch_id: props.branchId } });
    notice.value = 'Salary generated. Each payment is now recorded in Expenses and the net-profit report.';
    await load();
    emit('payroll-recorded');
  } catch (e) { error.value = e.message; }
  finally { busy.value = false; }
}
</script>

<template>
  <div class="staff-module">
    <div class="page-heading"><div><span class="eyebrow">TEAM & PAYROLL</span><h1>Staff</h1><p>Manage your branch team and record everyone’s monthly salary in one run.</p></div><button class="btn-primary-app" @click="openStaff()"><Plus :size="18" /> Add staff</button></div>
    <div v-if="error && !modal" class="alert-box error">{{ error }}</div>
    <div v-if="notice" class="alert-box success">{{ notice }}</div>
    <div class="category-stats staff-stats">
      <div class="stat-card"><div class="stat-top"><span>Active staff</span><span class="stat-icon violet"><Users :size="19" /></span></div><strong>{{ activeCount }}</strong><small>{{ staff.length }} {{ language === 'bn' ? 'জন কর্মী এই শাখায়' : 'profiles in this branch' }}</small></div>
      <div class="stat-card"><div class="stat-top"><span>Monthly base salary</span><span class="stat-icon orange"><Banknote :size="19" /></span></div><strong>{{ money(monthlyTotal) }}</strong><small>Current active staff salaries</small></div>
      <div class="stat-card"><div class="stat-top"><span>Latest payroll</span><span class="stat-icon blue"><CalendarDays :size="19" /></span></div><strong>{{ runs[0]?.period || '—' }}</strong><small>{{ runs[0] ? `${runs[0].staff_count} ${language === 'bn' ? 'জন কর্মী' : 'staff'} · ${money(runs[0].total_amount)}` : 'No salaries generated yet' }}</small></div>
    </div>

    <section class="panel staff-payroll-panel">
      <div class="staff-section-heading"><div><span class="eyebrow">BATCH PAYMENT</span><h2>Generate monthly salary</h2><p>All eligible active staff will be included. Each salary becomes a Payroll expense on the payment date.</p></div><span class="staff-total-badge">{{ money(payrollTotal) }}</span></div>
      <div class="staff-payroll-controls"><label class="field"><span>Salary month</span><input v-model="payroll.period" type="month" required></label><label class="field"><span>Payment date</span><input v-model="payroll.payment_date" type="date" required></label><label class="field"><span>Payment method</span><select v-model="payroll.payment_method"><option value="cash">Cash</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option><option value="bank_transfer">Bank transfer</option></select></label></div>
      <div class="staff-payroll-preview"><strong>{{ eligibleStaff.length }} {{ language === 'bn' ? 'জন কর্মী অন্তর্ভুক্ত' : `${eligibleStaff.length === 1 ? 'employee' : 'employees'} included` }}</strong><span v-if="eligibleStaff.length">{{ eligibleStaff.map(member => member.name).join(' · ') }}</span><span v-else>Add an active staff member with a start date in or before this month.</span></div>
      <div class="staff-payroll-action"><span v-if="selectedRun" class="staff-paid-note"><Check :size="17" /> {{ language === 'bn' ? `এই শাখা ও মাসের বেতন ${date(selectedRun.payment_date)} তারিখে নথিভুক্ত হয়েছে।` : `Already generated for this branch and month on ${date(selectedRun.payment_date)}.` }}</span><span v-else>One salary run per branch per month. Review amounts before generating.</span><button class="btn-primary-app" :disabled="busy || loading || !eligibleStaff.length || !!selectedRun || !payroll.payment_date" @click="generatePayroll"><Banknote :size="17" /> {{ language === 'bn' ? `${eligibleStaff.length} জনের বেতন প্রদান করুন` : `Generate ${eligibleStaff.length} salaries` }}</button></div>
    </section>

    <section class="panel table-panel"><div class="table-toolbar"><div><h2>Staff directory</h2><p>{{ staff.length }} {{ language === 'bn' ? 'জন কর্মী · বেতন পরিবর্তন শুধু ভবিষ্যতের বেতন প্রদানে প্রযোজ্য' : 'profiles · changing a salary only affects future payroll' }}</p></div></div><div class="table-scroll"><table><thead><tr><th>STAFF</th><th>ROLE</th><th>PHONE</th><th>START DATE</th><th>MONTHLY SALARY</th><th>STATUS</th><th></th></tr></thead><tbody><tr v-for="member in staff" :key="member.id"><td><div class="name-cell"><span class="product-avatar">{{ member.name.charAt(0).toUpperCase() }}</span><div><strong>{{ member.name }}</strong><small>{{ member.email || '—' }}</small></div></div></td><td>{{ member.job_title }}</td><td>{{ member.phone || '—' }}</td><td>{{ date(member.start_date) }}</td><td class="strong">{{ money(member.monthly_salary) }}</td><td><span class="stock-pill" :class="member.active ? 'good' : 'low'">{{ member.active ? 'Active' : 'Inactive' }}</span></td><td><div class="row-actions"><button class="icon-button" :aria-label="`Edit ${member.name}`" @click="openStaff(member)"><Pencil :size="17" /></button><button class="icon-button danger" :aria-label="`Remove ${member.name}`" :disabled="busy" @click="removeStaff(member)"><Trash2 :size="17" /></button></div></td></tr></tbody></table></div><div v-if="!staff.length && !loading" class="empty-state"><Users :size="32" /><strong>No staff yet</strong><span>Add your first employee to prepare a monthly salary run.</span></div></section>

    <section class="panel table-panel"><div class="table-toolbar"><div><h2>Payroll history</h2><p>Salary snapshots and matching expense entries are kept for audit.</p></div></div><div class="table-scroll"><table><thead><tr><th>MONTH</th><th>PAID ON</th><th>STAFF</th><th>METHOD</th><th>AMOUNT</th><th>GENERATED BY</th><th></th></tr></thead><tbody v-for="run in runs" :key="run.id"><tr><td class="strong">{{ run.period }}</td><td>{{ date(run.payment_date) }}</td><td>{{ run.staff_count }}</td><td>{{ paymentLabel(run.payment_method) }}</td><td class="strong">{{ money(run.total_amount) }}</td><td>{{ run.creator?.name || '—' }}</td><td><button class="btn-secondary-app small" @click="expandedRun = expandedRun === run.id ? null : run.id">{{ expandedRun === run.id ? 'Hide details' : 'View details' }}</button></td></tr><tr v-if="expandedRun === run.id"><td colspan="7"><div class="staff-run-details"><div v-for="item in run.items" :key="item.id"><span>{{ item.staff_name }} · {{ item.job_title }}</span><strong>{{ money(item.amount) }}</strong></div></div></td></tr></tbody></table></div><div v-if="!runs.length && !loading" class="empty-state"><ReceiptText :size="32" /><strong>No payroll history</strong><span>Generated salaries will appear here and in Expenses.</span></div></section>

    <div v-if="modal === 'staff'" class="modal-backdrop-app" @click.self="modal = ''"><div class="modal-card"><div class="modal-head"><div><span class="eyebrow">STAFF PROFILE</span><h2>{{ editingId ? 'Edit staff' : 'Add staff' }}</h2></div><button class="icon-button" @click="modal = ''"><X :size="20" /></button></div><div v-if="error" class="alert-box error">{{ error }}</div><form @submit.prevent="saveStaff"><div class="form-grid"><label class="field"><span>Full name *</span><input v-model.trim="form.name" maxlength="150" required placeholder="Employee name"></label><label class="field"><span>Job title *</span><input v-model.trim="form.job_title" maxlength="150" required placeholder="e.g. Sales associate"></label><label class="field"><span>Phone</span><input v-model.trim="form.phone" maxlength="30" type="tel" placeholder="Mobile number"></label><label class="field"><span>Email</span><input v-model.trim="form.email" maxlength="255" type="email" placeholder="Optional email"></label><label class="field"><span>Monthly salary *</span><input v-model="form.monthly_salary" type="number" min="0.01" step="0.01" required></label><label class="field"><span>Start date *</span><input v-model="form.start_date" type="date" required></label><label class="field staff-active-field"><input v-model="form.active" type="checkbox"><span>Active staff member</span></label></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="modal = ''">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Saving...' : editingId ? 'Update staff' : 'Save staff' }}</button></div></form></div></div>
  </div>
</template>
