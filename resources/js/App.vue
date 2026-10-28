<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { installLocalization, translate } from './localization';
import PosReceipt from './PosReceipt.vue';
import StaffModule from './StaffModule.vue';
import SupplierAdvances from './SupplierAdvances.vue';
import ProductDamages from './ProductDamages.vue';
import ProductPicker from './ProductPicker.vue';
import ContactPicker from './ContactPicker.vue';
import { saveProfitLossImage, saveProfitLossPdf } from './profitLossExport';
import { saveProductLedgerImage, saveProductLedgerPdf } from './productLedgerExport';
import '../css/warranty.css';
import '../css/sales-targets.css';
import '../css/design-refresh.css';
import '../css/contact-directory.css';
import '../css/branch-access.css';
import '../css/staff-module.css';
import '../css/responsive.css';
import '../css/client-mobile-dashboard.css';
import '../css/profit-loss-report.css';
import { LayoutDashboard, Package, Users, ShoppingCart, ShoppingBag, Repeat2, Undo2, Boxes, Plus, Search, Menu, X, LogOut, ArrowLeft, ArrowUpRight, ArrowDownRight, AlertTriangle, ChevronDown, Trash2, Pencil, Check, Clock3, Building2, UserCog, ShieldCheck, Eye, EyeOff, CalendarPlus, Info, ClipboardList, ScanLine, Minus, Banknote, ReceiptText, Barcode, Printer, FileText, Image as ImageIcon, Tags, MapPin, ClipboardCheck, ArrowLeftRight, Target, Languages, ChartNoAxesCombined, Settings2, Download } from '@lucide/vue';

const workspaceNav = [
  { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, abilities: ['dashboard'] },
  { id: 'products', label: 'Products', icon: Package, abilities: ['products'] },
  { id: 'categories', label: 'Categories', icon: Tags, abilities: ['categories'] },
  { id: 'branches', label: 'Branches', icon: MapPin, abilities: ['branches'] },
  { id: 'customers', label: 'Customers', icon: Users, abilities: ['contacts'] },
  { id: 'suppliers', label: 'Suppliers', icon: ShoppingBag, abilities: ['contacts'] },
  { id: 'purchase', label: 'Purchases', icon: ShoppingBag, abilities: ['purchases'] },
  { id: 'supplier_advances', label: 'Supplier Advances', icon: Banknote, abilities: ['supplier_advances'] },
  { id: 'pos', label: 'POS Sale', icon: ScanLine, abilities: ['pos_sale'] },
  { id: 'sale', label: 'Sales', icon: ShoppingCart, abilities: ['sales'] },
  { id: 'sale_return', label: 'Sales returns', icon: Undo2, abilities: ['sales_returns'] },
  { id: 'resale', label: 'Resales', icon: Repeat2, abilities: ['resales'] },
  { id: 'purchase_return', label: 'Purchase returns', icon: Undo2, abilities: ['purchase_returns'] },
  { id: 'inventory', label: 'Inventory', icon: Boxes, abilities: ['inventory', 'stock_adjustments'] },
  { id: 'product_damages', label: 'Damaged products', icon: AlertTriangle, abilities: ['product_damages'] },
  { id: 'stock_checks', label: 'Stock Check', icon: ClipboardCheck, abilities: ['stock_checks'] },
  { id: 'stock_transfers', label: 'Stock Transfer', icon: ArrowLeftRight, abilities: ['stock_transfers'] },
  { id: 'warranties', label: 'Warranty Search', icon: ShieldCheck, abilities: ['warranty_search'] },
  { id: 'sales_targets', label: 'Sales Targets', icon: Target, abilities: ['sales_targets'] },
  { id: 'expenses', label: 'Expenses', icon: ReceiptText, abilities: ['expenses'] },
  { id: 'gold_rates', label: 'Metal rates', icon: ChartNoAxesCombined, abilities: ['products', 'purchases', 'sales'] },
  { id: 'gold_jobs', label: 'Goldsmith jobs', icon: ClipboardList, abilities: ['contacts', 'purchases', 'sales'] },
  { id: 'gold_report', label: 'Gold stock report', icon: Boxes, abilities: ['products', 'purchases', 'sales'] },
  { id: 'reports', label: 'Reports', icon: ChartNoAxesCombined, abilities: ['sales', 'sales_returns', 'resales', 'purchases', 'purchase_returns', 'inventory', 'stock_adjustments', 'products', 'categories', 'contacts', 'expenses'] },
];
const managementNav = [
  { id: 'admin_dashboard', label: 'Overview', icon: LayoutDashboard, permission: 'manage_clients' },
  { id: 'clients', label: 'Companies', icon: Building2, permission: 'manage_clients' },
  { id: 'access_control', label: 'Access control', icon: ShieldCheck, permission: 'manage_clients' },
  { id: 'users', label: 'Users', icon: UserCog, permission: 'manage_users' },
  { id: 'staff', label: 'Staff & Payroll', icon: Users, permission: 'manage_settings' },
  { id: 'system_setup', label: 'Settings / System Setup', icon: Settings2, permission: 'manage_system_setup' },
  { id: 'roadmap', label: 'Work progress', icon: ClipboardList, permission: 'manage_clients' },
];
const titles = { ...Object.fromEntries([...workspaceNav, ...managementNav].map(item => [item.id, item.label])), profile: 'My profile' };
const session = ref(null);
const canViewDashboardFinances = computed(() => session.value?.user?.role === 'admin');
const staffDocumentDatesLocked = computed(() => ['manager', 'staff'].includes(session.value?.user?.role) && !session.value?.operational_setup?.store?.allow_staff_custom_dates);
const language = ref(localStorage.getItem('uddog-language') || 'en');
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const installPrompt = ref(null);
const isInstalledApp = ref(window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true);
const isIosBrowser = /iPad|iPhone|iPod/.test(window.navigator.userAgent);
const canShowInstall = computed(() => !isInstalledApp.value && (Boolean(installPrompt.value) || isIosBrowser));
function captureInstallPrompt(event) {
  event.preventDefault();
  installPrompt.value = event;
}
function handleAppInstalled() {
  isInstalledApp.value = true;
  installPrompt.value = null;
}
async function installApp() {
  if (!installPrompt.value) {
    notice.value = language.value === 'bn' ? 'ইনস্টল করতে ব্রাউজারের Share মেনু থেকে Add to Home Screen বেছে নিন।' : 'To install, choose Add to Home Screen from your browser’s Share menu.';
    return;
  }
  const prompt = installPrompt.value;
  installPrompt.value = null;
  await prompt.prompt();
  const choice = await prompt.userChoice;
  if (choice.outcome !== 'accepted') notice.value = language.value === 'bn' ? 'অ্যাপ ইনস্টল বাতিল করা হয়েছে।' : 'App installation was cancelled.';
}
const initialPage = location.hash.slice(1) || location.pathname.split('/').filter(Boolean)[0];
const page = ref(titles[initialPage] ? initialPage : 'dashboard');
if (location.hash && titles[initialPage]) history.replaceState(null, '', `/${initialPage}${location.search}`);
const reportViewParams = new URLSearchParams(location.search);
const isReportView = computed(() => page.value === 'reports' && new URLSearchParams(location.search).get('report_view') === '1');
const mobileOpen = ref(false);
const modal = ref('');
const systemUpdateStatus = ref(null);
const systemUpdateConfirmed = ref(false);
const documentCreateContext = ref('');
const documentLineToFill = ref(0);
const editingId = ref(null);
const detailId = ref(null);
const search = ref('');
const reportSearch = ref('');
const directoryDueOnly = ref(false);
const overview = ref({ products: 0, stock_value: 0, low_stock: 0, sales_total: 0, purchase_total: 0, recent_documents: [], low_stock_products: [] });
const cashAction = ref('');
const cashWithdrawalAmount = ref('');
const cashNotes = ref('');
const products = ref([]);
const categories = ref([]);
const branches = ref([]);
const activeBranchId = ref(isReportView.value ? reportViewParams.get('branch_id') || '' : '');
const contacts = ref([]);
const customers = ref([]);
const suppliers = ref([]);
const documents = ref([]);
const movements = ref([]);
const expenses = ref([]);
const expenseToday = new Date().toLocaleDateString('en-CA');
const expenseFilters = ref({ date_from: `${expenseToday.slice(0, 7)}-01`, date_to: expenseToday, category: '', payment_method: '', search: '' });
const expenseSummary = ref({ count: 0, total: 0 });
const expenseCategories = ref([]);
const expensePagination = ref({ page: 1, last_page: 1 });
const expensePage = ref(1);
const goldRates = ref([]);
const goldJobs = ref([]);
const goldSummary = ref({ inventory: [], summary: {}, rates: [], exchanges: [], jobs: [] });
const goldRateForm = ref({ metal: 'gold', purity: '22K', rate_per_gram: '', effective_on: new Date().toLocaleDateString('en-CA') });
const goldJobForm = ref({ job_type: 'manufacturing', contact_id: '', product_id: '', gold_purity: '', input_weight: '', output_weight: '', wastage_weight: '', labor_amount: '', due_date: '', notes: '' });
const expenseForm = ref({ branch_id: '', category: 'Rent', description: '', amount: '', expense_date: new Date().toLocaleDateString('en-CA'), payment_method: 'cash', notes: '' });
const newExpenseCategory = ref('');
const purchaseBeingPaid = ref(null);
const purchasePaymentForm = ref({ amount: '', payment_method: 'cash', payment_date: new Date().toLocaleDateString('en-CA'), purchase_installment_id: '', notes: '' });
const saleBeingPaid = ref(null);
const salePaymentForm = ref({ amount: '', payment_method: 'cash', payment_date: new Date().toLocaleDateString('en-CA'), sale_installment_id: '', notes: '' });
const reportKinds = [
  { id: 'sales', label: 'Sales', abilities: ['sales', 'resales', 'sales_returns'], description: 'Sales, returns, collections and customer balances.' },
  { id: 'purchases', label: 'Purchases', abilities: ['purchases', 'purchase_returns'], description: 'Purchases and supplier returns for the selected period.' },
  { id: 'profit_loss', label: 'Profit & loss', abilities: ['expenses'], description: 'Gross profit less recorded operating expenses for the selected period.' },
  { id: 'product_ledger', label: 'Product wise Profit-Loss', abilities: ['sales', 'resales'], description: 'Product-wise sales quantities, costs and profit for the selected period.' },
  { id: 'product_lifecycle_ledger', label: 'Product Ledger Report', abilities: ['purchases', 'sales', 'resales'], description: 'Trace purchase lots, sales, returns, FIFO costs and running stock for one product.' },
  { id: 'general_ledger', label: 'General ledger', abilities: ['sales', 'resales', 'sales_returns', 'purchases', 'purchase_returns', 'expenses'], description: 'Account-wise debits, credits and running balances derived from recorded transactions.' },
  { id: 'cash_movement', label: 'Cash Movement Report', abilities: ['sales', 'resales', 'sales_returns', 'purchases', 'purchase_returns', 'expenses'], description: 'Physical cash collections, payments, staff handovers, owner withdrawals and running store balance.' },
  { id: 'employee_sales', label: 'Employee-wise sales', abilities: ['sales', 'resales'], description: 'Sales totals and collections by the employee who created each sale.' },
  { id: 'stock', label: 'Stock', abilities: ['inventory', 'products'], description: 'Current branch stock, reorder levels and inventory values.' },
  { id: 'adjustments', label: 'Stock adjustments', abilities: ['stock_adjustments'], description: 'Manual adjustments and stock check variances.' },
  { id: 'barcode_products', label: 'Barcode-wise products', abilities: ['products'], description: 'Search products by barcode and review current quantity and price.' },
  { id: 'barcode_sales', label: 'Barcode-wise sales', abilities: ['sales', 'resales'], description: 'Sold product lines matched by barcode or product name.' },
  { id: 'categories', label: 'Categories', abilities: ['categories', 'products'], description: 'Product counts, current stock and sales grouped by category.' },
  { id: 'customers', label: 'Customers', abilities: ['contacts'], description: 'Customer sales, collections, balances and credit limits.' },
  { id: 'customer_ledger', label: 'Customer ledger', abilities: ['contacts'], description: 'Customer transaction debit and credit history.' },
  { id: 'customer_due', label: 'Customer due', abilities: ['sales', 'resales'], description: 'Unpaid and partially paid customer invoices.' },
  { id: 'suppliers', label: 'Suppliers', abilities: ['contacts'], description: 'Supplier purchases, returns, payments and payable balances.' },
  { id: 'supplier_ledger', label: 'Supplier ledger', abilities: ['contacts'], description: 'Supplier purchase, return and payment history.' },
  { id: 'supplier_due', label: 'Supplier due', abilities: ['purchases', 'purchase_returns'], description: 'Unpaid and partially paid supplier invoices.' },
  { id: 'supplier_tt', label: 'TT Report', abilities: ['supplier_advances'], description: 'Standalone register of advances, coded product deliveries and balances.' },
];
const reportVisuals = {
  sales: { icon: ReceiptText, tone: 'violet' },
  purchases: { icon: ShoppingBag, tone: 'orange' },
  profit_loss: { icon: ChartNoAxesCombined, tone: 'green' },
  product_ledger: { icon: Package, tone: 'violet' },
  product_lifecycle_ledger: { icon: ClipboardList, tone: 'blue' },
  general_ledger: { icon: ClipboardList, tone: 'slate' },
  cash_movement: { icon: Banknote, tone: 'green' },
  employee_sales: { icon: Users, tone: 'blue' },
  stock: { icon: Boxes, tone: 'green' },
  adjustments: { icon: ClipboardCheck, tone: 'slate' },
  barcode_products: { icon: Barcode, tone: 'blue' },
  barcode_sales: { icon: Barcode, tone: 'violet' },
  categories: { icon: Tags, tone: 'orange' },
  customers: { icon: Users, tone: 'blue' },
  customer_ledger: { icon: ClipboardList, tone: 'slate' },
  customer_due: { icon: Banknote, tone: 'orange' },
  suppliers: { icon: ShoppingBag, tone: 'orange' },
  supplier_ledger: { icon: ClipboardList, tone: 'slate' },
  supplier_due: { icon: Banknote, tone: 'orange' },
  supplier_tt: { icon: Banknote, tone: 'green' },
};
const reportKind = ref(isReportView.value && reportKinds.some(report => report.id === reportViewParams.get('report')) ? reportViewParams.get('report') : 'sales');
const reportDetailOpen = ref(false);
const reportScopeBranchId = ref(isReportView.value && ['profit_loss', 'product_ledger', 'product_lifecycle_ledger'].includes(reportKind.value) ? reportViewParams.get('branch_id') || 'all' : 'all');
const reportBranches = computed(() => branches.value.filter(branch => branch.active));
const reportBranchId = computed(() => ['profit_loss', 'product_ledger', 'product_lifecycle_ledger'].includes(reportKind.value) && reportBranches.value.length > 1 ? reportScopeBranchId.value : activeBranchId.value);
const reportBranchName = computed(() => ['profit_loss', 'product_ledger', 'product_lifecycle_ledger'].includes(reportKind.value) && reportBranchId.value === 'all' ? 'All permitted branches' : branches.value.find(branch => branch.id === Number(reportBranchId.value))?.name || reportResult.value.branch_name || 'Current branch');
const reportToday = new Date().toLocaleDateString('en-CA');
const reportFilters = ref({
  date_from: isReportView.value ? reportViewParams.get('date_from') || '' : `${reportToday.slice(0, 7)}-01`,
  date_to: isReportView.value ? reportViewParams.get('date_to') || '' : reportToday,
  search: isReportView.value ? reportViewParams.get('search') || '' : '',
  product_id: isReportView.value ? reportViewParams.get('product_id') || '' : '',
  contact_id: isReportView.value ? reportViewParams.get('contact_id') || '' : '',
  account: isReportView.value ? reportViewParams.get('account') || '' : '',
});
const reportResult = ref({ columns: [], rows: [], summary: {} });
const reportCanExport = computed(() => reportKind.value === 'profit_loss' ? Boolean(reportResult.value.income?.length || reportResult.value.expenses?.length) : Boolean(reportResult.value.rows.length));
const reportLoading = ref(false);
const reportSaveBusy = ref(false);
const reportGeneratedAt = ref(new Date());
const reportGeneratedLabel = computed(() => `${translate('Generated by Uddog-Soft', language.value)} · ${reportGeneratedAt.value.toLocaleString(localeCode(), { dateStyle: 'medium', timeStyle: 'short' })}`);
const reportExportContext = ref({ kind: reportKind.value, branchId: activeBranchId.value, filters: { ...reportFilters.value } });
const reportPeriod = computed(() => {
  const { date_from: from, date_to: to } = reportExportContext.value.filters;
  if (from && to) return `${date(from)}${language.value === 'bn' ? ' থেকে ' : ' to '}${date(to)}`;
  if (from) return `From ${date(from)}`;
  if (to) return `Through ${date(to)}`;
  return 'All dates';
});
const standardReportPeriod = computed(() => ['stock', 'barcode_products', 'customers', 'suppliers'].includes(reportKind.value)
  ? `${translate('As of', language.value)} ${date(reportToday)}`
  : reportPeriod.value);
const stockChecks = ref([]);
const stockTransfers = ref([]);
const warrantyResults = ref([]);
const warrantySearch = ref('');
const warrantyStatusFilter = ref('all');
const salesTargets = ref([]);
const salesTargetUsers = ref([]);
const salesTargetPeriod = ref(new Date().toISOString().slice(0, 7));
const clients = ref([]);
const selectedClient = ref(null);
const provisionTarget = ref(null);
const provisionConfirmation = ref('');
const selectedDocument = ref(null);
const ledgerExpense = ref(null);
const ledgerCashMovement = ref(null);
const clientStatusFilter = ref('all');
const accessCompanySearch = ref('');
const accessUserSearch = ref('');
const accessCompanyFilter = ref('all');
const users = ref([]);
const systemSetup = ref(null);
const systemSetupSection = ref('company');
const unitPickerOpen = ref(false);
const unitQuery = ref('');
const profileMenuOpen = ref(false);
const profileForm = ref({ name: '', mobile: '', current_password: '', password: '' });
const authForm = ref({ name: '', email: new URLSearchParams(location.search).get('email') || '', password: '', password_confirmation: '' });
const authPasswordVisible = ref(false);
let authPasswordTimer;
function hideAuthPassword() {
  clearTimeout(authPasswordTimer);
  authPasswordVisible.value = false;
}
function toggleAuthPassword() {
  if (authPasswordVisible.value) { hideAuthPassword(); return; }
  authPasswordVisible.value = true;
  authPasswordTimer = setTimeout(hideAuthPassword, 5000);
}
onUnmounted(hideAuthPassword);
const authMode = ref(location.pathname.startsWith('/password/reset/') ? 'reset' : 'login');
const authMessage = ref('');
const resetToken = ref(decodeURIComponent(location.pathname.split('/').filter(Boolean).at(-1) || ''));
const standardUnitOptions = [
  { value: 'pc', label: 'Pieces (pc)' },
  { value: 'hali', label: '4 pieces (1 hali)' },
  { value: '8 pcs', label: '8 pieces' },
  { value: 'dozen', label: 'Dozen (12 pieces)' },
  { value: 'items', label: 'Items' },
  { value: 'units', label: 'Units' },
  { value: 'packs', label: 'Packs' },
  { value: 'boxes', label: 'Boxes' },
  { value: 'cartons', label: 'Cartons' },
  { value: 'sets', label: 'Sets' },
  { value: 'kg', label: 'Kilograms (kg)' },
  { value: 'g', label: 'Grams (g)' },
  { value: 'L', label: 'Liters (L)' },
  { value: 'oz', label: 'Ounces (oz)' },
  { value: 'ounce', label: 'Ounce' },
  { value: 'ভরি', label: 'ভরি' },
  { value: 'রতি', label: 'রতি' },
];
const unitOptions = computed(() => {
  const configured = session.value?.operational_setup?.store?.units || standardUnitOptions.map(unit => unit.value);
  const options = configured.map(value => standardUnitOptions.find(unit => unit.value === value) || { value, label: value });
  if (editingId.value && productForm.value.unit && !options.some(unit => unit.value === productForm.value.unit)) {
    options.push({ value: productForm.value.unit, label: productForm.value.unit });
  }
  return options;
});
const nonPieceTransactionUnits = ['kg', 'g', 'L', 'oz', 'ounce', 'ভরি', 'রতি'];
const defaultTransactionConversionUnits = ['pc', 'hali', '8 pcs', 'dozen', 'items', 'units', 'packs', 'boxes', 'cartons', 'sets'];
function transactionUnitOptions(productId) {
  const product = products.value.find(candidate => candidate.id === Number(productId));
  if (!product) return [];
  if (!['pc', 'pcs'].includes(product.unit) || product.is_gold_item) return [{ value: product.unit, label: product.unit }];
  const configured = Object.keys(session.value?.operational_setup?.store?.unit_conversions || {});
  const units = [product.unit, ...configured.filter(unit => !nonPieceTransactionUnits.includes(unit))];
  return [...new Set(units)].map(value => standardUnitOptions.find(unit => unit.value === value) || { value, label: value });
}
function transactionUnitFactor(item) {
  const product = products.value.find(candidate => candidate.id === Number(item.product_id));
  if (!product || !item.unit || item.unit === product.unit) return 1;
  return Number(session.value?.operational_setup?.store?.unit_conversions?.[item.unit] || 1);
}
function changeTransactionUnit(item) {
  const newFactor = transactionUnitFactor(item);
  item.unit_price = (Math.round(Number(item.unit_price || 0) * newFactor / Number(item.previous_conversion || 1) * 100) / 100).toFixed(2);
  item.previous_conversion = newFactor;
}
const filteredUnitChoices = computed(() => {
  const selected = systemSetup.value?.store?.units || [];
  const catalog = [...standardUnitOptions, ...selected.filter(value => !standardUnitOptions.some(unit => unit.value === value)).map(value => ({ value, label: value }))];
  const query = unitQuery.value.trim().toLocaleLowerCase();
  return catalog.filter(unit => !query || `${unit.label} ${unit.value}`.toLocaleLowerCase().includes(query));
});
const productForm = ref({ stock_source_product_id: '', stock_source_quantity: '1', sku: '', barcode: '', name: '', category_id: '', unit: 'pc', cost_price: '', sale_price: '', reorder_level: '0', warranty_months: '', active: true, is_gold_item: false, gold_purity: '22K', gold_gross_weight: '', gold_stone_weight: '0', gold_other_weight: '0', stone_value: '0', making_charge_mode: 'per_gram', making_charge: '0', wastage_percent: '0', tag_number: '', hallmark_code: '' });
const categoryForm = ref({ name: '', description: '', active: true });
const branchForm = ref({ name: '', code: '', phone: '', address: '', is_default: false, active: true });
const contactForm = ref({ name: '', company: '', type: 'customer', phone: '', email: '', address: '', area: '', category: '', credit_limit: '0', opening_due: '0', opening_payable: '0', active: true });
const adjustmentForm = ref({ product_id: '', quantity_change: '', notes: '' });
const subscriptionPlans = ['Starter', 'Standard', 'Premium'];
const clientForm = ref({ name: '', subdomain: '', plan_name: 'Standard', subscription_status: 'trial', subscription_ends_at: '', gold_store: false, shared_stock_sales: false, notes: '', owner_name: '', owner_email: '', owner_mobile: '', owner_password: '', owner_active: true, owner_exists: false });
const userForm = ref({ name: '', email: '', mobile: '', password: '', role: 'manager', organization_id: '', active: true, permissions: [], report_permissions: [], branch_ids: [] });
const salesTargetForm = ref({ branch_id: '', user_id: '', period: new Date().toISOString().slice(0, 7), target_amount: '', notes: '' });
const permissionOptions = [
  { value: 'dashboard', label: 'Dashboard overview' },
  { value: 'products', label: 'Products' },
  { value: 'categories', label: 'Product categories' },
  { value: 'branches', label: 'Branches' },
  { value: 'contacts', label: 'Customers and suppliers' },
  { value: 'purchases', label: 'Purchases' },
  { value: 'supplier_advances', label: 'Supplier Advances' },
  { value: 'sales', label: 'Sales' },
  { value: 'pos_sale', label: 'POS Sale' },
  { value: 'sales_returns', label: 'Sales returns' },
  { value: 'resales', label: 'Resales' },
  { value: 'purchase_returns', label: 'Purchase returns' },
  { value: 'inventory', label: 'Inventory and stock history' },
  { value: 'stock_adjustments', label: 'Manual stock adjustments' },
  { value: 'product_damages', label: 'Damaged products' },
  { value: 'stock_checks', label: 'Stock checks' },
  { value: 'stock_transfers', label: 'Stock transfers' },
  { value: 'warranty_search', label: 'Warranty search' },
  { value: 'sales_targets', label: 'Sales targets' },
  { value: 'expenses', label: 'Expenses' },
];
const roadmapItems = [
  { title: 'Sales Returns', phase: 'Phase 1', priority: 'High', status: 'Completed', description: 'Return sold items, restore inventory and record refund values against the original sale.' },
  { title: 'POS Sale', phase: 'Phase 1', priority: 'High', status: 'Completed', description: 'Fast checkout screen with product search, cart controls, payment and receipt printing.' },
  { title: 'Categories', phase: 'Phase 1', priority: 'High', status: 'Completed', description: 'Reusable product categories with centralized management and filtering.' },
  { title: 'Stock Check', phase: 'Phase 2', priority: 'Medium', status: 'Completed', description: 'Physical stock-count sessions with expected, counted and variance quantities.' },
  { title: 'Stock Transfer', phase: 'Phase 2', priority: 'Medium', status: 'Completed', description: 'Move stock between branches or locations with a complete movement history.' },
  { title: 'Warranty Search', phase: 'Phase 3', priority: 'Medium', status: 'Completed', description: 'Find sold products and warranty eligibility using invoice, barcode, product, customer or phone.' },
  { title: 'Sales Targets', phase: 'Phase 3', priority: 'Low', status: 'Completed', description: 'Set monthly branch or employee goals and compare actual net sales with targets.' },
];
const today = () => new Date().toLocaleDateString('en-CA');
const documentForm = ref({ type: 'purchase', contact_id: '', purchase_id: '', sale_id: '', document_date: today(), discount: '0', tax: '0', payment_type: 'full', payment_method: 'cash', down_payment: '0', interest_rate: '0', installment_count: 3, installment_frequency: 'monthly', first_installment_date: '', gold_exchange_weight: '0', gold_exchange_purity: '22K', notes: '', items: [{ product_id: '', quantity: 1, unit_price: '0.00' }] });
const posSearch = ref('');
const barcodeScan = ref('');
const posForm = ref({ contact_id: '', document_date: today(), discount: '0', tax: '0', payment_type: 'full', payment_method: 'cash', down_payment: '0', interest_rate: '0', installment_count: 3, installment_frequency: 'monthly', installment_start_date: today(), gold_exchange_weight: '0', gold_exchange_purity: '22K', amount_paid: '0', notes: '', items: [] });
const barcodeProduct = ref(null);
const barcodeQuantityInput = ref('1');
const selectedStockCheck = ref(null);
const stockCheckForm = ref({ check_date: today(), notes: '', items: [] });
const stockTransferForm = ref({ to_branch_id: '', transfer_date: today(), notes: '', items: [{ product_id: '', quantity: 1 }] });

function localeCode() { return language.value === 'bn' ? 'bn-BD' : 'en-BD'; }
function money(value) { return (import.meta.env.VITE_CURRENCY_SYMBOL || '৳') + Number(value || 0).toLocaleString(localeCode(), { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function qty(value) { return Number(value || 0).toLocaleString(localeCode(), { maximumFractionDigits: 3 }); }
function date(value) { return value ? new Date(value.includes('T') ? value : `${value}T00:00:00`).toLocaleDateString(localeCode(), { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
function weekday(value) { return value ? new Date(`${value.slice(0, 10)}T00:00:00`).toLocaleDateString(localeCode(), { weekday: 'long' }) : '—'; }
function installmentDueDate(startDate, frequency, sequence) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(startDate || '')) return '';
  const [year, month, day] = startDate.split('-').map(Number);
  const start = new Date(Date.UTC(year, month - 1, day));
  if (Number.isNaN(start.getTime()) || start.toISOString().slice(0, 10) !== startDate) return '';
  if (frequency === 'monthly') {
    const target = new Date(Date.UTC(year, month - 1 + sequence, 1));
    const lastDay = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
    target.setUTCDate(Math.min(day, lastDay));
    return target.toISOString().slice(0, 10);
  }
  start.setUTCDate(start.getUTCDate() + sequence * (frequency === 'weekly' ? 7 : 15));
  return start.toISOString().slice(0, 10);
}
function frequencyLabel(value) { return ({ weekly: 'Weekly', half_monthly: 'Every 15 days', monthly: 'Monthly' })[value] || '—'; }
function warrantyLabel(status) { return ({ active: 'Active', expired: 'Expired', no_warranty: 'No warranty' })[status] || '—'; }
function salesTargetStatusLabel(status) { return ({ achieved: 'Achieved', on_track: 'On track', behind: 'Behind', upcoming: 'Upcoming', missed: 'Missed' })[status] || '—'; }
function time(value) { return value ? new Date(value).toLocaleTimeString(localeCode(), { hour: '2-digit', minute: '2-digit', hour12: true }) : '—'; }
function documentDateTime(document) { return `${date(document.document_date)} · ${time(document.created_at)}`; }
function historyPaymentLabel(document) {
  if (document.payment_type === 'installment') return 'Installment';
  if (Number(document.balance_due) > 0) return Number(document.amount_paid) > 0 ? 'Part paid' : 'Due';
  return 'Paid';
}
function label(type) { return translate(({ purchase: 'Purchase', sale: 'Sale', resale: 'Resale', purchase_return: 'Purchase return', sale_return: 'Sales return', adjustment: 'Adjustment', damage: 'Damage', stock_check: 'Stock check', transfer_out: 'Transfer out', transfer_in: 'Transfer in' })[type] || type, language.value); }
function initial(name) { return (name || 'U').trim().charAt(0).toUpperCase(); }
function productOption(product) { return product.sku ? `${product.name} · ${product.sku}` : product.name; }
function roleLabel(role) { return translate(({ superadmin: 'Superadmin', admin: 'Owner admin', manager: 'Manager', staff: 'Staff' })[role] || role, language.value); }
function statusLabel(status) { return ({ trial: 'Trial', active: 'Active', past_due: 'Past due', suspended: 'Suspended', cancelled: 'Cancelled' })[status] || status; }
const currentSubscription = computed(() => session.value?.subscription);
const subscriptionDisplayStatus = computed(() => {
  const subscription = currentSubscription.value;
  if (!subscription) return 'Not assigned';
  if (subscription.access_paused) return 'Paused by admin';
  if (!subscription.active && ['trial', 'active'].includes(subscription.status) && subscription.days_remaining < 0) return 'Expired';
  return statusLabel(subscription.status) || 'Not assigned';
});
function paymentLabel(method) { return translate(({ cash: 'Cash', card: 'Card', mobile_banking: 'Mobile banking', bank_transfer: 'Bank transfer', credit: 'Credit' })[method] || '—', language.value); }
function salePaymentTypeLabel(type) { return translate(({ full: 'Full payment', due: 'Due sale', installment: 'Installment sale' })[type] || '—', language.value); }
function saleStatusLabel(document) {
  if (document.payment_type === 'due') return salePaymentTypeLabel('due');
  if (document.payment_type === 'installment') return salePaymentTypeLabel('installment');
  return translate(Number(document.balance_due) > 0 ? 'Partial due' : 'Paid', language.value);
}
function saleBalanceSummary(document) { return `${translate('Paid', language.value)} ${money(document.amount_paid)} · ${translate('Due', language.value)} ${money(document.balance_due)}`; }
function checkVariance(stockCheck) { return stockCheck.items.reduce((total, item) => total + Number(item.variance || 0), 0); }
function clientValidityLabel(client) {
  if (!client.subscription_active && ['trial', 'active'].includes(client.subscription_status) && client.subscription_ends_at) return 'Expired';
  return statusLabel(client.subscription_status);
}
function clientAccessLabel(client) {
  if (client.access_paused) return 'Paused by admin';
  return client.subscription_active ? 'Available' : 'Subscription inactive';
}
function openCollectionSale(alert) {
  const sale = documents.value.find(document => document.id === alert.document.id);
  if (sale) openSalePayment(sale, alert.id);
  else {
    detailId.value = alert.document.id;
    navigate(alert.document.type === 'resale' ? 'resale' : 'sale');
  }
}
const overdueInstallments = computed(() => (overview.value.collection_alerts || []).filter(alert => alert.days_until_due < 0));
const upcomingInstallments = computed(() => (overview.value.collection_alerts || []).filter(alert => alert.days_until_due >= 0));

function hasAbility(...abilities) { return abilities.some(ability => session.value?.permissions?.abilities?.includes(ability)); }
const canDeleteCatalogRecords = computed(() => ['superadmin', 'admin'].includes(session.value?.user?.role));
const nav = computed(() => [
  ...(session.value?.permissions?.use_workspace ? workspaceNav.filter(item => item.id.startsWith('gold_') ? session.value?.gold_store && item.abilities.some(ability => hasAbility(ability)) : item.id === 'supplier_advances' ? hasAbility('supplier_advances') : item.id === 'reports' ? !!session.value?.permissions?.reports?.length : item.abilities.some(ability => hasAbility(ability))) : []),
  ...managementNav.filter(item => session.value?.permissions?.[item.permission] && (session.value?.permissions?.use_workspace || session.value?.permissions?.manage_clients)),
]);
const mobileTabs = computed(() => [
  { id: 'dashboard', label: 'Home', icon: LayoutDashboard },
  { id: 'sale', label: 'Sales', icon: ShoppingCart },
  { id: 'inventory', label: 'Stock', icon: Boxes },
  { id: 'reports', label: 'Reports', icon: ChartNoAxesCombined },
].filter(tab => nav.value.some(item => item.id === tab.id)));

async function api(path, options = {}) {
  const response = await fetch(`/api/${path}`, {
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
    ...options,
    body: options.body ? JSON.stringify(options.body) : undefined,
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    if (response.status === 401) { session.value = { user: null, setup_required: false }; }
    if (response.status === 403 && ['user_access_paused', 'user_inactive'].includes(data.code)) {
      session.value = { user: null, setup_required: false };
    }
    if (response.status === 403 && ['company_access_paused', 'subscription_inactive'].includes(data.code)) {
      session.value = await api('session');
    }
    throw new Error(data.errors
      ? Object.values(data.errors).flat().map(message => translate(message, language.value)).join(' ')
      : translate(data.message || 'Something went wrong.', language.value));
  }
  return data;
}

async function checkSystemUpdates() {
  if (session.value?.user?.role !== 'superadmin' || session.value?.impersonation) return;
  systemUpdateStatus.value = await api('system-updates');
  if (systemUpdateStatus.value.total > 0) modal.value = 'system_update';
}

async function applySystemUpdate() {
  error.value = ''; busy.value = true;
  try {
    systemUpdateStatus.value = await api('system-updates', { method: 'POST', body: { confirmed: systemUpdateConfirmed.value } });
    if (systemUpdateStatus.value.total > 0) throw new Error('Some migrations are still pending. Check the server log before trying again.');
    systemUpdateConfirmed.value = false;
    modal.value = '';
    await refresh();
    toast('System database update completed.');
  } catch (e) { error.value = e.message; }
  finally { busy.value = false; }
}

async function refresh() {
  const requests = [];
  if (session.value?.permissions?.use_workspace) {
    branches.value = await api('branches');
    const storedBranch = localStorage.getItem(`uddog-branch-${session.value.user.organization_id}`);
    const selectedBranch = branches.value.find(branch => branch.active && branch.id === Number(activeBranchId.value || storedBranch))
      || branches.value.find(branch => branch.active && branch.is_default)
      || branches.value.find(branch => branch.active);
    activeBranchId.value = selectedBranch?.id || '';
    if (selectedBranch) localStorage.setItem(`uddog-branch-${session.value.user.organization_id}`, String(selectedBranch.id));
    const branchQuery = selectedBranch ? `?branch_id=${selectedBranch.id}` : '';
    if (hasAbility('dashboard')) requests.push(api(`overview${branchQuery}`).then(data => { overview.value = data; }));
    if (hasAbility('products', 'purchases', 'sales', 'pos_sale', 'sales_returns', 'resales', 'purchase_returns', 'inventory', 'stock_adjustments', 'stock_transfers', 'product_damages')) requests.push(api(`products${branchQuery}`).then(data => { products.value = data; }));
    if (hasAbility('categories', 'products', 'purchases', 'pos_sale')) requests.push(api('categories').then(data => { categories.value = data; }));
    if (hasAbility('contacts', 'purchases', 'sales', 'pos_sale', 'sales_returns', 'resales', 'purchase_returns')) requests.push(api('contacts').then(data => { contacts.value = data; }));
    if (hasAbility('contacts')) {
      requests.push(api('customers').then(data => { customers.value = data; }));
      requests.push(api('suppliers').then(data => { suppliers.value = data; }));
    }
    if (hasAbility('purchases', 'sales', 'sales_returns', 'resales', 'purchase_returns')) requests.push(api(`documents${branchQuery}`).then(data => { documents.value = data; }));
    if (hasAbility('inventory', 'stock_adjustments')) requests.push(api(`movements${branchQuery}`).then(data => { movements.value = data; }));
    if (hasAbility('stock_checks')) requests.push(api(`stock-checks${branchQuery}`).then(data => { stockChecks.value = data; }));
    if (hasAbility('stock_transfers')) requests.push(api(`stock-transfers${branchQuery}`).then(data => { stockTransfers.value = data; }));
    if (hasAbility('warranty_search')) requests.push(api('warranties').then(data => { warrantyResults.value = data; }));
    if (hasAbility('sales_targets')) requests.push(refreshSalesTargets());
    if (hasAbility('expenses')) requests.push(loadExpenses());
    if (session.value?.gold_store && hasAbility('products', 'purchases', 'sales', 'pos_sale')) {
      requests.push(api(`gold/rates${branchQuery}`).then(data => { goldRates.value = data; }));
    }
    if (session.value?.gold_store && hasAbility('products', 'purchases', 'sales')) {
      requests.push(api(`gold/summary${branchQuery}`).then(data => { goldSummary.value = data; }));
    }
    if (session.value?.gold_store && hasAbility('contacts', 'purchases', 'sales')) {
      requests.push(api(`gold/jobs${branchQuery}`).then(data => { goldJobs.value = data; }));
    }
  }
  if (session.value?.permissions?.manage_clients) requests.push(api('clients').then(data => { clients.value = data; }));
  if (session.value?.permissions?.manage_users && (session.value?.permissions?.use_workspace || session.value?.permissions?.manage_clients)) requests.push(api('users').then(data => { users.value = data; }));
  if (session.value?.permissions?.use_workspace && session.value?.permissions?.manage_system_setup) requests.push(api('system-setup').then(data => { systemSetup.value = data; }));
  await Promise.all(requests);
}
async function initialize() {
  try {
    session.value = await api('session');
    if (session.value.account_disabled) error.value = 'Your user account has been paused. Contact your company administrator or platform support.';
    if (session.value.user) {
      if (page.value !== 'profile' && !nav.value.some(item => item.id === page.value)) {
        page.value = nav.value[0]?.id || 'dashboard';
        history.replaceState(null, '', `/${page.value}`);
      }
      await checkSystemUpdates();
      if (systemUpdateStatus.value?.total) return;
      await refresh();
      if (isReportView.value) await loadReport();
      if (page.value === 'profile') {
        const profile = await api('profile');
        profileForm.value = { name: profile.name, mobile: profile.mobile || '', current_password: '', password: '' };
      }
    }
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}
let localization;
async function applyLanguage() {
  localStorage.setItem('uddog-language', language.value);
  document.documentElement.lang = language.value === 'bn' ? 'bn' : 'en';
  document.title = 'Uddog | ব্যাবসা পরিচালনার সফটওয়্যার';
  await nextTick();
  localization?.apply();
}
onMounted(async () => {
  window.addEventListener('beforeinstallprompt', captureInstallPrompt);
  window.addEventListener('appinstalled', handleAppInstalled);
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});
  await initialize();
  localization = installLocalization(() => language.value);
  await applyLanguage();
});
onUnmounted(() => {
  window.removeEventListener('beforeinstallprompt', captureInstallPrompt);
  window.removeEventListener('appinstalled', handleAppInstalled);
});
watch(language, applyLanguage);
window.addEventListener('popstate', () => {
  const requested = location.pathname.split('/').filter(Boolean)[0] || 'dashboard';
  page.value = requested === 'profile' || nav.value.some(item => item.id === requested) ? requested : nav.value[0]?.id || 'dashboard';
  if (page.value !== 'reports') reportDetailOpen.value = false;
  mobileOpen.value = false;
});
function navigate(id) { page.value = id; if (location.pathname !== `/${id}` || location.search || location.hash) history.pushState(null, '', `/${id}`); mobileOpen.value = false; profileMenuOpen.value = false; search.value = ''; directoryDueOnly.value = false; reportDetailOpen.value = false; if (isReportView.value) loadReport(); }
function openDueDirectory(id) { navigate(id); directoryDueOnly.value = true; }
function selectReport(id) {
  reportKind.value = id;
  navigate('reports');
  reportDetailOpen.value = true;
  loadReport();
  nextTick(() => window.scrollTo(0, 0));
}
function canOpenReport(report) {
  return session.value?.permissions?.reports?.includes(report.id);
}
const availableReportKinds = computed(() => reportKinds.filter(canOpenReport));
const filteredReportKinds = computed(() => {
  const query = reportSearch.value.trim().toLocaleLowerCase();
  if (!query) return availableReportKinds.value;
  return availableReportKinds.value.filter(report => [report.label, report.description, translate(report.label, language.value), translate(report.description, language.value)]
    .some(value => value.toLocaleLowerCase().includes(query)));
});
function reportAllowedByPermissions(report, permissions) {
  if (report.id === 'supplier_tt') return permissions.includes('supplier_advances');
  if (report.id === 'profit_loss') return permissions.includes('expenses') && (permissions.includes('sales') || permissions.includes('resales'));
  if (report.id === 'product_lifecycle_ledger') return permissions.includes('purchases') && (permissions.includes('sales') || permissions.includes('resales'));
  if (['general_ledger', 'cash_movement'].includes(report.id)) return report.abilities.every(ability => permissions.includes(ability));
  return report.abilities.some(ability => permissions.includes(ability));
}
function userReportCount(user) {
  const permissions = user.permissions ?? (user.role === 'admin' ? permissionOptions.map(permission => permission.value) : []);
  const effectivePermissions = user.role === 'admin' && session.value?.operational_setup?.supplier_advances?.enabled ? [...permissions, 'supplier_advances'] : permissions;
  return reportKinds.filter(report => (report.id !== 'supplier_tt' || session.value?.operational_setup?.supplier_advances?.enabled) && (user.report_permissions === null || user.report_permissions?.includes(report.id)) && reportAllowedByPermissions(report, effectivePermissions)).length;
}
function canGrantReport(report) {
  if (report.id === 'supplier_tt') return (session.value?.operational_setup?.supplier_advances?.enabled || session.value?.user?.role === 'superadmin') && (userForm.value.role === 'admin' || userForm.value.permissions.includes('supplier_advances'));
  if (report.id === 'cash_movement' && !['admin', 'superadmin'].includes(userForm.value.role)) return false;
  return userForm.value.role === 'superadmin' || reportAllowedByPermissions(report, userForm.value.permissions);
}
const userAccessibleReports = computed(() => {
  if (userForm.value.role === 'superadmin') return reportKinds;
  return reportKinds.filter(report => userForm.value.report_permissions.includes(report.id) && canGrantReport(report));
});
watch(() => [...userForm.value.permissions], () => {
  if (modal.value === 'user' && userForm.value.role !== 'superadmin') {
    userForm.value.report_permissions = userForm.value.report_permissions.filter(id => {
      const report = reportKinds.find(item => item.id === id);
      return report && canGrantReport(report);
    });
  }
});
const activeReport = computed(() => reportKinds.find(report => report.id === reportKind.value) || reportKinds[0]);
const reportViewUrl = computed(() => {
  const url = new URL(window.location.href);
  url.search = '';
  url.searchParams.set('report_view', '1');
  url.searchParams.set('report', reportKind.value);
  url.searchParams.set('branch_id', String(reportBranchId.value));
  Object.entries(reportFilters.value).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) url.searchParams.set(key, String(value));
  });
  url.pathname = '/reports';
  url.hash = '';
  return url.toString();
});
async function loadReport() {
  if (!availableReportKinds.value.some(report => report.id === reportKind.value)) {
    reportKind.value = availableReportKinds.value[0]?.id || 'sales';
  }
  reportLoading.value = true;
  reportResult.value = { columns: [], rows: [], summary: {} };
  error.value = '';
  try {
    const exportContext = { kind: reportKind.value, branchId: reportBranchId.value, filters: { ...reportFilters.value } };
    const params = new URLSearchParams({ report: reportKind.value, branch_id: reportBranchId.value });
    Object.entries(reportFilters.value).forEach(([key, value]) => { if (value) params.set(key, value); });
    reportResult.value = await api(`reports?${params.toString()}`);
    reportExportContext.value = exportContext;
    reportGeneratedAt.value = new Date();
  } catch (e) { error.value = e.message; reportResult.value = { columns: [], rows: [], summary: {} }; }
  finally { reportLoading.value = false; }
}
function exportReportCsv() {
  const { kind, branchId, filters } = reportExportContext.value;
  const name = reportKinds.find(report => report.id === kind)?.label || activeReport.value.label;
  const period = ['stock', 'barcode_products', 'customers', 'suppliers'].includes(kind) ? `As of ${date(reportToday)}`
    : filters.date_from && filters.date_to
      ? filters.date_from === filters.date_to ? date(filters.date_from) : `${date(filters.date_from)} to ${date(filters.date_to)}`
      : filters.date_from ? `From ${date(filters.date_from)}` : filters.date_to ? `Through ${date(filters.date_to)}` : 'All dates';
  const title = `${name} Report (${period})`;
  const company = session.value?.user?.organization?.name || 'Company';
  const branch = kind === 'profit_loss' && branchId === 'all' ? 'All permitted branches' : branches.value.find(item => item.id === Number(branchId))?.name || 'Current branch';
  const exportedAt = new Date().toLocaleString(localeCode(), { dateStyle: 'medium', timeStyle: 'short' });
  const rows = [
    [title],
    ['Company / store', company],
    ['Branch', branch],
    ['Exported at', exportedAt],
    ...(reportResult.value.note ? [['Note', reportResult.value.note]] : []),
    ...(reportResult.value.notes || []).map(note => ['Note', note]),
    [],
    reportResult.value.columns,
    ...reportResult.value.rows.map(row => (kind === 'product_lifecycle_ledger'
      ? [row.at, row.type, row.reference, branches.value.find(branch => branch.id === row.branch_id)?.name || '', row.quantity, row.unit_price, row.price_change, row.price_change_percent, row.amount, row.cogs, row.profit, row.margin, row.stock_balance, row.stock_value, row.cumulative_sales, row.cumulative_cogs, row.cumulative_profit, row.allocations.map(lot => `${lot.reference}: qty ${lot.quantity}, cost ${lot.cost}, revenue ${lot.revenue ?? ''}, profit ${lot.profit ?? ''}, margin ${lot.margin ?? ''}%`).join('; ')]
      : kind === 'general_ledger'
      ? [row.date, row.customer_supplier, row.reference, row.details, row.debit, row.credit, row.balance, row.invoice]
      : ['customer_ledger', 'supplier_ledger'].includes(kind)
        ? [row.date, row.customer ?? row.supplier, row.reference, row.type, row.debit, row.credit, row.balance]
      : Object.values(row)).map((value, index) => {
      const column = reportResult.value.columns[index];
      if (column === 'Date' && value) return String(value).slice(0, 10);
      if (column === 'Recorded time' && value) return time(value);
      return value;
    })),
  ];
  const csv = rows.map(row => row.map(value => {
    const text = String(value ?? '');
    return `"${(/^[\s]*[=+@-]/.test(text) && !/^-?\d+(\.\d+)?$/.test(text) ? `'${text}` : text).replaceAll('"', '""')}"`;
  }).join(',')).join('\r\n');
  const url = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = `${name} Report (${period}).csv`;
  link.click();
  URL.revokeObjectURL(url);
}
function profitLossExportData() {
  const localized = value => translate(value, language.value);
  const summary = reportResult.value.summary;
  return {
    eyebrow: localized('FINANCIAL OVERVIEW'),
    company: session.value?.user?.organization?.name || 'Company / store',
    title: `${localized('Profit & loss')} · ${reportPeriod.value} · ${localized(reportBranchName.value)}`,
    incomeLabel: localized('Income'),
    expensesLabel: localized('Expenses'),
    serialLabel: localized('SL'),
    incomeHead: localized('Income Head'),
    expenseHead: localized('Expense Head'),
    amountLabel: localized('Amount'),
    income: reportResult.value.income.map(item => ({ ...item, account: localized(item.account) })),
    expenses: reportResult.value.expenses.map(item => ({ ...item, account: localized(item.account) })),
    totalIncomeLabel: localized('Total income'),
    totalExpensesLabel: localized('Total costs & expenses'),
    netSales: summary.net_sales,
    totalExpenses: summary.total_costs_and_expenses,
    netProfit: summary.net_profit,
    netLabel: localized(Number(summary.net_profit) < 0 ? 'Loss for this period' : 'Profit for this period'),
    note: localized('Cost of sales = Opening Inventory + Purchases - Closing Inventory'),
    preparedLabel: localized('Prepared by:'),
    verifiedLabel: localized('Verified by:'),
    approvedLabel: localized('Approved by:'),
    generatedLabel: reportGeneratedLabel.value,
    money,
  };
}
function profitLossFilename() {
  const { date_from: from, date_to: to } = reportExportContext.value.filters;
  return `profit-loss-${from || 'all'}-to-${to || 'all'}-${reportExportContext.value.branchId}`;
}
function productLedgerExportData() {
  const localized = value => translate(value, language.value);
  return {
    eyebrow: localized('PRODUCT PERFORMANCE'),
    company: session.value?.user?.organization?.name || localized('Company / store'),
    title: `${localized('Product wise Profit-Loss')} · ${reportPeriod.value} · ${localized(reportBranchName.value)}`,
    columns: ['SL', 'Product name', 'Quantity (Qty)', 'Total sales', 'Costing', 'Stock profit', 'Installment profit', 'Profit / loss for period'].map(localized),
    totalLabel: localized('Total'),
    rows: reportResult.value.rows,
    summary: reportResult.value.summary,
    note: localized('Sales returns reduce quantity, sales and cost. Sales exclude tax; stock profit includes the allocated invoice discount.'),
    preparedLabel: localized('Prepared by:'),
    verifiedLabel: localized('Verified by:'),
    approvedLabel: localized('Approved by:'),
    generatedLabel: reportGeneratedLabel.value,
    money,
    qty,
  };
}
function productLedgerFilename() {
  const { date_from: from, date_to: to } = reportExportContext.value.filters;
  return `product-wise-profit-loss-${from || 'all'}-to-${to || 'all'}-${reportExportContext.value.branchId}`;
}
function printReport() {
  if (reportKind.value !== 'product_lifecycle_ledger') return window.print();
  const closedLots = [...document.querySelectorAll('.lifecycle-lot-details:not([open])')];
  closedLots.forEach(lot => { lot.open = true; });
  window.addEventListener('afterprint', () => closedLots.forEach(lot => { lot.open = false; }), { once: true });
  window.print();
}
async function saveReportFile(format) {
  if (!isReportView.value || !['profit_loss', 'product_ledger'].includes(reportKind.value) || !reportCanExport.value || reportSaveBusy.value) return;
  reportSaveBusy.value = true;
  error.value = '';
  try {
    if (reportKind.value === 'profit_loss') {
      if (format === 'pdf') saveProfitLossPdf(profitLossExportData(), profitLossFilename());
      else await saveProfitLossImage(profitLossExportData(), profitLossFilename());
    } else if (format === 'pdf') saveProductLedgerPdf(productLedgerExportData(), productLedgerFilename());
    else await saveProductLedgerImage(productLedgerExportData(), productLedgerFilename());
  } catch (exception) { error.value = exception.message || 'Could not save this report.'; }
  finally { reportSaveBusy.value = false; }
}
function reportSummaryLabel(key) { return key.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase()); }
function reportSummaryValue(key, value) { return /sales|^purchases$|paid|payments|due|returns|refunds|expenses|transfers|withdrawals|profit|value|collected|debit|credit|balance|payable|advance|received/i.test(key) ? money(value) : qty(value); }
function reportCell(column, value) {
  if (value === null || value === undefined || value === '') return '—';
  if (/^date$/i.test(column)) return date(String(value).slice(0, 10));
  if (column === 'Recorded at') return value === '—' ? value : `${date(String(value).slice(0, 10))} · ${time(String(value).replace(' ', 'T'))}`;
  if (column === 'Recorded time') return time(value);
  if (/count|units sold|^products$|^invoices$|^entries$|^total purchases$|^total sales$|^actual stock$|^damaged stock$|^good stock$/i.test(column)) return qty(value);
  if (/total|paid|due|price|value|sales|purchases|returns|returned|payable|collected|debit|credit|balance|cash in|cash out|transfer|advance sent|products received|^rate$/i.test(column) && !/count/i.test(column)) return money(value);
  if (/quantity|stock|change|reorder|units|on hand/i.test(column) && !/status/i.test(column)) return qty(value);
  return value;
}
async function switchBranch() {
  localStorage.setItem(`uddog-branch-${session.value.user.organization_id}`, String(activeBranchId.value));
  expensePage.value = 1;
  error.value = ''; busy.value = true;
  try { await refresh(); if (page.value === 'reports' && (reportDetailOpen.value || isReportView.value)) await loadReport(); } catch (e) { error.value = e.message; } finally { busy.value = false; }
}

async function authenticate() {
  error.value = ''; busy.value = true;
  try {
    const endpoint = session.value.setup_required ? 'setup' : 'login';
    await api(endpoint, { method: 'POST', body: authForm.value });
    hideAuthPassword();
    session.value = await api('session');
    page.value = nav.value[0]?.id || 'dashboard';
    history.replaceState(null, '', `/${page.value}`);
    await checkSystemUpdates();
    if (!systemUpdateStatus.value?.total) await refresh();
  } catch (e) { error.value = e.message; }
  finally { busy.value = false; }
}
async function requestPasswordReset() {
  error.value = ''; authMessage.value = ''; busy.value = true;
  try {
    const result = await api('password/forgot', { method: 'POST', body: { email: authForm.value.email } });
    authMessage.value = result.message;
  } catch (e) { error.value = e.message; }
  finally { busy.value = false; }
}
async function resetPassword() {
  error.value = ''; authMessage.value = ''; busy.value = true;
  try {
    const result = await api('password/reset', { method: 'POST', body: { token: resetToken.value, email: authForm.value.email, password: authForm.value.password, password_confirmation: authForm.value.password_confirmation } });
    authMessage.value = result.message;
    authForm.value.password = ''; authForm.value.password_confirmation = '';
    hideAuthPassword();
    authMode.value = 'login';
    history.replaceState(null, '', '/');
  } catch (e) { error.value = e.message; }
  finally { busy.value = false; }
}
function showAuthMode(mode) { hideAuthPassword(); authMode.value = mode; error.value = ''; authMessage.value = ''; }
async function logout() {
  await api('logout', { method: 'POST' });
  location.reload();
}
async function openProfile() {
  error.value = ''; busy.value = true;
  try {
    const user = await api('profile');
    profileForm.value = { name: user.name, mobile: user.mobile || '', current_password: '', password: '' };
    navigate('profile');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function saveProfile() {
  error.value = ''; busy.value = true;
  try {
    const user = await api('profile', { method: 'PUT', body: profileForm.value });
    session.value = { ...session.value, user };
    profileForm.value.current_password = ''; profileForm.value.password = '';
    toast('Profile updated successfully.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
function clearMessages() { error.value = ''; notice.value = ''; }
function toast(message) { notice.value = translate(message, language.value); setTimeout(() => { notice.value = ''; }, 4000); }

const filteredProducts = computed(() => products.value.filter(p => `${p.name} ${p.sku || ''} ${p.barcode || ''} ${p.category?.name || ''}`.toLowerCase().includes(search.value.toLowerCase())));
const filteredCategories = computed(() => categories.value.filter(category => `${category.name} ${category.description || ''}`.toLowerCase().includes(search.value.toLowerCase())));
const expenseCategoryOptions = computed(() => expenseCategories.value);
async function loadExpenses() {
  const params = new URLSearchParams({ branch_id: String(activeBranchId.value), page: String(expensePage.value) });
  for (const [key, value] of Object.entries(expenseFilters.value)) if (value) params.set(key, value);
  const result = await api(`expenses?${params}`);
  expenses.value = result.data;
  expenseSummary.value = result.summary;
  expenseCategories.value = result.categories;
  expensePagination.value = result.pagination;
}
async function applyExpenseFilters() { expensePage.value = 1; try { await loadExpenses(); } catch (e) { error.value = e.message; } }
async function changeExpensePage(nextPage) { expensePage.value = nextPage; try { await loadExpenses(); } catch (e) { error.value = e.message; } }
const filteredContacts = computed(() => contacts.value.filter(c => `${c.name} ${c.phone || ''} ${c.email || ''}`.toLowerCase().includes(search.value.toLowerCase())));
const customerDueTotal = computed(() => customers.value.reduce((total, contact) => total + Math.max(0, Number(contact.balance_total) || 0), 0));
const supplierDueTotal = computed(() => suppliers.value.reduce((total, contact) => total + Math.max(0, Number(contact.balance_total) || 0), 0));
const directoryContacts = computed(() => (page.value === 'customers' ? customers.value : suppliers.value).filter(contact => (!directoryDueOnly.value || Number(contact.balance_total) > 0) && `${contact.name} ${contact.company || ''} ${contact.phone || ''} ${contact.email || ''} ${contact.address || ''} ${contact.area || ''} ${contact.category || ''}`.toLowerCase().includes(search.value.toLowerCase())));
const directoryTotals = computed(() => directoryContacts.value.reduce((totals, contact) => ({ amount: totals.amount + Number(contact.sales_total || contact.purchases_total || 0), paid: totals.paid + Number(contact.paid_total || 0), balance: totals.balance + Number(contact.balance_total || 0) }), { amount: 0, paid: 0, balance: 0 }));
const filteredDocuments = computed(() => documents.value.filter(d => d.type === page.value && `${d.number} ${d.contact?.name || ''}`.toLowerCase().includes(search.value.toLowerCase())));
function customerDueDocuments(contactId) { return documents.value.filter(document => ['sale', 'resale'].includes(document.type) && Number(document.contact_id) === Number(contactId) && Number(document.balance_due) > 0).sort((a, b) => Number(b.balance_due) - Number(a.balance_due)); }
const customerDueContacts = computed(() => customers.value
  .map(customer => ({ ...customer, dueDocuments: customerDueDocuments(customer.id) }))
  .filter(customer => customer.dueDocuments.length)
  .map(customer => ({ ...customer, branch_due: customer.dueDocuments.reduce((total, document) => total + Number(document.balance_due), 0) }))
  .sort((a, b) => b.branch_due - a.branch_due));
const supplierDueDocuments = computed(() => documents.value.filter(document => document.type === 'purchase' && Number(document.balance_due) > 0));
const filteredClients = computed(() => clients.value.filter(client => {
  const matchesSearch = `${client.name} ${client.plan_name || ''} ${client.subscription_status} ${client.admins?.map(admin => `${admin.name} ${admin.email || ''} ${admin.mobile || ''}`).join(' ') || ''}`.toLowerCase().includes(search.value.toLowerCase());
  if (!matchesSearch || clientStatusFilter.value === 'all') return matchesSearch;
  if (clientStatusFilter.value === 'active') return client.subscription_active;
  if (clientStatusFilter.value === 'expired') return clientValidityLabel(client) === 'Expired';
  if (clientStatusFilter.value === 'expiring') {
    if (!client.subscription_active || !client.subscription_ends_at) return false;
    const end = new Date(`${client.subscription_ends_at.slice(0, 10)}T23:59:59`);
    const limit = new Date(); limit.setDate(limit.getDate() + 30);
    return end >= new Date() && end <= limit;
  }
  return client.subscription_status === clientStatusFilter.value;
}));
const filteredUsers = computed(() => users.value.filter(user => `${user.name} ${user.email || ''} ${user.mobile || ''} ${user.role} ${user.organization?.name || ''}`.toLowerCase().includes(search.value.toLowerCase())));
const accessClients = computed(() => clients.value.filter(client => `${client.name} ${client.plan_name || ''}`.toLowerCase().includes(accessCompanySearch.value.toLowerCase())));
const accessUsers = computed(() => users.value.filter(user => user.role !== 'superadmin' && user.organization_id && (accessCompanyFilter.value === 'all' || String(user.organization_id) === String(accessCompanyFilter.value)) && `${user.name} ${user.email} ${user.role} ${user.organization?.name || ''}`.toLowerCase().includes(accessUserSearch.value.toLowerCase())));
const clientStats = computed(() => {
  const now = new Date();
  const inThirtyDays = new Date();
  inThirtyDays.setDate(inThirtyDays.getDate() + 30);
  return {
    total: clients.value.length,
    active: clients.value.filter(client => client.subscription_active).length,
    expiring: clients.value.filter(client => {
      if (!client.subscription_active || !client.subscription_ends_at) return false;
      const end = new Date(`${client.subscription_ends_at.slice(0, 10)}T23:59:59`);
      return end >= now && end <= inThirtyDays;
    }).length,
    attention: clients.value.filter(client => !client.subscription_active).length,
  };
});
const upcomingClients = computed(() => [...clients.value]
  .filter(client => client.subscription_ends_at || !client.subscription_active)
  .sort((a, b) => {
    if (!a.subscription_active && b.subscription_active) return -1;
    if (a.subscription_active && !b.subscription_active) return 1;
    return String(a.subscription_ends_at || '9999-12-31').localeCompare(String(b.subscription_ends_at || '9999-12-31'));
  })
  .slice(0, 8));
const dashboardMonthlyTotals = computed(() => overview.value.monthly_totals || []);
const dashboardAnnualSales = computed(() => dashboardMonthlyTotals.value.reduce((total, month) => total + Number(month.sales || 0), 0));
const dashboardAnnualPurchases = computed(() => dashboardMonthlyTotals.value.reduce((total, month) => total + Number(month.purchases || 0), 0));
const dashboardActiveMonths = computed(() => dashboardMonthlyTotals.value.filter(month => Number(month.sales) || Number(month.purchases)));
const dashboardPositiveMax = computed(() => Math.max(0, ...dashboardMonthlyTotals.value.flatMap(month => [Number(month.sales) || 0, Number(month.purchases) || 0])));
const dashboardNegativeMax = computed(() => Math.max(0, ...dashboardMonthlyTotals.value.flatMap(month => [-(Number(month.sales) || 0), -(Number(month.purchases) || 0)])));
const dashboardChartRange = computed(() => Math.max(1, dashboardPositiveMax.value + dashboardNegativeMax.value));
const dashboardZeroBottom = computed(() => dashboardNegativeMax.value ? 5 + dashboardNegativeMax.value / dashboardChartRange.value * 90 : 0);
function monthlyBarStyle(value) {
  const amount = Number(value) || 0;
  const height = `${Math.abs(amount) / dashboardChartRange.value * 90}%`;
  return amount < 0 ? { height, top: `${100 - dashboardZeroBottom.value}%` } : { height, bottom: `${dashboardZeroBottom.value}%` };
}
const selectedPurchase = computed(() => documents.value.find(d => d.id === Number(documentForm.value.purchase_id)));
const selectedSale = computed(() => documents.value.find(d => d.id === Number(documentForm.value.sale_id)));
const purchaseOptions = computed(() => documents.value.filter(d => d.type === 'purchase'));
function returnedSaleQuantity(saleId, productId) {
  return documents.value
    .filter(document => document.type === 'sale_return' && document.sale_id === saleId)
    .flatMap(document => document.items)
    .filter(item => item.product_id === productId)
    .reduce((total, item) => total + Number(item.quantity), 0);
}
function returnableQuantity(productId) {
  const soldItem = selectedSale.value?.items.find(item => item.product_id === Number(productId));
  return Math.max(0, Number(soldItem?.quantity || 0) - returnedSaleQuantity(selectedSale.value?.id, Number(productId)));
}
const saleOptions = computed(() => documents.value.filter(document => ['sale', 'resale'].includes(document.type)
  && document.items.some(item => Number(item.quantity) > returnedSaleQuantity(document.id, item.product_id))));
const availableContacts = computed(() => contacts.value.filter(c => c.type === 'both' || c.type === (['purchase', 'purchase_return'].includes(documentForm.value.type) ? 'supplier' : 'customer')));
const relatedContactName = computed(() => ['purchase', 'purchase_return'].includes(documentForm.value.type) ? 'supplier' : 'customer');
const availableProducts = computed(() => {
  if (documentForm.value.type === 'purchase_return') return (selectedPurchase.value?.items || []).map(item => item.product);
  if (documentForm.value.type === 'sale_return') return (selectedSale.value?.items || []).filter(item => returnableQuantity(item.product_id) > 0).map(item => item.product);
  return products.value.filter(product => product.active && (!product.stock_source_product_id || (session.value?.shared_stock_sales && !['purchase', 'purchase_return'].includes(documentForm.value.type))));
});
const documentCanSave = computed(() => {
  const contactIsValid = availableContacts.value.some(contact => contact.id === Number(documentForm.value.contact_id));
  const itemsAreValid = documentForm.value.items.length > 0 && documentForm.value.items.every(item => availableProducts.value.some(product => product.id === Number(item.product_id)));
  const sourceIsValid = (documentForm.value.type !== 'purchase_return' || !!documentForm.value.purchase_id)
    && (documentForm.value.type !== 'sale_return' || !!documentForm.value.sale_id);
  return contactIsValid && itemsAreValid && sourceIsValid;
});
const roadmapCompleted = computed(() => roadmapItems.filter(item => item.status === 'Completed').length);
const salesTargetTotals = computed(() => salesTargets.value.reduce((totals, target) => ({ target: totals.target + Number(target.target_amount || 0), actual: totals.actual + Number(target.actual_amount || 0) }), { target: 0, actual: 0 }));
const purchaseStep = computed(() => !documentForm.value.contact_id ? 1 : !documentForm.value.items.every(item => item.product_id) ? 2 : 3);
const docSubtotal = computed(() => documentForm.value.items.reduce((sum, item) => sum + Number(item.quantity || 0) * Number(item.unit_price || 0), 0));
const docBaseTotal = computed(() => Math.max(0, docSubtotal.value - Number(documentForm.value.discount || 0) + Number(documentForm.value.tax || 0)));
const docInterestAmount = computed(() => documentForm.value.payment_type === 'installment' && ['sale', 'resale'].includes(documentForm.value.type)
  ? Math.round(Math.max(0, docBaseTotal.value - Number(documentForm.value.down_payment || 0) - goldExchangeAmount.value) * Number(documentForm.value.interest_rate || 0)) / 100
  : 0);
const docTotal = computed(() => docBaseTotal.value + docInterestAmount.value);
const goldExchangeRate = computed(() => Number(goldRates.value.find(item => item.metal === 'gold' && String(item.purity).toUpperCase() === String(documentForm.value.gold_exchange_purity).toUpperCase())?.rate_per_gram || 0));
const goldExchangeAmount = computed(() => Math.round(Number(documentForm.value.gold_exchange_weight || 0) * goldExchangeRate.value * 100) / 100);
const salePaymentInitial = computed(() => documentForm.value.payment_type === 'full' ? Math.max(0, docTotal.value) : documentForm.value.payment_type === 'installment' ? Number(documentForm.value.down_payment || 0) : 0);
const saleInstallmentBalance = computed(() => Math.max(0, docTotal.value - salePaymentInitial.value - (['sale', 'resale'].includes(documentForm.value.type) ? goldExchangeAmount.value : 0)));
const saleInstallmentAmount = computed(() => Number(documentForm.value.installment_count || 0) ? saleInstallmentBalance.value / Number(documentForm.value.installment_count) : 0);
const posProducts = computed(() => products.value.filter(product => product.active
  && (!product.stock_source_product_id || session.value?.shared_stock_sales)
  && Number(product.available_quantity ?? product.quantity_on_hand) > 0
  && `${product.name} ${product.sku || ''} ${product.category?.name || ''}`.toLowerCase().includes(posSearch.value.toLowerCase())));
const posCustomers = computed(() => contacts.value.filter(contact => ['customer', 'both'].includes(contact.type)));
const soleDefaultCustomerId = computed(() => {
  const [customer] = posCustomers.value;
  return posCustomers.value.length === 1 && customer.active && customer.is_default ? customer.id : '';
});
const soleSupplierId = computed(() => {
  const activeSuppliers = contacts.value.filter(contact => contact.active && ['supplier', 'both'].includes(contact.type));
  return activeSuppliers.length === 1 ? activeSuppliers[0].id : '';
});
watch([modal, soleSupplierId], ([currentModal, supplierId]) => {
  if (currentModal === 'document' && documentForm.value.type === 'purchase' && !documentForm.value.contact_id && supplierId) documentForm.value.contact_id = supplierId;
});
watch([page, soleDefaultCustomerId], ([currentPage, customerId]) => {
  if (currentPage === 'pos' && customerId && !posForm.value.contact_id) posForm.value.contact_id = customerId;
});
const posSubtotal = computed(() => posForm.value.items.reduce((sum, item) => sum + Number(item.quantity || 0) * Number(item.unit_price || 0), 0));
const posBaseTotal = computed(() => Math.max(0, posSubtotal.value - Number(posForm.value.discount || 0) + Number(posForm.value.tax || 0)));
const posInterestAmount = computed(() => posForm.value.payment_type === 'installment'
  ? Math.round(Math.max(0, posBaseTotal.value - Number(posForm.value.down_payment || 0) - posGoldExchangeAmount.value) * Number(posForm.value.interest_rate || 0)) / 100
  : 0);
const posTotal = computed(() => posBaseTotal.value + posInterestAmount.value);
const posGoldExchangeRate = computed(() => Number(goldRates.value.find(item => item.metal === 'gold' && String(item.purity).toUpperCase() === String(posForm.value.gold_exchange_purity).toUpperCase())?.rate_per_gram || 0));
const posGoldExchangeAmount = computed(() => Math.round(Number(posForm.value.gold_exchange_weight || 0) * posGoldExchangeRate.value * 100) / 100);
const posInitialPayment = computed(() => posForm.value.payment_type === 'full' ? posTotal.value : posForm.value.payment_type === 'installment' ? Number(posForm.value.down_payment || 0) : 0);
const posCashPayment = computed(() => posForm.value.payment_type === 'installment'
  ? Math.max(0, Number(posForm.value.down_payment || 0))
  : Math.max(0, posInitialPayment.value - posGoldExchangeAmount.value));
const posBalance = computed(() => Math.max(0, posTotal.value - posInitialPayment.value - posGoldExchangeAmount.value));
const posFinancedAmount = computed(() => Math.max(0, posBaseTotal.value - Number(posForm.value.down_payment || 0) - posGoldExchangeAmount.value));
const posInstallmentRows = computed(() => {
  if (posForm.value.payment_type !== 'installment' || !posForm.value.installment_start_date) return [];
  const count = Math.min(120, Math.max(0, Math.trunc(Number(posForm.value.installment_count) || 0)));
  if (!count) return [];
  const totalCents = Math.round(posBalance.value * 100);
  const interestCents = Math.round(posInterestAmount.value * 100);
  const baseAmount = Math.floor(totalCents / count);
  const amountRemainder = totalCents % count;
  const baseInterest = Math.floor(interestCents / count);
  const interestRemainder = interestCents % count;
  return Array.from({ length: count }, (_, index) => {
    const sequence = index + 1;
    const dueDate = installmentDueDate(posForm.value.installment_start_date, posForm.value.installment_frequency, sequence);
    const amountCents = baseAmount + (sequence === count ? amountRemainder : 0);
    const interestPart = baseInterest + (sequence === count ? interestRemainder : 0);
    return { sequence, due_date: dueDate, principal: (amountCents - interestPart) / 100, interest: interestPart / 100, amount: amountCents / 100 };
  });
});
const invoiceInstallmentRows = computed(() => {
  const installments = selectedDocument.value?.installments || [];
  const count = installments.length;
  if (!count) return [];
  const interestCents = Math.round(Number(selectedDocument.value.interest_amount || 0) * 100);
  const baseInterest = Math.floor(interestCents / count);
  const remainder = interestCents % count;
  return installments.map((installment, index) => {
    const interestPart = baseInterest + (index === count - 1 ? remainder : 0);
    return { ...installment, principal: (Math.round(Number(installment.amount) * 100) - interestPart) / 100, interest: interestPart / 100 };
  });
});
const barcodeMarkup = computed(() => ean13Svg(barcodeProduct.value?.barcode));
const barcodeLabelCount = computed(() => {
  const requested = Number(barcodeQuantityInput.value);
  return Number.isInteger(requested) && requested >= 1 && requested <= 1000 ? requested : 0;
});
const posSharedStockValid = computed(() => {
  const usedBySource = new Map();
  for (const item of posForm.value.items) {
    const product = products.value.find(candidate => candidate.id === Number(item.product_id));
    if (!product) return false;
    const sourceId = Number(product.stock_source_product_id || product.id);
    const consumed = Number(item.quantity) * transactionUnitFactor(item) * Number(product.stock_source_quantity || 1);
    usedBySource.set(sourceId, (usedBySource.get(sourceId) || 0) + consumed);
  }
  return [...usedBySource].every(([sourceId, consumed]) => {
    const source = products.value.find(product => product.id === sourceId);
    return source && consumed <= Number(source.quantity_on_hand) + 0.000001;
  });
});
const posCanCheckout = computed(() => posCustomers.value.some(contact => contact.id === Number(posForm.value.contact_id))
  && posForm.value.items.length > 0
  && posSharedStockValid.value
  && posForm.value.items.every(item => Number(item.quantity) > 0 && Number(item.quantity) * transactionUnitFactor(item) <= Number(item.stock))
  && Number(posForm.value.discount || 0) <= posSubtotal.value
  && posInitialPayment.value >= 0
  && (posForm.value.payment_type === 'installment'
    ? posInitialPayment.value < posBaseTotal.value - posGoldExchangeAmount.value
    : posInitialPayment.value <= posBaseTotal.value)
  && (posForm.value.payment_type !== 'installment' || (Number.isInteger(Number(posForm.value.installment_count)) && Number(posForm.value.installment_count) >= 1 && Number(posForm.value.installment_count) <= 120 && !!posForm.value.installment_frequency && !!posInstallmentRows.value[0]?.due_date && posForm.value.installment_start_date >= posForm.value.document_date)));
const selectableCategories = computed(() => categories.value.filter(category => category.active || category.id === Number(productForm.value.category_id)));
const stockCheckVariance = computed(() => stockCheckForm.value.items.reduce((total, item) => total + (item.counted_quantity === '' || item.counted_quantity === null ? 0 : Number(item.counted_quantity) - Number(item.expected_quantity)), 0));
const uncountedStockItems = computed(() => stockCheckForm.value.items.filter(item => item.counted_quantity === '' || item.counted_quantity === null).length);
const transferDestinations = computed(() => branches.value.filter(branch => branch.active && branch.id !== Number(activeBranchId.value)));
const stockTransferCanSave = computed(() => {
  const productIds = stockTransferForm.value.items.map(item => Number(item.product_id));
  return transferDestinations.value.some(branch => branch.id === Number(stockTransferForm.value.to_branch_id))
    && productIds.length > 0
    && new Set(productIds).size === productIds.length
    && stockTransferForm.value.items.every(item => {
      const product = products.value.find(candidate => candidate.id === Number(item.product_id));
      return product?.active && Number(item.quantity) > 0 && Number(item.quantity) <= Number(product.quantity_on_hand);
    });
});
const stockProducts = computed(() => products.value.filter(product => !product.stock_source_product_id));
const lowStock = p => Number(p.available_quantity ?? p.quantity_on_hand) <= Number(p.reorder_level);

function resetPos() {
  posForm.value = { contact_id: soleDefaultCustomerId.value, document_date: today(), discount: '0', tax: '0', payment_type: 'full', payment_method: session.value?.operational_setup?.sales?.default_payment_method || 'cash', down_payment: '0', interest_rate: '0', installment_count: 3, installment_frequency: 'monthly', installment_start_date: today(), gold_exchange_weight: '0', gold_exchange_purity: '22K', amount_paid: '0', notes: '', items: [] };
  posSearch.value = '';
}
function operationEnabled(type) {
  const setup = session.value?.operational_setup;
  if (!setup) return true;
  return ({ resale: setup.sales?.allow_resales, sale_return: setup.sales?.allow_sales_returns, purchase_return: setup.purchases?.allow_purchase_returns, adjustment: setup.store?.allow_stock_adjustments, stock_transfer: setup.store?.allow_stock_transfers })[type] !== false;
}
function ean13CheckDigit(base) {
  const digits = String(base).slice(0, 12).padStart(12, '0').split('').map(Number);
  const sum = digits.reduce((total, digit, index) => total + digit * (index % 2 === 0 ? 1 : 3), 0);
  return String((10 - (sum % 10)) % 10);
}
function generateBarcode() {
  let base = String(Date.now()).slice(-12).padStart(12, '0');
  let candidate = base + ean13CheckDigit(base);
  while (products.value.some(product => product.barcode === candidate)) {
    base = String((Number(base) + 1) % 1000000000000).padStart(12, '0');
    candidate = base + ean13CheckDigit(base);
  }
  productForm.value.barcode = candidate;
}
function ean13Svg(value) {
  if (!/^\d{13}$/.test(value || '')) return '';
  const left = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
  const middle = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
  const right = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];
  const parity = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];
  let bits = '101';
  for (let index = 1; index <= 6; index += 1) bits += parity[Number(value[0])][index - 1] === 'L' ? left[Number(value[index])] : middle[Number(value[index])];
  bits += '01010';
  for (let index = 7; index <= 12; index += 1) bits += right[Number(value[index])];
  bits += '101';
  const bars = [...bits].map((bit, index) => bit === '1' ? `<rect x="${20 + index * 2}" y="8" width="2" height="58"/>` : '').join('');
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 230 88" role="img" aria-label="EAN-13 barcode ${value}"><rect width="230" height="88" fill="white"/><g fill="black">${bars}</g><text x="115" y="82" text-anchor="middle" font-family="monospace" font-size="13" letter-spacing="3">${value}</text></svg>`;
}
function scanPosBarcode() {
  const code = barcodeScan.value.trim();
  if (!code) return;
  const product = products.value.find(item => item.active && (item.barcode === code || item.sku === code));
  barcodeScan.value = '';
  if (!product) {
    error.value = `No active product matches barcode ${code}.`;
    return;
  }
  if (Number(product.available_quantity ?? product.quantity_on_hand) <= 0) {
    error.value = `${product.name} is out of stock.`;
    return;
  }
  error.value = '';
  addPosProduct(product);
  toast(`${product.name} scanned into the cart.`);
}
function addPosProduct(product) {
  const existing = posForm.value.items.find(item => item.product_id === product.id);
  if (existing) {
    existing.quantity = Math.min(Math.floor(Number(existing.stock) / transactionUnitFactor(existing)), Number(existing.quantity) + 1);
    return;
  }
  posForm.value.items.push({ product_id: product.id, name: product.name, sku: product.sku, unit: product.unit, base_unit: product.unit, previous_conversion: 1, stock: product.available_quantity ?? product.quantity_on_hand, quantity: 1, unit_price: product.is_gold_item ? goldUnitPrice(product) : product.sale_price || '0.00' });
}
function changePosQuantity(item, change) {
  item.quantity = Math.max(0.001, Math.min(Number(item.stock) / transactionUnitFactor(item), Number(item.quantity || 0) + change));
}
function setPosPayment() {
  if (posForm.value.payment_type === 'full') {
    posForm.value.amount_paid = posTotal.value.toFixed(2);
    if (posForm.value.payment_method === 'credit') posForm.value.payment_method = 'cash';
  } else if (posForm.value.payment_type === 'due') {
    posForm.value.payment_method = 'credit';
    posForm.value.amount_paid = '0';
    posForm.value.down_payment = '0';
  } else {
    posForm.value.amount_paid = posForm.value.down_payment || '0';
    if (posForm.value.payment_method === 'credit') posForm.value.payment_method = 'cash';
  }
}
function setDocumentPayment() {
  if (documentForm.value.payment_type === 'due') {
    documentForm.value.payment_method = 'credit';
    documentForm.value.down_payment = '0';
  } else if (documentForm.value.payment_method === 'credit') {
    documentForm.value.payment_method = 'cash';
  }
}

function openProduct(product = null, context = '') {
  clearMessages(); editingId.value = product?.id || null;
  documentCreateContext.value = context;
  productForm.value = product ? { stock_source_product_id: product.stock_source_product_id || '', stock_source_quantity: product.stock_source_quantity || '1', sku: product.sku || '', barcode: product.barcode || '', name: product.name, category_id: product.category_id || '', unit: product.unit === 'pcs' ? 'pc' : product.unit, cost_price: product.cost_price, sale_price: product.sale_price, reorder_level: product.reorder_level, warranty_months: product.warranty_months ?? '', active: product.active, is_gold_item: !!product.is_gold_item, gold_purity: product.gold_purity || '22K', gold_gross_weight: product.gold_gross_weight || '', gold_stone_weight: product.gold_stone_weight || '0', gold_other_weight: product.gold_other_weight || '0', stone_value: product.stone_value || '0', making_charge_mode: product.making_charge_mode || 'per_gram', making_charge: product.making_charge || '0', wastage_percent: product.wastage_percent || '0', tag_number: product.tag_number || '', hallmark_code: product.hallmark_code || '' } : { stock_source_product_id: '', stock_source_quantity: '1', sku: '', barcode: '', name: '', category_id: '', unit: 'pc', cost_price: '', sale_price: '', reorder_level: String(session.value?.operational_setup?.store?.default_reorder_level ?? 0), warranty_months: '', active: true, is_gold_item: false, gold_purity: '22K', gold_gross_weight: '', gold_stone_weight: '0', gold_other_weight: '0', stone_value: '0', making_charge_mode: 'per_gram', making_charge: '0', wastage_percent: '0', tag_number: '', hallmark_code: '' };
  modal.value = 'product';
}
function openCategory(category = null, context = '') {
  clearMessages();
  editingId.value = category?.id || null;
  documentCreateContext.value = context;
  categoryForm.value = category ? { name: category.name, description: category.description || '', active: category.active } : { name: '', description: '', active: true };
  modal.value = 'category';
}
function openBranch(branch = null) {
  if (!branch) return;
  clearMessages();
  editingId.value = branch?.id || null;
  branchForm.value = branch ? { name: branch.name, code: branch.code, phone: branch.phone || '', address: branch.address || '', is_default: branch.is_default, active: branch.active } : { name: '', code: '', phone: '', address: '', is_default: false, active: true };
  modal.value = 'branch';
}
function openClientBranch() {
  if (session.value?.user?.role !== 'superadmin' || !selectedClient.value) return;
  clearMessages();
  editingId.value = null;
  branchForm.value = { name: '', code: '', phone: '', address: '', is_default: false, active: true };
  modal.value = 'client_branch';
}
function openBarcode(product) {
  clearMessages();
  if (!product.barcode) {
    openProduct(product);
    generateBarcode();
    toast('A barcode was generated. Save the product before printing it.');
    return;
  }
  barcodeProduct.value = product;
  barcodeQuantityInput.value = '1';
  modal.value = 'barcode';
}
function openContact(contact = null, context = '') {
  clearMessages(); editingId.value = contact?.id || null;
  documentCreateContext.value = context;
  const defaultType = context === 'contact' && documentForm.value.type === 'purchase' ? 'supplier' : page.value === 'suppliers' ? 'supplier' : 'customer';
  contactForm.value = contact ? { name: contact.name, company: contact.company || '', type: contact.type, phone: contact.phone || '', email: contact.email || '', address: contact.address || '', area: contact.area || '', category: contact.category || '', credit_limit: contact.credit_limit || '0', opening_due: '0', opening_payable: '0', active: contact.active } : { name: '', company: '', type: defaultType, phone: '', email: '', address: '', area: '', category: '', credit_limit: '0', opening_due: '0', opening_payable: '0', active: true };
  modal.value = 'contact';
}
function openDocument(type = page.value) {
  if (!operationEnabled(type)) { error.value = 'This workflow is disabled in System Setup.'; return; }
  clearMessages();
  documentCreateContext.value = '';
  documentForm.value = { type, contact_id: type === 'sale' ? soleDefaultCustomerId.value : type === 'purchase' ? soleSupplierId.value : '', purchase_id: '', sale_id: '', document_date: today(), discount: '0', tax: '0', payment_type: 'full', payment_method: ['sale_return', 'purchase_return'].includes(type) ? 'credit' : (session.value?.operational_setup?.sales?.default_payment_method || 'cash'), down_payment: '0', interest_rate: '0', installment_count: 3, installment_frequency: 'monthly', first_installment_date: '', gold_exchange_weight: '0', gold_exchange_purity: '22K', notes: '', items: [{ product_id: '', quantity: 1, unit_price: '0.00' }] };
  modal.value = 'document';
}
function openInvoice(document) {
  clearMessages();
  selectedDocument.value = document;
  modal.value = 'invoice';
}
function openLedgerSource(row) {
  if (row.source_type === 'cash_movement' && row.source_record) {
    ledgerCashMovement.value = { ...row.source_record, reference: row.reference };
    clearMessages();
    modal.value = 'ledger_cash_movement';
    return;
  }
  if (row.source_type === 'expense' && row.source_record) {
    ledgerExpense.value = { ...row.source_record, reference: row.reference };
    clearMessages();
    modal.value = 'ledger_expense';
    return;
  }
  const document = documents.value.find(item => Number(item.id) === Number(row.source_id));
  if (document) openInvoice(document);
  else error.value = 'The related invoice could not be found in this branch.';
}
async function openContactLedgerInvoice(row) {
  error.value = '';
  try {
    const ledger = reportKind.value === 'supplier_ledger' ? 'supplier-ledger' : 'customer-ledger';
    const invoice = await api(`reports/${ledger}/invoices/${row.document_id}?branch_id=${reportBranchId.value}`);
    openInvoice(invoice);
  } catch (exception) {
    error.value = exception.message;
  }
}
function printInvoice() { window.print(); }
async function printBarcode() {
  error.value = '';
  if (!barcodeLabelCount.value) {
    error.value = translate('Enter a whole number of labels between 1 and 1000.', language.value);
    return;
  }
  busy.value = true;
  try {
    const branchProducts = await api(`products?branch_id=${activeBranchId.value}`);
    const currentProduct = branchProducts.find(product => product.id === barcodeProduct.value?.id);
    if (!currentProduct) throw new Error('This product is no longer available in the selected branch.');
    if (!currentProduct.barcode) throw new Error('This product no longer has a barcode. Save a barcode before printing.');
    barcodeProduct.value = currentProduct;
    await nextTick();
    window.print();
  } catch (exception) {
    error.value = translate(exception.message, language.value);
  } finally {
    busy.value = false;
  }
}
function createRelatedContact() {
  openContact(null, 'contact');
}
function createPosCustomer() {
  openContact(null, 'pos_contact');
}
function createRelatedProduct(index) {
  if (documentForm.value.type === 'purchase' && !documentForm.value.contact_id) return;
  documentLineToFill.value = index;
  openProduct(null, 'product');
}
function closeModal() {
  if (modal.value === 'system_update') {
    modal.value = '';
    systemUpdateConfirmed.value = false;
    refresh().catch(e => { error.value = e.message; });
    return;
  }
  if (modal.value === 'client_branch') {
    modal.value = 'client_details';
    error.value = '';
    return;
  }
  if (documentCreateContext.value === 'product_category') {
    modal.value = 'product';
    documentCreateContext.value = '';
    error.value = '';
    return;
  }
  if (documentCreateContext.value === 'pos_contact') {
    modal.value = '';
    documentCreateContext.value = '';
    error.value = '';
    return;
  }
  if (documentCreateContext.value && modal.value !== 'document') {
    modal.value = 'document';
    documentCreateContext.value = '';
    error.value = '';
    return;
  }
  modal.value = '';
  documentCreateContext.value = '';
  error.value = '';
}
function openAdjustment(product = null) {
  if (!operationEnabled('adjustment')) { error.value = 'This workflow is disabled in System Setup.'; return; }
  clearMessages(); adjustmentForm.value = { product_id: product?.id || '', quantity_change: '', notes: '' }; modal.value = 'adjustment';
}
function openExpense(expense = null) {
  clearMessages(); editingId.value = expense?.id || null;
  newExpenseCategory.value = '';
  documentForm.value.type = 'expense';
  expenseForm.value = expense
    ? { branch_id: activeBranchId.value, category: expense.category, description: expense.description, amount: expense.amount, expense_date: expense.expense_date, payment_method: expense.payment_method, notes: expense.notes || '' }
    : { branch_id: activeBranchId.value, category: 'Rent', description: '', amount: '', expense_date: today(), payment_method: 'cash', notes: '' };
  modal.value = 'expense';
}
function openPurchasePayment(purchase) {
  const selectedPurchase = documents.value.find(document => document.id === purchase.id) || purchase;
  const installment = selectedPurchase.purchaseInstallments?.find(item => Number(item.balance_due) > 0);
  clearMessages(); purchaseBeingPaid.value = selectedPurchase;
  documentForm.value.type = 'supplier payment';
  purchasePaymentForm.value = {
    amount: Number(installment?.balance_due ?? selectedPurchase.balance_due ?? selectedPurchase.amount_due ?? 0).toFixed(2),
    payment_method: 'cash', payment_date: today(), purchase_installment_id: installment?.id || '', notes: '',
  };
  modal.value = 'purchase_payment';
}
function setPurchaseInstallmentAmount(event) {
  const installment = purchaseBeingPaid.value?.purchaseInstallments?.find(item => Number(item.id) === Number(event.target.value));
  if (installment) purchasePaymentForm.value.amount = Number(installment.balance_due).toFixed(2);
}
function openSalePayment(sale, installmentId = null) {
  const selectedSale = documents.value.find(document => document.id === sale.id) || sale;
  const installment = selectedSale.installments?.find(item => Number(item.id) === Number(installmentId))
    || selectedSale.installments?.find(item => Number(item.balance_due) > 0);
  const balance = installment ? Number(installment.balance_due) : Number(selectedSale.balance_due);
  if (balance <= 0) return;
  clearMessages();
  saleBeingPaid.value = selectedSale.payment_type ? selectedSale : { ...selectedSale, payment_type: 'due' };
  documentForm.value.type = 'sale payment';
  salePaymentForm.value = {
    amount: balance.toFixed(2),
    payment_method: 'cash',
    payment_date: today(),
    sale_installment_id: installment?.id || '',
    notes: '',
  };
  modal.value = 'sale_payment';
}
function setSaleInstallmentAmount(event) {
  const installment = saleBeingPaid.value?.installments?.find(item => Number(item.id) === Number(event.target.value));
  if (installment) salePaymentForm.value.amount = Number(installment.balance_due).toFixed(2);
}
function openCustomerCollection(customer) {
  const dueSale = customerDueDocuments(customer.contact_id || customer.id)[0];
  if (dueSale) openSalePayment(dueSale);
  else toast('No unpaid sale in the current branch for this customer.');
}
function openStockTransfer() {
  if (!operationEnabled('stock_transfer')) { error.value = 'This workflow is disabled in System Setup.'; return; }
  clearMessages();
  stockTransferForm.value = { to_branch_id: transferDestinations.value[0]?.id || '', transfer_date: today(), notes: '', items: [{ product_id: '', quantity: 1 }] };
  modal.value = 'stock_transfer';
}
function openClient(client = null, forceEdit = false) {
  if (client?.expected_tenant_database && !client.tenant_database && !forceEdit) {
    openClientProvision(client);
    return;
  }
  clearMessages(); editingId.value = client?.id || null;
  const owner = client?.admins?.[0];
  clientForm.value = client ? { name: client.name, subdomain: client.subdomain || '', plan_name: client.plan_name || '', subscription_status: client.subscription_status, subscription_ends_at: client.subscription_ends_at?.slice(0, 10) || '', gold_store: !!client.gold_store, shared_stock_sales: !!client.shared_stock_sales, notes: client.notes || '', owner_name: owner?.name || '', owner_email: owner?.email || '', owner_mobile: owner?.mobile || '', owner_password: '', owner_active: owner?.active ?? true, owner_exists: !!owner } : { name: '', subdomain: '', plan_name: 'Standard', subscription_status: 'trial', subscription_ends_at: '', gold_store: false, shared_stock_sales: false, notes: '', owner_name: '', owner_email: '', owner_mobile: '', owner_password: '', owner_active: true, owner_exists: false };
  modal.value = 'client';
}
function openClientProvision(client) {
  clearMessages();
  provisionTarget.value = client;
  provisionConfirmation.value = '';
  modal.value = 'client_provision';
}
async function openClientDetails(client) {
  clearMessages(); busy.value = true;
  try {
    selectedClient.value = await api(`clients/${client.id}`);
    modal.value = 'client_details';
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
function openUser(user = null) {
  clearMessages(); editingId.value = user?.id || null;
  const permissions = user?.permissions ?? (user?.role === 'admin' || (!user && session.value.user.role === 'superadmin') ? permissionOptions.map(permission => permission.value) : []);
  const inheritedReports = reportKinds.filter(report => reportAllowedByPermissions(report, permissions)).map(report => report.id);
  userForm.value = user ? { name: user.name, email: user.email || '', mobile: user.mobile || '', password: '', role: user.role, organization_id: user.organization_id || '', active: user.active, permissions: [...permissions], report_permissions: [...(user.report_permissions ?? inheritedReports)], branch_ids: user.accessible_branches?.map(branch => branch.id) || [] } : { name: '', email: '', mobile: '', password: '', role: session.value.user.role === 'superadmin' ? 'admin' : 'manager', organization_id: session.value.user.role === 'superadmin' ? '' : session.value.user.organization_id, active: true, permissions: [...permissions], report_permissions: session.value.user.role === 'superadmin' ? [...inheritedReports] : [], branch_ids: [] };
  modal.value = 'user';
}
function chooseProduct(item) {
  const product = products.value.find(p => p.id === Number(item.product_id));
  if (!product) return;
  item.unit = product.unit;
  item.previous_conversion = 1;
  if (documentForm.value.type === 'sale_return') {
    item.unit_price = selectedSale.value?.items.find(soldItem => soldItem.product_id === product.id)?.unit_price || '0.00';
    return;
  }
  item.unit_price = documentForm.value.type === 'purchase' || documentForm.value.type === 'purchase_return' ? product.cost_price : product.is_gold_item ? goldUnitPrice(product) : product.sale_price;
}
function goldUnitPrice(product) {
  const rate = goldRates.value.find(item => item.metal === 'gold' && String(item.purity).toUpperCase() === String(product.gold_purity).toUpperCase());
  if (!rate) return product.sale_price || '0.00';
  const net = Math.max(0, Number(product.gold_gross_weight || 0) - Number(product.gold_stone_weight || 0) - Number(product.gold_other_weight || 0));
  const wastage = net * Number(product.wastage_percent || 0) / 100;
  const metalValue = (net + wastage) * Number(rate.rate_per_gram || 0);
  const making = product.making_charge_mode === 'percentage'
    ? metalValue * Number(product.making_charge || 0) / 100
    : product.making_charge_mode === 'flat' ? Number(product.making_charge || 0) : net * Number(product.making_charge || 0);
  return (Math.round((metalValue + making + Number(product.stone_value || 0)) * 100) / 100).toFixed(2);
}
function saveGoldRate() {
  error.value = ''; busy.value = true;
  api('gold/rates', { method: 'POST', body: { ...goldRateForm.value, branch_id: activeBranchId.value } })
    .then(async () => { await refresh(); toast('Metal rate saved.'); })
    .catch(e => { error.value = e.message; })
    .finally(() => { busy.value = false; });
}
function openGoldJob() {
  clearMessages(); editingId.value = null;
  goldJobForm.value = { job_type: 'manufacturing', contact_id: '', product_id: '', gold_purity: '22K', input_weight: '', output_weight: '', wastage_weight: '', labor_amount: '', due_date: '', notes: '' };
  modal.value = 'gold_job';
}
async function saveGoldJob() {
  error.value = ''; busy.value = true;
  try {
    await api('gold/jobs', { method: 'POST', body: { ...goldJobForm.value, branch_id: activeBranchId.value } });
    modal.value = ''; await refresh(); toast('Goldsmith job recorded.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function updateGoldJob(job) {
  error.value = ''; busy.value = true;
  try {
    await api(`gold/jobs/${job.id}`, { method: 'PUT', body: { status: job.status, output_weight: job.output_weight, wastage_weight: job.wastage_weight, labor_amount: job.labor_amount, notes: job.notes || '' , branch_id: activeBranchId.value } });
    await refresh(); toast('Goldsmith job updated.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
function choosePurchase() {
  const purchase = selectedPurchase.value;
  if (!purchase) return;
  documentForm.value.contact_id = purchase.contact_id;
  documentForm.value.items = purchase.items.map(item => ({ product_id: item.product_id, unit: item.product.unit, quantity: 1, unit_price: item.unit_price }));
}
function chooseSale() {
  const sale = selectedSale.value;
  if (!sale) return;
  documentForm.value.contact_id = sale.contact_id;
  documentForm.value.items = sale.items
    .filter(item => returnableQuantity(item.product_id) > 0)
    .map(item => ({ product_id: item.product_id, unit: item.product.unit, quantity: Math.min(1, returnableQuantity(item.product_id)), unit_price: item.unit_price }));
}
async function searchWarranties() {
  error.value = ''; busy.value = true;
  try {
    const query = new URLSearchParams();
    if (warrantySearch.value.trim()) query.set('search', warrantySearch.value.trim());
    if (warrantyStatusFilter.value !== 'all') query.set('status', warrantyStatusFilter.value);
    warrantyResults.value = await api(`warranties${query.toString() ? `?${query}` : ''}`);
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function refreshSalesTargets() {
  const data = await api(`sales-targets?period=${salesTargetPeriod.value}`);
  salesTargets.value = data.targets;
  salesTargetUsers.value = data.users;
}
function openSalesTarget(target = null) {
  clearMessages(); editingId.value = target?.id || null;
  documentForm.value.type = 'Sales target';
  salesTargetForm.value = target ? {
    branch_id: target.branch_id,
    user_id: target.user_id || '',
    period: target.period.slice(0, 7),
    target_amount: target.target_amount,
    notes: target.notes || '',
  } : {
    branch_id: activeBranchId.value || '',
    user_id: '',
    period: salesTargetPeriod.value,
    target_amount: '',
    notes: '',
  };
  modal.value = 'sales_target';
}
async function saveSalesTarget() {
  error.value = ''; busy.value = true;
  try {
    await api(editingId.value ? `sales-targets/${editingId.value}` : 'sales-targets', { method: editingId.value ? 'PUT' : 'POST', body: salesTargetForm.value });
    salesTargetPeriod.value = salesTargetForm.value.period;
    await refreshSalesTargets();
    modal.value = '';
    toast('Sales target saved successfully.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function deleteSalesTarget(target) {
  if (!confirm(`Delete the ${target.branch?.name} sales target for ${target.period.slice(0, 7)}?`)) return;
  error.value = ''; busy.value = true;
  try { await api(`sales-targets/${target.id}`, { method: 'DELETE' }); await refreshSalesTargets(); toast('Sales target deleted.'); }
  catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function saveProduct() {
  error.value = ''; busy.value = true;
  try {
    const result = await api(editingId.value ? `products/${editingId.value}` : 'products', { method: editingId.value ? 'PUT' : 'POST', body: productForm.value });
    await refresh();
    if (documentCreateContext.value === 'product') {
      const item = documentForm.value.items[documentLineToFill.value];
      if (item) { item.product_id = result.id; chooseProduct(item); }
      modal.value = 'document';
      documentCreateContext.value = '';
      toast('Product created and added to this transaction.');
    } else { modal.value = ''; toast('Product saved successfully.'); }
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function deleteProduct(product) {
  if (!canDeleteCatalogRecords.value || !confirm(translate('Delete this product? This cannot be undone.', language.value))) return;
  error.value = ''; busy.value = true;
  try {
    await api(`products/${product.id}`, { method: 'DELETE' });
    await refresh();
    toast('Product deleted.');
  } catch (exception) { error.value = exception.message; }
  finally { busy.value = false; }
}
async function saveCategory() {
  error.value = ''; busy.value = true;
  try {
    const result = await api(editingId.value ? `categories/${editingId.value}` : 'categories', { method: editingId.value ? 'PUT' : 'POST', body: categoryForm.value });
    await refresh();
    if (documentCreateContext.value === 'product_category') {
      productForm.value.category_id = result.id;
      modal.value = 'product';
      documentCreateContext.value = '';
      toast('Category created and selected.');
    } else {
      modal.value = '';
      toast('Category saved successfully.');
    }
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function deleteCategory(category) {
  error.value = ''; busy.value = true;
  try {
    await api(`categories/${category.id}`, { method: 'DELETE' });
    await refresh();
    toast('Category deleted.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function saveBranch() {
  error.value = ''; busy.value = true;
  try {
    if (!editingId.value) throw new Error('Only the superadmin can add a branch from company management.');
    const result = await api(`branches/${editingId.value}`, { method: 'PUT', body: branchForm.value });
    modal.value = '';
    await refresh();
    if (result.is_default) activeBranchId.value = result.id;
    toast('Branch saved successfully.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function saveClientBranch() {
  error.value = ''; busy.value = true;
  try {
    await api(`clients/${selectedClient.value.id}/branches`, { method: 'POST', body: branchForm.value });
    selectedClient.value = await api(`clients/${selectedClient.value.id}`);
    modal.value = 'client_details';
    await refresh();
    toast('Branch added successfully.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function deleteClientBranch(branch) {
  if (!window.confirm(`Delete ${branch.name}? This is only possible for an empty branch with no activity.`)) return;
  error.value = ''; busy.value = true;
  try {
    await api(`clients/${selectedClient.value.id}/branches/${branch.id}`, { method: 'DELETE' });
    selectedClient.value = await api(`clients/${selectedClient.value.id}`);
    await refresh();
    toast('Branch deleted.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
function openStockCheck(stockCheck) {
  clearMessages();
  selectedStockCheck.value = stockCheck;
  stockCheckForm.value = {
    check_date: stockCheck.check_date?.slice(0, 10) || today(),
    notes: stockCheck.notes || '',
    items: stockCheck.items.map(item => ({ id: item.id, product: item.product, expected_quantity: item.expected_quantity, counted_quantity: item.counted_quantity ?? '' })),
  };
  modal.value = 'stock_check';
}
async function startStockCheck() {
  error.value = ''; busy.value = true;
  try {
    const result = await api('stock-checks', { method: 'POST', body: { branch_id: activeBranchId.value, check_date: today(), notes: '' } });
    await refresh();
    openStockCheck(result);
    toast(`${result.number} started.`);
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function saveStockCheck(closeAfter = true) {
  error.value = ''; busy.value = true;
  try {
    const result = await api(`stock-checks/${selectedStockCheck.value.id}`, { method: 'PUT', body: { ...stockCheckForm.value, branch_id: activeBranchId.value } });
    selectedStockCheck.value = result;
    await refresh();
    if (closeAfter) modal.value = '';
    toast(`${result.number} draft saved.`);
    return result;
  } catch (e) { error.value = e.message; return null; } finally { busy.value = false; }
}
async function completeStockCheck() {
  if (uncountedStockItems.value) return;
  const saved = await saveStockCheck(false);
  if (!saved) return;
  busy.value = true;
  try {
    const result = await api(`stock-checks/${saved.id}/complete`, { method: 'POST', body: { branch_id: activeBranchId.value } });
    modal.value = '';
    await refresh();
    toast(`${result.number} completed and stock reconciled.`);
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function saveContact() {
  error.value = ''; busy.value = true;
  try {
    const result = await api(editingId.value ? `contacts/${editingId.value}` : 'contacts', { method: editingId.value ? 'PUT' : 'POST', body: contactForm.value });
    await refresh();
    if (documentCreateContext.value === 'pos_contact') {
      posForm.value.contact_id = result.id;
      modal.value = '';
      documentCreateContext.value = '';
      toast('Customer created and selected for checkout.');
    } else if (documentCreateContext.value === 'contact') {
      documentForm.value.contact_id = result.id;
      modal.value = 'document';
      documentCreateContext.value = '';
      toast(`${relatedContactName.value === 'supplier' ? 'Supplier' : 'Customer'} created. Continue by choosing a product.`);
    } else { modal.value = ''; toast('Contact saved successfully.'); }
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function deleteContact(contact) {
  if (!canDeleteCatalogRecords.value || !confirm(translate('Delete this customer or supplier? This cannot be undone.', language.value))) return;
  error.value = ''; busy.value = true;
  try {
    await api(`contacts/${contact.id}`, { method: 'DELETE' });
    await refresh();
    toast('Contact deleted.');
  } catch (exception) { error.value = exception.message; }
  finally { busy.value = false; }
}
async function completePosSale() {
  error.value = ''; busy.value = true;
  try {
    const payload = {
      type: 'sale', sale_channel: 'pos', branch_id: activeBranchId.value, contact_id: posForm.value.contact_id,
      document_date: posForm.value.document_date, discount: posForm.value.discount,
      tax: posForm.value.tax, payment_type: posForm.value.payment_type, payment_method: posForm.value.payment_method,
      amount_paid: posCashPayment.value.toFixed(2), down_payment: posForm.value.down_payment,
      gold_exchange_weight: posForm.value.gold_exchange_weight, gold_exchange_purity: posForm.value.gold_exchange_purity,
      interest_rate: posForm.value.payment_type === 'installment' ? posForm.value.interest_rate : 0,
      installment_count: posForm.value.installment_count, installment_frequency: posForm.value.installment_frequency,
      installment_start_date: posForm.value.payment_type === 'installment' ? posForm.value.installment_start_date : null, notes: posForm.value.notes,
      items: posForm.value.items.map(item => ({ product_id: item.product_id, unit: item.unit, quantity: item.quantity, unit_price: item.unit_price })),
    };
    const result = await api('documents', { method: 'POST', body: payload });
    await refresh();
    resetPos();
    selectedDocument.value = result;
    modal.value = 'invoice';
    toast(`POS sale ${result.number} completed.`);
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function saveDocument() {
  error.value = ''; busy.value = true;
  try {
    const result = await api('documents', { method: 'POST', body: { ...documentForm.value, branch_id: activeBranchId.value } });
    modal.value = ''; await refresh(); detailId.value = result.id; toast(`${label(result.type)} ${result.number} saved.`);
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function deleteDocument(document) {
  if (session.value?.user?.role !== 'admin' || !confirm(`${translate('Delete this transaction? This will reverse its stock movement and cannot be undone.', language.value)}\n${document.number}`)) return;
  error.value = ''; busy.value = true;
  try {
    await api(`documents/${document.id}?branch_id=${activeBranchId.value}`, { method: 'DELETE' });
    if (detailId.value === document.id) detailId.value = null;
    await refresh();
    toast(`${document.number} deleted.`);
  } catch (exception) { error.value = exception.message; }
  finally { busy.value = false; }
}
async function deleteDocumentPayment(payment) {
  const document = selectedDocument.value;
  if (session.value?.user?.role !== 'admin' || !document || !confirm(`${translate('Delete this payment? This will restore the invoice balance.', language.value)}\n${document.number} · ${money(payment.amount)}`)) return;
  error.value = ''; busy.value = true;
  try {
    const updated = await api(`documents/${document.id}/payments/${payment.id}?branch_id=${activeBranchId.value}`, { method: 'DELETE' });
    await refresh();
    selectedDocument.value = documents.value.find(item => item.id === updated.id) || updated;
    toast('Payment deleted.');
  } catch (exception) { error.value = exception.message; }
  finally { busy.value = false; }
}
async function saveAdjustment() {
  error.value = ''; busy.value = true;
  try {
    await api(`products/${adjustmentForm.value.product_id}/adjust`, { method: 'POST', body: { ...adjustmentForm.value, branch_id: activeBranchId.value } });
    modal.value = ''; await refresh(); toast('Stock adjustment saved.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function saveExpense() {
  error.value = ''; busy.value = true;
  try {
    const category = expenseForm.value.category === '__new__' ? newExpenseCategory.value.trim() : expenseForm.value.category;
    await api(editingId.value ? `expenses/${editingId.value}` : 'expenses', { method: editingId.value ? 'PUT' : 'POST', body: { ...expenseForm.value, category, branch_id: activeBranchId.value } });
    modal.value = ''; await refresh(); toast('Expense saved.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function deleteExpense(expense) {
  if (!confirm(`Delete expense “${expense.description}”?`)) return;
  error.value = ''; busy.value = true;
  try { await api(`expenses/${expense.id}`, { method: 'DELETE' }); if (expenses.value.length === 1 && expensePage.value > 1) expensePage.value--; await refresh(); toast('Expense deleted.'); }
  catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function recordPurchasePayment() {
  if (!purchaseBeingPaid.value) return;
  error.value = ''; busy.value = true;
  try {
    await api(`purchases/${purchaseBeingPaid.value.id}/payments`, { method: 'POST', body: purchasePaymentForm.value });
    modal.value = ''; await refresh(); toast('Supplier payment recorded.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function recordSalePayment() {
  if (!saleBeingPaid.value) return;
  error.value = ''; busy.value = true;
  try {
    const updatedSale = await api(`documents/${saleBeingPaid.value.id}/payments`, { method: 'POST', body: salePaymentForm.value });
    const saleId = saleBeingPaid.value.id;
    await refresh();
    const refreshedSale = documents.value.find(document => document.id === saleId) || updatedSale;
    modal.value = refreshedSale ? 'invoice' : '';
    selectedDocument.value = refreshedSale || null;
    toast('Customer payment recorded.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function saveStockTransfer() {
  error.value = ''; busy.value = true;
  try {
    const result = await api('stock-transfers', { method: 'POST', body: { ...stockTransferForm.value, from_branch_id: activeBranchId.value } });
    modal.value = '';
    await refresh();
    toast(`${result.number} completed successfully.`);
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function saveClient() {
  error.value = ''; busy.value = true;
  try {
    const saved = await api(editingId.value ? `clients/${editingId.value}` : 'clients', { method: editingId.value ? 'PUT' : 'POST', body: clientForm.value });
    await refresh();
    if (saved.expected_tenant_database && !saved.tenant_database) {
      openClientProvision(saved);
      toast('Company saved. Create its database in cPanel to finish setup.');
    } else {
      modal.value = '';
      toast('Client subscription saved.');
    }
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function provisionClient() {
  if (!provisionTarget.value || provisionConfirmation.value !== provisionTarget.value.expected_tenant_database) return;
  error.value = ''; busy.value = true;
  try {
    await api(`clients/${provisionTarget.value.id}/provision`, { method: 'POST', body: { database: provisionConfirmation.value } });
    modal.value = '';
    await refresh();
    toast(`${provisionTarget.value.name} is ready.`);
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function renewClient(client, months) {
  error.value = ''; busy.value = true;
  try {
    const updated = await api(`clients/${client.id}/renew`, { method: 'POST', body: { months } });
    selectedClient.value = selectedClient.value?.id === updated.id ? { ...selectedClient.value, ...updated } : selectedClient.value;
    await refresh();
    toast(`${updated.name} renewed for ${months} ${months === 1 ? 'month' : 'months'}.`);
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function setClientAccess(client) {
  const paused = !client.access_paused;
  if (paused && !window.confirm(`Pause access for ${client.name} and all its users? Their saved data and subscription dates will stay unchanged.`)) return;
  error.value = ''; busy.value = true;
  try {
    const updated = await api(`clients/${client.id}/access`, { method: 'PUT', body: { paused } });
    if (selectedClient.value?.id === updated.id) selectedClient.value = { ...selectedClient.value, ...updated };
    await refresh();
    toast(paused ? `${updated.name} access paused.` : updated.subscription_active ? `${updated.name} access restored.` : `${updated.name} company pause removed. Subscription remains inactive.`);
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function setUserAccess(user) {
  const paused = !user.access_paused;
  if (paused && !window.confirm(`Pause ${user.name}'s account? They will lose access immediately, without changing other users.`)) return;
  error.value = ''; busy.value = true;
  try {
    const updated = await api(`users/${user.id}/access`, { method: 'PUT', body: { paused } });
    if (selectedClient.value?.users) selectedClient.value.users = selectedClient.value.users.map(item => item.id === updated.id ? { ...item, access_paused: paused } : item);
    await refresh();
    toast(paused ? `${updated.name} account paused.` : updated.active && !updated.organization?.access_paused ? `${updated.name} account restored.` : `${updated.name} platform pause removed. Other access restrictions still apply.`);
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function saveUser() {
  error.value = ''; busy.value = true;
  try {
    await api(editingId.value ? `users/${editingId.value}` : 'users', { method: editingId.value ? 'PUT' : 'POST', body: userForm.value });
    modal.value = ''; await refresh(); toast('User account saved.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}

async function viewAsUser(user) {
  error.value = ''; busy.value = true;
  try {
    const result = await api(`users/${user.id}/impersonate`, { method: 'POST' });
    session.value = await api('session');
    page.value = 'dashboard';
    history.pushState(null, '', '/dashboard');
    search.value = '';
    await refresh();
    toast(result.message);
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}

async function deleteInactiveUser(user) {
  if (!window.confirm(`Permanently delete ${user.name}'s inactive account? This cannot be undone.`)) return;
  error.value = ''; busy.value = true;
  try {
    await api(`users/${user.id}`, { method: 'DELETE' });
    await refresh();
    toast(`${user.name}'s account was permanently deleted.`);
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}

async function saveSystemSetup() {
  error.value = ''; busy.value = true;
  try {
    if (!systemSetup.value.store.units.length) throw new Error('Add at least one product unit.');
    systemSetup.value = await api('system-setup', { method: 'PUT', body: systemSetup.value });
    session.value = await api('session');
    session.value.operational_setup = { sales: systemSetup.value.sales, purchases: systemSetup.value.purchases, supplier_advances: systemSetup.value.supplier_advances, store: systemSetup.value.store };
    if (session.value.user.organization) Object.assign(session.value.user.organization, systemSetup.value.company);
    if (session.value.impersonation?.organization) session.value.impersonation.organization.name = systemSetup.value.company.name;
    toast('System setup saved successfully.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}

async function saveCashMovement() {
  if (!activeBranchId.value || !overview.value.cash || !cashAction.value) return;
  error.value = ''; busy.value = true;
  try {
    const action = cashAction.value;
    const payload = { branch_id: Number(activeBranchId.value), notes: cashNotes.value };
    if (action === 'withdraw') payload.amount = cashWithdrawalAmount.value;
    const result = await api(`cash/${action}`, { method: 'POST', body: payload });
    overview.value.cash = result.cash;
    cashAction.value = ''; cashWithdrawalAmount.value = ''; cashNotes.value = '';
    toast(action === 'transfer' ? 'Full paid balance transferred to the owner.' : 'Owner sales withdrawal recorded.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}

function toggleSetupUnit(value) {
  const units = systemSetup.value.store.units;
  if (units.includes(value)) {
    systemSetup.value.store.units = units.filter(unit => unit !== value);
    if (!defaultTransactionConversionUnits.includes(value)) delete systemSetup.value.store.unit_conversions[value];
    return;
  }
  if (units.length >= 50) {
    error.value = 'You can configure up to 50 product units.';
    return;
  }
  systemSetup.value.store.units = [...units, value];
  systemSetup.value.store.unit_conversions[value] = Number(systemSetup.value.store.unit_conversions[value] || 1);
}

function addSetupUnitFromSearch() {
  const value = unitQuery.value.trim();
  if (!value) return;
  if (!systemSetup.value.store.units.some(unit => unit.toLocaleLowerCase() === value.toLocaleLowerCase())) {
    if (systemSetup.value.store.units.length >= 50) {
      error.value = 'You can configure up to 50 product units.';
      return;
    }
    systemSetup.value.store.units.push(value);
    systemSetup.value.store.unit_conversions[value] = 1;
  }
  unitQuery.value = '';
  unitPickerOpen.value = false;
}

async function startSupportSession(client) {
  error.value = ''; busy.value = true;
  try {
    const result = await api(`clients/${client.id}/impersonate`, { method: 'POST' });
    session.value = await api('session');
    page.value = 'dashboard';
    history.pushState(null, '', '/dashboard');
    search.value = '';
    await refresh();
    toast(result.message);
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}

async function stopSupportSession() {
  error.value = ''; busy.value = true;
  try {
    const result = await api('support-session', { method: 'DELETE' });
    session.value = await api('session');
    page.value = session.value.user.role === 'superadmin' ? 'clients' : 'users';
    history.pushState(null, '', `/${page.value}`);
    search.value = '';
    await refresh();
    toast(result.message);
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
</script>

<template>
  <div v-if="loading" class="loading-screen"><div class="brand-mark" role="img" aria-label="Uddog-Soft logo"></div><span>Loading your workspace...</span></div>
  <div v-else-if="!session?.user" class="auth-page">
    <div class="auth-panel">
      <form class="auth-card" @submit.prevent="authMode === 'forgot' ? requestPasswordReset() : authMode === 'reset' ? resetPassword() : authenticate()">
        <label class="language-switcher auth-language"><Languages :size="15" /><select v-model="language" aria-label="Language"><option value="en">English</option><option value="bn">বাংলা</option></select></label>
        <div class="auth-mobile-brand brand"><img class="auth-logo-mark" :src="'/favicon.svg'" alt="" width="54" height="54"><span>Uddog-Soft</span></div>
        <template v-if="session?.setup_required || authMode !== 'login'">
          <span class="eyebrow">{{ session?.setup_required ? 'GET STARTED' : authMode === 'forgot' ? 'ACCOUNT RECOVERY' : 'NEW PASSWORD' }}</span>
          <h2>{{ session?.setup_required ? 'Create your workspace' : authMode === 'forgot' ? 'Forgot your password?' : 'Choose a new password' }}</h2>
          <p class="muted">{{ session?.setup_required ? 'Set up the first administrator account.' : authMode === 'forgot' ? 'Enter your account email and we’ll send a reset link if it matches an account.' : 'Set a new password for your account.' }}</p>
        </template>
        <div v-if="error" class="alert-box error">{{ error }}</div>
        <div v-if="authMessage" class="alert-box success">{{ authMessage }}</div>
        <label v-if="session?.setup_required" class="field"><span>Full name</span><input v-model="authForm.name" required autocomplete="name" placeholder="Your name"></label>
        <label class="field"><span>{{ authMode === 'login' && !session?.setup_required ? 'Email address or phone number' : 'Email address' }}</span><input v-model="authForm.email" :type="authMode === 'login' && !session?.setup_required ? 'text' : 'email'" required :autocomplete="authMode === 'login' && !session?.setup_required ? 'username' : 'email'" :placeholder="authMode === 'login' && !session?.setup_required ? 'Email or registered phone number' : 'name@company.com'" :readonly="authMode === 'reset'"></label>
        <template v-if="authMode !== 'forgot'">
          <div class="field">
            <label for="auth-password">{{ authMode === 'reset' ? 'New password' : 'Password' }}</label>
            <div class="auth-password-wrap">
              <input id="auth-password" v-model="authForm.password" :type="authPasswordVisible ? 'text' : 'password'" required :minlength="session?.setup_required || authMode === 'reset' ? 8 : undefined" :autocomplete="session?.setup_required || authMode === 'reset' ? 'new-password' : 'current-password'" :placeholder="session?.setup_required || authMode === 'reset' ? 'At least 8 characters' : 'Your password'">
              <button type="button" class="auth-password-toggle" :aria-label="language === 'bn' ? (authPasswordVisible ? 'পাসওয়ার্ড লুকান' : '৫ সেকেন্ডের জন্য পাসওয়ার্ড দেখুন') : (authPasswordVisible ? 'Hide password' : 'Show password for 5 seconds')" :title="language === 'bn' ? (authPasswordVisible ? 'পাসওয়ার্ড লুকান' : '৫ সেকেন্ডের জন্য পাসওয়ার্ড দেখুন') : (authPasswordVisible ? 'Hide password' : 'Show password for 5 seconds')" :aria-pressed="authPasswordVisible" @click="toggleAuthPassword"><EyeOff v-if="authPasswordVisible" :size="19" /><Eye v-else :size="19" /></button>
            </div>
          </div>
          <label v-if="authMode === 'reset'" class="field"><span>Confirm new password</span><input v-model="authForm.password_confirmation" type="password" required minlength="8" autocomplete="new-password" placeholder="Enter the new password again"></label>
        </template>
        <button class="btn-primary-app full" :disabled="busy">{{ busy ? 'Please wait...' : authMode === 'forgot' ? 'Send reset link' : authMode === 'reset' ? 'Reset password' : session?.setup_required ? 'Create workspace' : 'Sign in' }} <ArrowUpRight :size="18" /></button>
        <button v-if="authMode === 'login' && !session?.setup_required" class="auth-link-button" type="button" @click="showAuthMode('forgot')">Forgot password?</button>
        <button v-if="authMode !== 'login' && !session?.setup_required" class="auth-link-button" type="button" @click="showAuthMode('login')">Back to sign in</button>
      </form>
    </div>
  </div>
  <div v-else-if="!session?.permissions?.use_workspace && !session?.permissions?.manage_clients && page !== 'profile'" class="loading-screen subscription-lock"><ShieldCheck :size="42" /><h2>{{ session.user.organization?.access_paused ? 'Company access paused' : 'Subscription access paused' }}</h2><p v-if="session.user.organization?.access_paused"><strong>{{ session.user.organization?.name || 'Your company' }}</strong> has been paused by the platform superadmin.</p><p v-else><strong>{{ session.user.organization?.name || 'Your client account' }}</strong> does not have an active subscription.</p><p>{{ session.user.organization?.access_paused ? 'Your company workspace is unavailable until the platform superadmin restores access. Your saved data and subscription dates remain unchanged.' : 'Sales, purchases, inventory, reports and settings are unavailable until the platform superadmin renews or reactivates the company.' }}</p><p v-if="session.subscription?.ends_on" class="subscription-lock-date">Valid until {{ date(session.subscription.ends_on) }}</p><div class="subscription-lock-actions"><button class="btn-primary-app" @click="openProfile"><UserCog :size="17" /> View profile</button><button class="btn-secondary-app" @click="logout"><LogOut :size="17" /> Sign out</button></div></div>
  <div v-else-if="!session?.permissions?.abilities?.length && !session?.permissions?.manage_clients && !session?.permissions?.manage_users && page !== 'profile' && !session?.impersonation" class="loading-screen subscription-lock"><ShieldCheck :size="42" /><h2>No access assigned yet</h2><p>Your company owner has not assigned any workspace permissions to this account.</p><button class="btn-secondary-app" @click="logout"><LogOut :size="17" /> Sign out</button></div>
  <div v-else class="app-shell" :class="{ 'report-view-shell': isReportView, 'client-mobile-shell': session.user.role !== 'superadmin' && !session.impersonation && session.permissions.use_workspace, 'client-dashboard-shell': page === 'dashboard' }">
    <div v-if="mobileOpen" class="mobile-scrim" @click="mobileOpen = false"></div>
    <aside class="sidebar" :class="{ 'sidebar-open': mobileOpen }">
      <div class="brand"><span class="brand-mark" aria-hidden="true"></span><span>Uddog-Soft</span><button class="icon-button sidebar-close" @click="mobileOpen = false"><X :size="20" /></button></div>
      <div class="sidebar-caption">{{ session.user.role === 'superadmin' && !session.impersonation ? 'SAAS CONTROL' : 'WORKSPACE' }}</div>
      <nav class="nav-list">
        <template v-for="item in nav" :key="item.id">
          <button class="nav-item-app" :class="{ active: page === item.id }" :aria-current="page === item.id ? 'page' : undefined" @click="navigate(item.id)"><component :is="item.icon" :size="18" :stroke-width="1.9" /><span>{{ item.label }}</span><span v-if="item.id === 'purchase_return'" class="nav-spacer"></span></button>
        </template>
      </nav>
      <div class="sidebar-bottom"><div class="sidebar-help"><div class="help-icon"><component :is="session.user.role === 'superadmin' && !session.impersonation ? Building2 : Boxes" :size="20" /></div><strong>{{ session.user.role === 'superadmin' && !session.impersonation ? 'Subscription control' : 'Inventory at a glance' }}</strong><p>{{ session.user.role === 'superadmin' && !session.impersonation ? 'Manage companies, validity and owner support.' : 'Track every movement from purchase to sale.' }}</p></div><div class="sidebar-user"><div class="avatar">{{ initial(session.user.name) }}</div><div class="user-copy"><strong>{{ session.user.name }}</strong><span>{{ roleLabel(session.user.role) }}</span></div><button class="icon-button" title="Sign out" @click="logout"><LogOut :size="18" /></button></div></div>
    </aside>
    <div class="main-wrap">
      <div v-if="session.impersonation" class="support-mode-banner"><div><Eye :size="18" /><span><strong>{{ session.impersonation.impersonator.role === 'superadmin' ? 'Support mode' : 'View as user' }}</strong> — viewing {{ session.impersonation.organization.name }} as {{ session.impersonation.owner.name }}</span></div><button class="support-return-button" :disabled="busy" @click="stopSupportSession"><Undo2 :size="16" /> Return to {{ session.impersonation.impersonator.role === 'superadmin' ? 'superadmin' : 'owner' }}</button></div>
<header class="topbar"><div class="topbar-left"><button class="icon-button mobile-menu" @click="mobileOpen = true"><Menu :size="22" /></button><span class="breadcrumb-home">{{ session.user.role === 'superadmin' && !session.impersonation ? 'Platform' : 'Workspace' }}</span><span class="breadcrumb-slash">/</span><strong>{{ titles[page] || 'Dashboard' }}</strong></div><div class="topbar-right"><button v-if="systemUpdateStatus?.total" type="button" class="btn-secondary-app" :disabled="busy" @click="modal = 'system_update'"><AlertTriangle :size="16" /> Update available</button><button v-if="session.permissions.use_workspace && hasAbility('sales')" type="button" class="btn-primary-app topbar-new-sale" :disabled="busy || !activeBranchId" aria-label="New Sale" title="New Sale" @click="openDocument('sale')"><Plus :size="17" /><span>New Sale</span></button><button v-if="session.permissions.use_workspace && hasAbility('pos_sale')" type="button" class="btn-secondary-app topbar-pos-sale" :disabled="busy || !activeBranchId" aria-label="POS Sale" title="POS Sale" @click="navigate('pos')"><ScanLine :size="17" /><span>POS Sale</span></button><span v-if="session.user.role !== 'superadmin' && session.user.organization?.name" class="company-chip" :title="`Company: ${session.user.organization.name}`"><Building2 :size="15" /><span>{{ session.user.organization.name }}</span></span><button v-if="canShowInstall" type="button" class="btn-secondary-app install-app-button" @click="installApp">{{ language === 'bn' ? 'অ্যাপ ইনস্টল করুন' : 'Install app' }}</button><label class="language-switcher"><Languages :size="15" /><select v-model="language" aria-label="Language"><option value="en">English</option><option value="bn">বাংলা</option></select></label><label v-if="session.permissions.use_workspace && branches.length" class="branch-switcher"><MapPin :size="15" /><select v-model="activeBranchId" :disabled="busy" aria-label="Active branch" @change="switchBranch"><option v-for="branch in branches.filter(item => item.active)" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select></label><span class="topbar-date">{{ date(today()) }}</span><div class="profile-menu-wrap"><button class="avatar small profile-trigger" type="button" aria-label="Open profile menu" :aria-expanded="profileMenuOpen" @click="profileMenuOpen = !profileMenuOpen">{{ initial(session.user.name) }}</button><div v-if="profileMenuOpen" class="profile-menu"><button type="button" @click="openProfile"><UserCog :size="16" /> My profile</button><button type="button" @click="logout"><LogOut :size="16" /> Sign out</button></div></div></div></header>
      <main class="content" :class="{ 'dashboard-content': page === 'dashboard', 'compact-page-heading': page !== 'dashboard', 'customers-page': page === 'customers', 'suppliers-page': page === 'suppliers' }">
        <div v-if="session.user.role !== 'superadmin' && !session.impersonation && session.permissions.use_workspace && !isReportView" class="client-mobile-context">
          <label v-if="branches.length" class="mobile-branch-select"><MapPin :size="16" /><select v-model="activeBranchId" :disabled="busy" aria-label="Active branch" @change="switchBranch"><option v-for="branch in branches.filter(item => item.active)" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select><ChevronDown :size="14" /></label>
          <label class="mobile-language-select"><Languages :size="16" /><select v-model="language" aria-label="Language"><option value="en">English</option><option value="bn">বাংলা</option></select><ChevronDown :size="14" /></label>
        </div>
        <div v-if="notice" class="toast-notice"><Check :size="17" />{{ notice }}</div>
        <button v-if="['customers', 'suppliers'].includes(page) && directoryDueOnly" type="button" class="directory-due-filter" @click="directoryDueOnly = false">Showing accounts with a due balance <span>{{ page === 'customers' ? 'Show all customers' : 'Show all suppliers' }} <ArrowUpRight :size="15" /></span></button>
        <div v-if="error && ['products', 'customers', 'suppliers'].includes(page)" class="alert-box error" role="alert">{{ error }}</div>
        <template v-if="page === 'reports'">
          <section v-if="!reportDetailOpen && !isReportView" class="reports-catalog" aria-labelledby="reports-catalog-title">
            <div class="reports-catalog-header">
              <h1 id="reports-catalog-title">All reports</h1>
              <span class="reports-catalog-count"><strong>{{ qty(availableReportKinds.length) }}</strong> reports available</span>
            </div>
            <div class="reports-catalog-toolbar">
              <label class="reports-catalog-search"><Search :size="19" aria-hidden="true" /><input v-model="reportSearch" type="search" placeholder="Search reports by name or topic" aria-label="Search reports" autocomplete="off"></label>
              <span v-if="reportSearch.trim()" class="reports-catalog-match-count" role="status">{{ qty(filteredReportKinds.length) }} {{ translate('matching reports', language) }}</span>
            </div>
            <div class="reports-catalog-grid">
              <button v-for="report in filteredReportKinds" :key="report.id" type="button" class="reports-catalog-card" @click="selectReport(report.id)">
                <span class="reports-catalog-icon" :class="reportVisuals[report.id].tone"><component :is="reportVisuals[report.id].icon" :size="23" /></span>
                <span class="reports-catalog-copy"><strong>{{ report.label }}</strong><small>{{ report.description }}</small></span>
                <ArrowUpRight class="reports-catalog-arrow" :size="18" aria-hidden="true" />
              </button>
            </div>
            <div v-if="!filteredReportKinds.length" class="reports-catalog-empty"><Search :size="24" /><strong>{{ translate('No matching reports', language) }}</strong><span>{{ translate('Try another report name or keyword.', language) }}</span><button type="button" @click="reportSearch = ''">{{ translate('Clear search', language) }}</button></div>
          </section>
          <div v-if="reportDetailOpen || isReportView" class="report-detail">
          <button v-if="!isReportView" type="button" class="reports-catalog-back" @click="reportDetailOpen = false"><ArrowLeft :size="17" /> All reports</button>
          <div class="page-heading"><div><span class="eyebrow">BUSINESS INSIGHTS</span><h1>{{ activeReport.label }}</h1><p>{{ activeReport.description }} · {{ reportBranchName }}</p></div><div v-if="isReportView && !reportLoading && reportCanExport" class="report-action-group" aria-label="Report actions"><button type="button" class="report-icon-action" title="Print" aria-label="Print" data-tooltip="Print" @click="printReport"><Printer :size="19" /></button><template v-if="['profit_loss', 'product_ledger'].includes(reportKind)"><button type="button" class="report-icon-action" title="Save as PDF" aria-label="Save as PDF" data-tooltip="Save as PDF" :disabled="reportSaveBusy" @click="saveReportFile('pdf')"><FileText :size="19" /></button><button type="button" class="report-icon-action" title="Save as image" aria-label="Save as image" data-tooltip="Save as image" :disabled="reportSaveBusy" @click="saveReportFile('image')"><ImageIcon :size="19" /></button></template><button v-else type="button" class="report-icon-action" title="Export CSV" aria-label="Export CSV" data-tooltip="Export CSV" @click="exportReportCsv"><ArrowDownRight :size="19" /></button></div></div>
          <section v-if="!isReportView" class="panel report-filter-panel">
            <div class="report-filter-grid" :class="{ 'ledger-filter-grid': reportKind === 'general_ledger', 'product-ledger-filter-grid': ['product_ledger', 'product_lifecycle_ledger'].includes(reportKind) }">
              <div class="report-current"><span>Report</span><strong>{{ activeReport.label }}</strong></div>
              <label class="field"><span>From</span><input v-model="reportFilters.date_from" type="date" :disabled="['stock', 'barcode_products', 'customers', 'suppliers'].includes(reportKind)"></label>
              <label class="field"><span>To</span><input v-model="reportFilters.date_to" type="date" :disabled="['stock', 'barcode_products', 'customers', 'suppliers'].includes(reportKind)"></label>
              <label v-if="['profit_loss', 'product_ledger', 'product_lifecycle_ledger'].includes(reportKind) && reportBranches.length > 1" class="field"><span>Branch</span><select v-model="reportScopeBranchId" aria-label="Report branch"><option value="all">All permitted branches</option><option v-for="branch in reportBranches" :key="branch.id" :value="String(branch.id)">{{ branch.name }}</option></select></label>
              <label v-if="['stock', 'barcode_products', 'barcode_sales', 'product_ledger'].includes(reportKind)" class="field"><span>Product or barcode</span><input v-model="reportFilters.search" placeholder="Enter a name or barcode"></label>
              <label v-if="reportKind === 'product_lifecycle_ledger'" class="field"><span>{{ translate('Product', language) }}</span><select v-model="reportFilters.product_id" aria-label="Product"><option value="">{{ translate('Select a product', language) }}</option><option v-for="product in [...stockProducts].sort((a, b) => a.name.localeCompare(b.name))" :key="product.id" :value="product.id">{{ product.name }}{{ product.sku ? ` · ${product.sku}` : '' }}</option></select></label>
              <label v-if="reportKind === 'customer_ledger'" class="field"><span>Customer</span><select v-model="reportFilters.contact_id"><option value="">All customers</option><option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option></select></label>
              <label v-if="reportKind === 'supplier_ledger'" class="field"><span>Supplier</span><select v-model="reportFilters.contact_id"><option value="">All suppliers</option><option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option></select></label>
              <label v-if="reportKind === 'supplier_tt'" class="field"><span>Company name</span><input v-model="reportFilters.search" placeholder="Search company name"></label>
              <label v-if="reportKind === 'general_ledger'" class="field"><span>Account</span><select v-model="reportFilters.account"><option value="">All accounts</option><option value="cash">1000 · Cash</option><option value="bank">1010 · Bank and digital payments</option><option value="unclassified">1090 · Unclassified payments</option><option value="receivables">1100 · Accounts receivable</option><option value="inventory">1200 · Inventory</option><option value="payables">2000 · Accounts payable</option><option value="owner_drawings">3000 · Owner drawings</option><option value="sales">4000 · Sales revenue</option><option value="sales_returns">4010 · Sales returns</option><option value="cost_of_goods">5000 · Cost of goods sold</option><option value="expenses">6000 · Operating expenses</option></select></label>
              <a class="btn-primary-app report-run-button" :class="{ 'report-run-disabled': reportKind === 'product_lifecycle_ledger' && !reportFilters.product_id }" :aria-disabled="reportKind === 'product_lifecycle_ledger' && !reportFilters.product_id" :href="reportKind === 'product_lifecycle_ledger' && !reportFilters.product_id ? undefined : reportViewUrl" target="_blank" rel="noopener"><Search :size="17" /> Run report</a>
            </div>
            <p class="report-description">{{ activeReport.description }} · {{ reportBranchName }}</p>
          </section>
          <div v-if="error" class="alert-box error report-error">{{ error }}</div>
          <section v-if="reportKind === 'profit_loss'" class="profit-loss-sheet" aria-label="Profit and loss statement">
            <div class="profit-loss-sheet-header"><small>FINANCIAL OVERVIEW</small><strong>{{ session.user.organization?.name || 'Company / store' }}</strong><span>Profit &amp; Loss · {{ reportPeriod }} · {{ reportBranchName }}</span></div>
            <div v-if="reportLoading" class="profit-loss-sheet-status">Loading report…</div>
            <div v-else-if="reportResult.income && reportResult.expenses" class="profit-loss-table-wrap"><table class="profit-loss-table"><colgroup><col class="profit-loss-col-serial"><col class="profit-loss-col-account"><col class="profit-loss-col-amount"><col class="profit-loss-col-serial"><col class="profit-loss-col-account"><col class="profit-loss-col-amount"></colgroup><thead><tr class="profit-loss-groups"><th colspan="3">Income</th><th colspan="3">Expenses</th></tr><tr class="profit-loss-columns"><th class="profit-loss-serial">SL</th><th>{{ translate('Income Head', language) }}</th><th>Amount</th><th class="profit-loss-serial">SL</th><th>{{ translate('Expense Head', language) }}</th><th>Amount</th></tr></thead><tbody><tr v-for="index in Math.max(reportResult.income.length, reportResult.expenses.length, 1)" :key="index"><td class="profit-loss-serial">{{ reportResult.income[index - 1] ? index : '' }}</td><td>{{ reportResult.income[index - 1]?.account || '' }}</td><td class="profit-loss-amount">{{ reportResult.income[index - 1] ? money(reportResult.income[index - 1].amount) : '' }}</td><td class="profit-loss-serial">{{ reportResult.expenses[index - 1] ? index : '' }}</td><td>{{ reportResult.expenses[index - 1]?.account || '' }}</td><td class="profit-loss-amount">{{ reportResult.expenses[index - 1] ? money(reportResult.expenses[index - 1].amount) : '' }}</td></tr><tr class="profit-loss-total"><th colspan="2">Total income</th><td class="profit-loss-amount">{{ money(reportResult.summary.net_sales) }}</td><th colspan="2">Total costs &amp; expenses</th><td class="profit-loss-amount">{{ money(reportResult.summary.total_costs_and_expenses) }}</td></tr><tr class="profit-loss-net"><th colspan="5">{{ Number(reportResult.summary.net_profit) < 0 ? 'Loss for this period' : 'Profit for this period' }}</th><td class="profit-loss-amount">{{ money(reportResult.summary.net_profit) }}</td></tr></tbody></table></div>
            <div v-else class="profit-loss-sheet-status">No report data. Check the date range and branch selection.</div>
            <p v-if="reportResult.income" class="profit-loss-note">{{ translate('Cost of sales = Opening Inventory + Purchases - Closing Inventory', language) }}</p>
            <footer v-if="reportResult.income" class="profit-loss-footer"><div class="profit-loss-signatures"><div><span></span><strong>{{ translate('Prepared by:', language) }}</strong></div><div><span></span><strong>{{ translate('Verified by:', language) }}</strong></div><div><span></span><strong>{{ translate('Approved by:', language) }}</strong></div></div><p>{{ reportGeneratedLabel }}</p></footer>
          </section>
          <section v-else-if="reportKind === 'product_lifecycle_ledger'" class="profit-loss-sheet lifecycle-ledger-sheet" aria-label="Product Ledger Report">
            <div class="profit-loss-sheet-header"><small>{{ translate('PRODUCT HISTORY', language) }}</small><strong>{{ reportResult.product?.name || translate('Product Ledger Report', language) }}</strong><span>{{ reportPeriod }} · {{ translate(reportBranchName, language) }}</span></div>
            <div v-if="reportLoading" class="profit-loss-sheet-status">{{ translate('Loading report…', language) }}</div>
            <template v-else-if="reportResult.product">
              <div class="standard-report-summary lifecycle-ledger-summary">
                <div v-for="[key, label] in [['opening_stock', 'Opening stock'], ['purchased_quantity', 'Net purchased'], ['sold_quantity', 'Net sold'], ['sales', 'Sales revenue'], ['cogs', 'FIFO COGS'], ['realized_profit', 'Realized profit'], ['closing_stock', 'Closing stock'], ['closing_value', 'Stock valuation']]" :key="key" class="standard-report-stat"><span>{{ translate(label, language) }}</span><strong :class="{ 'lifecycle-negative': key === 'realized_profit' && reportResult.summary[key] < 0 }">{{ ['opening_stock', 'purchased_quantity', 'sold_quantity', 'closing_stock'].includes(key) ? qty(reportResult.summary[key]) : money(reportResult.summary[key]) }}</strong></div>
              </div>
              <div v-if="reportResult.rows.length" class="profit-loss-table-wrap"><table class="lifecycle-ledger-table"><thead><tr><th>{{ translate('Date & time', language) }}</th><th>{{ translate('Transaction / lot', language) }}</th><th>{{ translate('Movement', language) }}</th><th>{{ translate('Quantity', language) }}</th><th>{{ translate('Unit price', language) }}</th><th>{{ translate('Price change', language) }}</th><th>{{ translate('Amount', language) }}</th><th>{{ translate('FIFO COGS', language) }}</th><th>{{ translate('Gross profit', language) }}</th><th>{{ translate('Margin', language) }}</th><th>{{ translate('Stock balance', language) }}</th><th>{{ translate('Stock valuation', language) }}</th><th>{{ translate('Cumulative sales', language) }}</th><th>{{ translate('Cumulative COGS', language) }}</th><th>{{ translate('Cumulative profit', language) }}</th></tr></thead><tbody><tr v-for="(row, index) in reportResult.rows" :key="index"><td><strong>{{ date(row.at.slice(0, 10)) }}</strong><small>{{ time(row.at.replace(' ', 'T')) }}</small></td><td><strong>{{ row.reference }}</strong><small v-if="reportBranchId === 'all'">{{ branches.find(branch => branch.id === row.branch_id)?.name }}</small><details v-if="row.allocations?.length" class="lifecycle-lot-details"><summary>{{ qty(row.allocations.length) }} {{ translate('lot allocation(s)', language) }}</summary><div v-for="(lot, lotIndex) in row.allocations" :key="lotIndex"><b>{{ lot.reference }}</b><span>{{ qty(lot.quantity) }} · {{ translate('Costing', language) }} {{ money(lot.cost) }}</span><span v-if="lot.revenue !== null">{{ translate('Sales revenue', language) }} {{ money(lot.revenue) }} · {{ translate('Gross profit', language) }} {{ money(lot.profit) }} · {{ lot.margin === null ? '—' : `${qty(lot.margin)}%` }}</span></div></details></td><td><span class="lifecycle-type" :class="row.type">{{ translate(row.type.replaceAll('_', ' '), language) }}</span></td><td class="lifecycle-number" :class="row.quantity < 0 ? 'lifecycle-negative' : 'lifecycle-positive'">{{ row.quantity > 0 ? '+' : '' }}{{ qty(row.quantity) }}</td><td class="lifecycle-number">{{ row.unit_price === null ? '—' : money(row.unit_price) }}</td><td class="lifecycle-number" :class="row.price_change > 0 ? (['sale', 'resale'].includes(row.type) ? 'lifecycle-positive' : 'lifecycle-negative') : row.price_change < 0 ? (['sale', 'resale'].includes(row.type) ? 'lifecycle-negative' : 'lifecycle-positive') : ''"><template v-if="row.price_change !== null">{{ row.price_change > 0 ? '+' : '' }}{{ money(row.price_change) }} <small>({{ row.price_change_percent > 0 ? '+' : '' }}{{ row.price_change_percent ?? '—' }}%)</small></template><span v-else>—</span></td><td class="lifecycle-number">{{ row.amount ? money(row.amount) : '—' }}</td><td class="lifecycle-number">{{ row.cogs ? money(row.cogs) : '—' }}</td><td class="lifecycle-number" :class="row.profit < 0 ? 'lifecycle-negative' : row.profit > 0 ? 'lifecycle-positive' : ''">{{ row.revenue ? money(row.profit) : '—' }}</td><td class="lifecycle-number">{{ row.margin === null ? '—' : `${qty(row.margin)}%` }}</td><td class="lifecycle-number">{{ qty(row.stock_balance) }}</td><td class="lifecycle-number">{{ money(row.stock_value) }}</td><td class="lifecycle-number">{{ money(row.cumulative_sales) }}</td><td class="lifecycle-number">{{ money(row.cumulative_cogs) }}</td><td class="lifecycle-number" :class="row.cumulative_profit < 0 ? 'lifecycle-negative' : 'lifecycle-positive'">{{ money(row.cumulative_profit) }}</td></tr></tbody></table></div>
              <div v-else class="profit-loss-sheet-status">{{ translate('No movements in the selected period.', language) }}</div>
              <div class="lifecycle-ledger-totals"><span>{{ translate('Cumulative sales', language) }} <strong>{{ money(reportResult.summary.cumulative_sales) }}</strong></span><span>{{ translate('Cumulative COGS', language) }} <strong>{{ money(reportResult.summary.cumulative_cogs) }}</strong></span><span>{{ translate('Cumulative profit', language) }} <strong :class="{ 'lifecycle-negative': reportResult.summary.cumulative_profit < 0 }">{{ money(reportResult.summary.cumulative_profit) }}</strong></span></div>
              <p v-for="note in reportResult.notes || []" :key="note" class="profit-loss-note">{{ translate(note, language) }}</p>
              <footer class="profit-loss-footer"><div class="profit-loss-signatures"><div><span></span><strong>{{ translate('Prepared by:', language) }}</strong></div><div><span></span><strong>{{ translate('Verified by:', language) }}</strong></div><div><span></span><strong>{{ translate('Approved by:', language) }}</strong></div></div><p>{{ reportGeneratedLabel }}</p></footer>
            </template>
            <div v-else class="profit-loss-sheet-status">{{ translate('Select a product to view its ledger.', language) }}</div>
          </section>
          <section v-else-if="reportKind === 'product_ledger'" class="profit-loss-sheet product-ledger-sheet" aria-label="Product wise Profit-Loss">
            <div class="profit-loss-sheet-header"><small>{{ translate('PRODUCT PERFORMANCE', language) }}</small><strong>{{ session.user.organization?.name || translate('Company / store', language) }}</strong><span>{{ translate('Product wise Profit-Loss', language) }} · {{ reportPeriod }} · {{ translate(reportBranchName, language) }}</span></div>
            <div v-if="reportLoading" class="profit-loss-sheet-status">Loading report…</div>
            <div v-else-if="reportResult.rows.length" class="profit-loss-table-wrap"><table class="product-ledger-table"><thead><tr><th>{{ translate('SL', language) }}</th><th>{{ translate('Product name', language) }}</th><th>{{ translate('Quantity (Qty)', language) }}</th><th>{{ translate('Total sales', language) }}</th><th>{{ translate('Costing', language) }}</th><th>{{ translate('Stock profit', language) }}</th><th>{{ translate('Installment profit', language) }}</th><th>{{ translate('Profit / loss for period', language) }}</th></tr></thead><tbody><tr v-for="row in reportResult.rows" :key="row.serial"><td class="product-ledger-serial">{{ qty(row.serial) }}</td><td class="product-ledger-product">{{ row.product }}</td><td class="product-ledger-number">{{ qty(row.quantity) }}</td><td class="product-ledger-number">{{ money(row.total_sales) }}</td><td class="product-ledger-number">{{ money(row.costing) }}</td><td class="product-ledger-number">{{ money(row.stock_profit) }}</td><td class="product-ledger-number">{{ money(row.installment_profit) }}</td><td class="product-ledger-number product-ledger-profit" :class="{ negative: row.period_profit < 0 }">{{ money(row.period_profit) }}</td></tr><tr class="product-ledger-total"><th colspan="2">{{ translate('Total', language) }}</th><td class="product-ledger-number">{{ qty(reportResult.summary.quantity) }}</td><td class="product-ledger-number">{{ money(reportResult.summary.total_sales) }}</td><td class="product-ledger-number">{{ money(reportResult.summary.costing) }}</td><td class="product-ledger-number">{{ money(reportResult.summary.stock_profit) }}</td><td class="product-ledger-number">{{ money(reportResult.summary.installment_profit) }}</td><td class="product-ledger-number">{{ money(reportResult.summary.period_profit) }}</td></tr></tbody></table></div>
            <div v-else class="profit-loss-sheet-status">No product sales found for this period.</div>
            <p v-if="!reportLoading && reportResult.rows.length" class="profit-loss-note">{{ translate('Sales returns reduce quantity, sales and cost. Sales exclude tax; stock profit includes the allocated invoice discount.', language) }}</p>
            <footer v-if="!reportLoading && reportResult.rows.length" class="profit-loss-footer"><div class="profit-loss-signatures"><div><span></span><strong>{{ translate('Prepared by:', language) }}</strong></div><div><span></span><strong>{{ translate('Verified by:', language) }}</strong></div><div><span></span><strong>{{ translate('Approved by:', language) }}</strong></div></div><p>{{ reportGeneratedLabel }}</p></footer>
          </section>
          <section v-else class="profit-loss-sheet standard-report-sheet" :class="{ 'general-ledger-sheet': reportKind === 'general_ledger' }" :aria-label="`${activeReport.label} report`">
            <div class="profit-loss-sheet-header"><small>{{ translate('BUSINESS INSIGHTS', language) }}</small><strong>{{ session.user.organization?.name || translate('Company / store', language) }}</strong><span>{{ translate(activeReport.label, language) }} · {{ standardReportPeriod }} · {{ translate(reportBranchName, language) }}</span></div>
            <div v-if="reportLoading" class="profit-loss-sheet-status">{{ translate('Loading report…', language) }}</div>
            <template v-else>
              <div v-if="Object.keys(reportResult.summary || {}).length" class="standard-report-summary"><div v-for="(value, key) in reportResult.summary" :key="key" class="standard-report-stat"><span>{{ translate(reportSummaryLabel(key), language) }}</span><strong>{{ reportSummaryValue(key, value) }}</strong></div></div>
              <div v-if="reportResult.rows.length" class="profit-loss-table-wrap"><table class="standard-report-table" :class="{ 'stock-report-table': reportKind === 'stock' }" :style="{ minWidth: `${Math.max(reportResult.columns.length * 132, 720)}px` }"><thead><tr><th v-for="(column, index) in reportResult.columns" :key="index" :class="{ 'standard-report-number': /amount|total|paid|due|price|value|sales|collected|debit|credit|balance|cash in|cash out|transfer|quantity|stock|change|reorder|units|on hand|^rate$/i.test(column) && !/status/i.test(column) }">{{ translate(column, language) }}</th></tr></thead><tbody><tr v-for="(row, index) in reportResult.rows" :key="index"><template v-if="reportKind === 'general_ledger'"><td>{{ date(row.date) }}</td><td>{{ row.customer_supplier }}</td><td class="mono">{{ row.reference }}</td><td>{{ translate(row.details, language) }}</td><td class="standard-report-number">{{ money(row.debit) }}</td><td class="standard-report-number">{{ money(row.credit) }}</td><td class="standard-report-number">{{ money(row.balance) }}</td><td><button type="button" class="btn-secondary-app small ledger-source-action" :aria-label="`${row.source_type === 'expense' ? translate('View expense document', language) : row.source_type === 'cash_movement' ? translate('View cash movement', language) : translate('View invoice', language)} ${row.reference}`" @click="openLedgerSource(row)"><Eye :size="15" /> {{ row.source_type === 'expense' ? translate('Expense document', language) : row.source_type === 'cash_movement' ? translate('Cash movement', language) : translate('Invoice', language) }}</button></td></template><template v-else-if="['customer_ledger', 'supplier_ledger'].includes(reportKind)"><td>{{ date(row.date) }}</td><td>{{ row.customer ?? row.supplier }}</td><td><button type="button" class="report-reference-link" :aria-label="`${translate('View invoice', language)} ${row.reference}`" @click="openContactLedgerInvoice(row)">{{ row.reference }}</button></td><td>{{ translate(row.type, language) }}</td><td class="standard-report-number">{{ money(row.debit) }}</td><td class="standard-report-number">{{ money(row.credit) }}</td><td class="standard-report-number">{{ money(row.balance) }}</td></template><td v-else v-for="([key, value], cellIndex) in Object.entries(row)" :key="key" :class="{ 'standard-report-number': /amount|total|paid|due|price|value|sales|collected|debit|credit|balance|cash in|cash out|transfer|quantity|stock|change|reorder|units|on hand|^rate$/i.test(reportResult.columns[cellIndex] || key) && !/status/i.test(reportResult.columns[cellIndex] || key) }">{{ translate(String(reportCell(reportResult.columns[cellIndex] || key, value)), language) }}</td></tr><tr v-if="reportKind === 'stock' && reportResult.totals" class="stock-report-total"><th colspan="3">{{ translate('Total', language) }}</th><td class="standard-report-number">{{ qty(reportResult.totals.purchases) }}</td><td class="standard-report-number">{{ qty(reportResult.totals.sales) }}</td><td class="standard-report-number">{{ qty(reportResult.totals.actual_stock) }}</td><td class="standard-report-number">{{ qty(reportResult.totals.damaged_stock) }}</td><td class="standard-report-number">{{ qty(reportResult.totals.good_stock) }}</td><td class="standard-report-number">—</td><td class="standard-report-number">{{ money(reportResult.totals.total_value) }}</td></tr></tbody></table></div>
              <div v-else class="profit-loss-sheet-status"><strong>{{ translate('No report data', language) }}</strong><span>{{ translate('Try changing the date range or search term.', language) }}</span></div>
              <p v-if="reportResult.note" class="profit-loss-note">{{ translate(reportResult.note, language) }}</p>
              <footer v-if="reportResult.rows.length" class="profit-loss-footer"><div class="profit-loss-signatures"><div><span></span><strong>{{ translate('Prepared by:', language) }}</strong></div><div><span></span><strong>{{ translate('Verified by:', language) }}</strong></div><div><span></span><strong>{{ translate('Approved by:', language) }}</strong></div></div><p>{{ reportGeneratedLabel }}</p></footer>
            </template>
          </section>
          </div>
        </template>
        <template v-if="page === 'admin_dashboard'"><div class="page-heading"><div><span class="eyebrow">SAAS OVERVIEW</span><h1>Company subscriptions</h1><p>Monitor every company, subscription validity and owner account from one place.</p></div><button class="btn-primary-app" @click="openClient()"><Plus :size="18" /> Add company</button></div><div class="stats-grid"><div class="stat-card"><div class="stat-top"><span>Total companies</span><span class="stat-icon violet"><Building2 :size="19" /></span></div><strong>{{ clientStats.total }}</strong><small>Companies using your software</small></div><div class="stat-card"><div class="stat-top"><span>Active subscriptions</span><span class="stat-icon green"><ShieldCheck :size="19" /></span></div><strong>{{ clientStats.active }}</strong><small>Active and trial companies</small></div><div class="stat-card"><div class="stat-top"><span>Expiring in 30 days</span><span class="stat-icon orange"><Clock3 :size="19" /></span></div><strong>{{ clientStats.expiring }}</strong><small>Renewal follow-up required</small></div><div class="stat-card"><div class="stat-top"><span>Needs attention</span><span class="stat-icon orange"><AlertTriangle :size="19" /></span></div><strong>{{ clientStats.attention }}</strong><small>Expired, overdue or suspended</small></div></div><section class="panel table-panel admin-subscription-panel"><div class="table-toolbar"><div><h2>Validity and support queue</h2><p>Expired subscriptions and upcoming renewals appear first.</p></div><button class="text-button" @click="navigate('clients')">Manage all companies <ArrowUpRight :size="16" /></button></div><div class="table-scroll"><table><thead><tr><th>COMPANY</th><th>OWNER</th><th>PLAN</th><th>STATUS</th><th>VALID UNTIL</th><th>USERS</th><th>SUPPORT</th></tr></thead><tbody><tr v-for="client in upcomingClients" :key="client.id"><td><div class="name-cell"><span class="product-avatar contact-avatar">{{ initial(client.name) }}</span><strong>{{ client.name }}</strong></div></td><td><div v-if="client.admins?.length" class="name-cell"><div><strong>{{ client.admins[0].name }}</strong><small>{{ client.admins[0].email || client.admins[0].mobile }}</small></div></div><span v-else>Owner not assigned</span></td><td>{{ client.plan_name || '—' }}</td><td><span class="stock-pill" :class="client.subscription_active ? 'good' : 'low'">{{ clientValidityLabel(client) }}</span></td><td>{{ date(client.subscription_ends_at) }}</td><td>{{ client.users_count }}</td><td><button v-if="client.admins?.some(owner => owner.active)" class="btn-secondary-app small" :disabled="busy" @click="startSupportSession(client)"><Eye :size="15" /> View as owner</button><button v-else class="btn-secondary-app small" @click="openClient(client)"><UserCog :size="15" /> Set up owner</button></td></tr></tbody></table></div><div v-if="!upcomingClients.length" class="empty-state"><ShieldCheck :size="32" /><strong>No renewals need attention</strong><span>Add company validity dates to monitor renewals here.</span></div></section></template>

        <template v-else-if="page === 'roadmap'"><div class="page-heading"><div><span class="eyebrow">PRODUCT ROADMAP</span><h1>Work progress</h1><p>Track the operational modules planned for the inventory platform.</p></div><span class="roadmap-count">{{ roadmapItems.length - roadmapCompleted }} planned modules</span></div><div class="roadmap-summary"><div class="roadmap-summary-copy"><ClipboardList :size="22" /><div><strong>Operational expansion</strong><p>These modules move through planned, in progress, testing and completed stages.</p></div></div><div class="roadmap-progress"><span><strong>{{ roadmapCompleted }}</strong> of {{ roadmapItems.length }} completed</span><div><i :style="{ width: `${(roadmapCompleted / roadmapItems.length) * 100}%` }"></i></div></div></div><section class="roadmap-grid"><article v-for="(item, index) in roadmapItems" :key="item.title" class="roadmap-card"><div class="roadmap-card-top"><span class="roadmap-number">{{ String(index + 1).padStart(2, '0') }}</span><span class="roadmap-status" :class="{ completed: item.status === 'Completed' }">{{ item.status }}</span></div><h2>{{ item.title }}</h2><p>{{ item.description }}</p><div class="roadmap-meta"><span>{{ item.phase }}</span><span :class="`priority-${item.priority.toLowerCase()}`">{{ item.priority }} priority</span></div></article></section></template>

        <template v-else-if="page === 'dashboard'">
          <div class="client-mobile-dashboard">
            <div class="mobile-dashboard-welcome">
              <div><h1><span class="mobile-welcome-hello">Hello,</span> {{ session.user.name.split(' ')[0] }} <span class="mobile-welcome-spark" aria-hidden="true">✦</span></h1><p>{{ session.user.organization?.name || 'Your business' }}</p></div>
              <span class="mobile-dashboard-date">{{ date(today()) }}</span>
            </div>

            <section v-if="canViewDashboardFinances" class="mobile-business-card" aria-label="Business summary">
              <div class="mobile-business-card-top"><span><ChartNoAxesCombined :size="16" /><span>Business overview</span></span><span>All time</span></div>
              <span class="mobile-business-label">Total sales</span>
              <strong class="mobile-business-total">{{ money(overview.sales_total) }}</strong>
              <div class="mobile-business-card-bottom"><div><span>Purchases</span><strong>{{ money(overview.purchase_total) }}</strong></div><div><span>Stock value</span><strong>{{ money(overview.stock_value) }}</strong></div><div><span>Low stock</span><strong>{{ qty(overview.low_stock) }} <span class="mobile-stock-unit">items</span></strong></div></div>
              <div v-if="hasAbility('contacts')" class="mobile-business-dues">
                <span class="mobile-business-dues-scope">Outstanding across accessible branches</span>
                <div class="mobile-business-dues-grid">
                  <button type="button" class="mobile-business-due" aria-label="View customers owing the store" @click="openDueDirectory('customers')"><span>Receivable from customers <ArrowUpRight :size="15" /></span><strong>{{ money(customerDueTotal) }}</strong></button>
                  <button type="button" class="mobile-business-due" aria-label="View suppliers owed by the store" @click="openDueDirectory('suppliers')"><span>Payable to suppliers <ArrowUpRight :size="15" /></span><strong>{{ money(supplierDueTotal) }}</strong></button>
                </div>
              </div>
            </section>
            <section v-else class="mobile-business-card" aria-label="Stock summary">
              <div class="mobile-business-card-top"><span><Boxes :size="16" /><span>Stock overview</span></span><span>Current</span></div>
              <span class="mobile-business-label">Products</span>
              <strong class="mobile-business-total">{{ qty(overview.products) }}</strong>
              <div class="mobile-business-card-bottom mobile-stock-summary"><div><span>Low stock</span><strong>{{ qty(overview.low_stock) }} <span class="mobile-stock-unit">items</span></strong></div></div>
            </section>

            <section v-if="overview.cash" class="mobile-cash-summary" aria-label="My paid balance"><span>{{ language === 'bn' ? 'আমার পরিশোধিত ব্যালেন্স' : 'My paid balance' }}</span><strong>{{ money(overview.cash.on_hand) }}</strong></section>

            <section v-if="hasAbility('sales', 'pos_sale', 'purchases', 'products')" class="mobile-dashboard-section" aria-labelledby="mobile-quick-actions-title">
              <div class="mobile-section-heading"><div><span class="mobile-section-rule"></span><h2 id="mobile-quick-actions-title">Quick actions</h2></div></div>
              <div class="mobile-primary-actions">
                <button v-if="hasAbility('sales')" type="button" class="mobile-primary-action mobile-action-sale" :disabled="busy || !activeBranchId" @click="openDocument('sale')"><span class="mobile-action-icon"><ShoppingCart :size="21" /></span><strong>New Sale</strong></button>
                <button v-if="hasAbility('pos_sale')" type="button" class="mobile-primary-action mobile-action-pos" :disabled="busy || !activeBranchId" @click="navigate('pos')"><span class="mobile-action-icon"><ScanLine :size="21" /></span><strong>POS Sale</strong></button>
                <button v-if="hasAbility('purchases')" type="button" class="mobile-primary-action mobile-action-purchase" :disabled="busy || !activeBranchId" @click="openDocument('purchase')"><span class="mobile-action-icon"><ShoppingBag :size="21" /></span><strong>New Purchase</strong></button>
                <button v-if="hasAbility('products')" type="button" class="mobile-primary-action mobile-action-product" :disabled="busy" @click="openProduct()"><span class="mobile-action-icon"><Package :size="21" /></span><strong>Add product</strong></button>
              </div>
            </section>

            <section v-if="hasAbility('sales', 'purchases', 'inventory', 'stock_adjustments', 'contacts', 'stock_checks') || session.permissions.reports?.length" class="mobile-dashboard-section" aria-labelledby="mobile-explore-title">
              <div class="mobile-section-heading"><div><span class="mobile-section-rule"></span><h2 id="mobile-explore-title">Manage your store</h2></div></div>
              <div class="mobile-shortcut-grid">
                <button v-if="hasAbility('sales')" type="button" class="mobile-shortcut" @click="navigate('sale')"><span class="mobile-shortcut-icon violet"><ReceiptText :size="21" /></span><strong>Sales</strong></button>
                <button v-if="hasAbility('purchases')" type="button" class="mobile-shortcut" @click="navigate('purchase')"><span class="mobile-shortcut-icon orange"><ShoppingBag :size="21" /></span><strong>Purchases</strong></button>
                <button v-if="hasAbility('inventory', 'stock_adjustments')" type="button" class="mobile-shortcut" @click="navigate('inventory')"><span class="mobile-shortcut-icon green"><Boxes :size="21" /></span><strong>Inventory</strong></button>
                <button v-if="hasAbility('contacts')" type="button" class="mobile-shortcut" @click="navigate('customers')"><span class="mobile-shortcut-icon blue"><Users :size="21" /></span><strong>Customers</strong></button>
                <button v-if="hasAbility('stock_checks')" type="button" class="mobile-shortcut" @click="navigate('stock_checks')"><span class="mobile-shortcut-icon green"><ClipboardCheck :size="21" /></span><strong>Stock Check</strong></button>
                <button v-if="session.permissions.reports?.length" type="button" class="mobile-shortcut" @click="navigate('reports')"><span class="mobile-shortcut-icon slate"><ChartNoAxesCombined :size="21" /></span><strong>Reports</strong></button>
              </div>
            </section>

            <section class="mobile-dashboard-section mobile-activity-section" aria-labelledby="mobile-activity-title">
              <div class="mobile-section-heading"><div><span class="mobile-section-rule"></span><h2 id="mobile-activity-title">Recent transactions</h2></div><button v-if="hasAbility('sales')" type="button" @click="navigate('sale')">View sales <ArrowUpRight :size="15" /></button></div>
              <div class="mobile-activity-card"><div v-if="overview.recent_documents?.length" class="mobile-activity-list"><div v-for="doc in overview.recent_documents.slice(0, 3)" :key="doc.id" class="mobile-activity-row"><span class="mobile-activity-icon" :class="doc.type"><ArrowDownRight v-if="doc.type === 'purchase'" :size="18" /><ArrowUpRight v-else :size="18" /></span><div><strong>{{ doc.contact?.name || doc.number }}</strong><small>{{ label(doc.type) }} · {{ date(doc.document_date) }}</small></div><b v-if="canViewDashboardFinances">{{ money(doc.total) }}</b></div></div><div v-else class="mobile-activity-empty"><Clock3 :size="25" /><strong>No transactions yet</strong><span>Create your first sale or purchase to see activity here.</span></div></div>
            </section>
          </div>
          <div class="desktop-dashboard"><div class="page-heading"><div><span class="eyebrow">OVERVIEW</span><h1>Good to see you, {{ session.user.name.split(' ')[0] }} <span class="wave">✦</span></h1><p>Here is what is happening across your inventory today.</p></div></div>
          <div v-if="hasAbility('sales') || hasAbility('purchases')" class="dashboard-quick-actions"><button v-if="hasAbility('purchases')" class="dashboard-action-card purchase-action" @click="openDocument('purchase')"><span class="dashboard-action-icon"><ShoppingBag :size="22" /></span><span class="dashboard-action-copy"><strong>New Purchase</strong><small>Record stock and supplier payment terms</small></span><span class="dashboard-action-arrow"><ArrowUpRight :size="18" /></span></button><button v-if="hasAbility('sales')" class="dashboard-action-card sales-action" @click="openDocument('sale')"><span class="dashboard-action-icon"><ShoppingCart :size="22" /></span><span class="dashboard-action-copy"><strong>New Sale</strong><small>Sell products and record customer payment</small></span><span class="dashboard-action-arrow"><ArrowUpRight :size="18" /></span></button></div>
          <div class="stats-grid"><div v-if="canViewDashboardFinances" class="stat-card"><div class="stat-top"><span>Total sales</span><span class="stat-icon violet"><ShoppingCart :size="19" /></span></div><strong>{{ money(overview.sales_total) }}</strong><small>Sales and resales</small></div><div v-if="canViewDashboardFinances" class="stat-card"><div class="stat-top"><span>Purchases</span><span class="stat-icon blue"><ShoppingBag :size="19" /></span></div><strong>{{ money(overview.purchase_total) }}</strong><small>All recorded purchases</small></div><div v-if="canViewDashboardFinances" class="stat-card"><div class="stat-top"><span>Stock value</span><span class="stat-icon green"><Boxes :size="19" /></span></div><strong>{{ money(overview.stock_value) }}</strong><small>At product cost price</small></div><div v-else class="stat-card"><div class="stat-top"><span>Products</span><span class="stat-icon green"><Boxes :size="19" /></span></div><strong>{{ qty(overview.products) }}</strong><small>Products in your inventory</small></div><div class="stat-card"><div class="stat-top"><span>Low stock</span><span class="stat-icon orange"><AlertTriangle :size="19" /></span></div><strong>{{ overview.low_stock }}</strong><small>Products at reorder level</small></div><div v-if="overview.cash" class="stat-card"><div class="stat-top"><span>{{ language === 'bn' ? 'আমার পরিশোধিত ব্যালেন্স' : 'My paid balance' }}</span><span class="stat-icon violet"><Banknote :size="19" /></span></div><strong>{{ money(overview.cash.on_hand) }}</strong><small>{{ language === 'bn' ? 'নির্বাচিত শাখা' : 'Selected branch' }}</small></div></div>
          <div class="dashboard-analytics-grid" :class="{ 'dashboard-analytics-single': !canViewDashboardFinances }"><section v-if="canViewDashboardFinances" class="panel analytics-panel"><div class="panel-heading"><div><h2>Sales vs purchases</h2><p>Monthly totals for {{ new Date().getFullYear() }}</p></div><button v-if="hasAbility('sales', 'purchases')" class="text-button" @click="navigate('reports')">Open reports <ArrowUpRight :size="16" /></button></div><div class="chart-summary"><span><i class="sales-dot"></i>Sales <strong>{{ money(dashboardAnnualSales) }}</strong></span><span><i class="purchase-dot"></i>Purchases <strong>{{ money(dashboardAnnualPurchases) }}</strong></span></div>
          <div class="monthly-chart">
            <div class="monthly-chart-plot" :style="{ '--zero-bottom': dashboardZeroBottom + '%' }">
              <span class="chart-guide guide-quarter"></span><span class="chart-guide guide-half"></span><span class="chart-guide guide-three-quarter"></span>
              <div v-for="month in dashboardMonthlyTotals" :key="month.month" class="month-chart-column">
                <div class="month-chart-bars">
                  <span v-if="Number(month.sales)" class="month-bar sales-bar" :class="{ negative: Number(month.sales) < 0 }" :data-tooltip="'Sales: ' + money(month.sales)" :aria-label="'Sales: ' + money(month.sales)" :style="monthlyBarStyle(month.sales)" role="img" tabindex="0"></span>
                  <span v-if="Number(month.purchases)" class="month-bar purchase-bar" :class="{ negative: Number(month.purchases) < 0 }" :data-tooltip="'Purchases: ' + money(month.purchases)" :aria-label="'Purchases: ' + money(month.purchases)" :style="monthlyBarStyle(month.purchases)" role="img" tabindex="0"></span>
                </div>
              </div>
            </div>
            <div class="month-chart-labels"><small v-for="month in dashboardMonthlyTotals" :key="month.month" :class="{ active: Number(month.sales) || Number(month.purchases) }">{{ month.month }}</small></div>
          </div>
          <div v-if="dashboardActiveMonths.length" class="chart-monthly-values" aria-label="Monthly sales and purchases">
            <div v-for="month in dashboardActiveMonths" :key="month.month" class="chart-monthly-value-row">
              <strong>{{ month.month }}</strong><span><i class="sales-dot"></i>Sales <b>{{ money(month.sales) }}</b></span><span><i class="purchase-dot"></i>Purchases <b>{{ money(month.purchases) }}</b></span>
            </div>
          </div>
          <p v-else class="chart-empty-note">No sales or purchases recorded this year.</p></section>
          <section class="panel analytics-panel product-rankings-panel">
            <div class="panel-heading"><div><h2>Product sales ranking</h2><p>Net units sold this year · Unsold products appear in lowest selling</p></div><Package :size="19" class="analytics-heading-icon" /></div>
            <div v-if="overview.highest_selling_products?.length || overview.lowest_selling_products?.length" class="product-rankings">
              <div v-if="overview.highest_selling_products?.length" class="product-ranking-group">
                <h3><ArrowUpRight :size="16" /> Highest selling</h3>
                <div v-for="(product, index) in overview.highest_selling_products" :key="product.id" class="product-ranking-row">
                  <span class="product-ranking-position highest">{{ index + 1 }}</span><strong :title="product.name">{{ product.name }}</strong><span>{{ qty(product.quantity) }} {{ product.unit }}</span>
                </div>
              </div>
              <div class="product-ranking-group">
                <h3><ArrowDownRight :size="16" /> Lowest selling <small>(unsold first)</small></h3>
                <div v-for="(product, index) in overview.lowest_selling_products" :key="product.id" class="product-ranking-row">
                  <span class="product-ranking-position lowest">{{ index + 1 }}</span><strong :title="product.name">{{ product.name }}</strong><span>{{ Number(product.quantity) ? `${qty(product.quantity)} ${product.unit}` : '0 sold' }}</span>
                </div>
                <p v-if="!overview.lowest_selling_products?.length" class="product-ranking-empty">No other products to compare.</p>
              </div>
            </div>
            <div v-else class="empty-state compact"><Package :size="27" /><strong>No products yet</strong><span>Add products to see sales rankings here.</span></div>
          </section></div>
          <div class="dashboard-grid"><section class="panel"><div class="panel-heading"><div><h2>Recent transactions</h2><p>Your latest purchases, sales and returns</p></div><button v-if="hasAbility('sales')" class="text-button" @click="navigate('sale')">View sales <ArrowUpRight :size="16" /></button></div><div v-if="overview.recent_documents.length" class="transaction-list"><div v-for="doc in overview.recent_documents" :key="doc.id" class="transaction-row"><span class="transaction-icon" :class="doc.type"><ArrowDownRight v-if="doc.type === 'purchase'" :size="18" /><ArrowUpRight v-else :size="18" /></span><div class="transaction-title"><strong>{{ doc.number }}</strong><span>{{ doc.contact?.name }} · {{ label(doc.type) }}</span></div><div class="transaction-right"><strong v-if="canViewDashboardFinances">{{ money(doc.total) }}</strong><span>{{ date(doc.document_date) }}</span></div></div></div><div v-else class="empty-state compact"><Clock3 :size="29" /><strong>No transactions yet</strong><span>Create your first purchase to bring stock in.</span></div></section><section class="panel"><div class="panel-heading"><div><h2>Stock alerts</h2><p>Items that may need attention</p></div><button v-if="hasAbility('inventory', 'stock_adjustments')" class="text-button" @click="navigate('inventory')">Inventory <ArrowUpRight :size="16" /></button></div><div v-if="overview.low_stock_products.length" class="alert-list"><div v-for="product in overview.low_stock_products" :key="product.id" class="alert-row"><span class="product-avatar">{{ initial(product.name) }}</span><div><strong>{{ product.name }}</strong><span>{{ product.sku || 'No SKU' }}</span></div><span class="stock-pill low">{{ qty(product.quantity_on_hand) }} {{ product.unit }}</span></div></div><div v-else class="empty-state compact"><Check :size="29" /><strong>All stocked up</strong><span>No products are at their reorder level.</span></div></section></div>
          <div v-if="canViewDashboardFinances" class="dashboard-collections-grid">
            <section v-if="hasAbility('purchases')" class="panel collection-alert-panel"><div class="panel-heading"><div><h2>Supplier due list</h2><p>Unsettled purchase balances for this branch</p></div><span class="stock-pill pending">{{ overview.supplier_due_list?.length || 0 }} open</span></div><div v-if="overview.supplier_due_list?.length" class="collection-alert-list"><article v-for="due in overview.supplier_due_list" :key="due.id" class="collection-alert-row"><span class="collection-alert-icon upcoming"><Banknote :size="17" /></span><div class="collection-alert-copy"><strong>{{ due.supplier || 'Supplier' }} · {{ due.number }}</strong><span>{{ date(due.date) }}</span></div><strong class="collection-alert-amount">{{ money(due.amount_due) }}</strong><button class="btn-secondary-app small" @click="openPurchasePayment(due)">Settle</button></article></div><div v-else class="empty-state compact"><Check :size="29" /><strong>No supplier balances due</strong><span>All recorded purchases are settled.</span></div></section>
            <section v-if="hasAbility('sales', 'resales')" class="panel collection-alert-panel"><div class="panel-heading"><div><h2>Customer due list</h2><p>Customers with the highest outstanding balances first</p></div><span class="stock-pill pending">{{ overview.customer_due_list?.length || 0 }} customers</span></div><div v-if="overview.customer_due_list?.length" class="collection-alert-list"><article v-for="due in overview.customer_due_list" :key="due.contact_id || due.customer" class="collection-alert-row"><span class="collection-alert-icon overdue"><Users :size="17" /></span><div class="collection-alert-copy"><strong>{{ due.customer }}</strong><span>{{ due.invoices_count }} unpaid invoices · {{ due.phone || 'No phone' }}</span></div><strong class="collection-alert-amount">{{ money(due.amount_due) }}</strong><button class="btn-primary-app small" @click="openCustomerCollection(due)">Collect</button></article></div><div v-else class="empty-state compact"><Check :size="29" /><strong>No customer balances due</strong><span>All customer sales and resales are settled.</span></div></section>
            <section v-if="hasAbility('sales', 'resales')" class="panel collection-alert-panel"><div class="panel-heading"><div><h2>Overdue customer balances</h2><p>Unpaid sales invoices outstanding for at least 30 days</p></div><span class="stock-pill low">{{ overview.overdue_customers?.length || 0 }} aged</span></div><div v-if="overview.overdue_customers?.length" class="collection-alert-list"><article v-for="due in overview.overdue_customers" :key="due.id" class="collection-alert-row"><span class="collection-alert-icon overdue"><AlertTriangle :size="17" /></span><div class="collection-alert-copy"><strong>{{ due.customer || 'Customer' }} · {{ due.number }}</strong><span>{{ due.days_outstanding }} days outstanding · {{ due.phone || 'No phone' }}</span></div><strong class="collection-alert-amount">{{ money(due.amount_due) }}</strong><button class="btn-secondary-app small" @click="navigate('sale')">View</button></article></div><div v-else class="empty-state compact"><Check :size="29" /><strong>No aged customer balances</strong><span>No unpaid sale invoices have reached 30 days.</span></div></section>
            <section v-if="hasAbility('sales', 'resales')" class="panel collection-alert-panel"><div class="panel-heading"><div><h2>Installments due next 7 days</h2><p>Scheduled customer payments at this branch</p></div><span class="stock-pill pending">{{ upcomingInstallments.length }} due</span></div><div v-if="upcomingInstallments.length" class="collection-alert-list"><article v-for="alert in upcomingInstallments" :key="alert.id" class="collection-alert-row"><span class="collection-alert-icon upcoming"><Clock3 :size="17" /></span><div class="collection-alert-copy"><strong>{{ alert.document.contact?.name || 'Customer' }} · {{ alert.document.number }}</strong><span>{{ alert.days_until_due === 0 ? 'Due today' : `Due in ${alert.days_until_due} days` }} · {{ date(alert.due_date) }}</span></div><strong class="collection-alert-amount">{{ money(alert.amount_due) }}</strong><button class="btn-secondary-app small" @click="openCollectionSale(alert)">View sale</button></article></div><div v-else class="empty-state compact"><Check :size="29" /><strong>No installments due soon</strong><span>No unpaid installments fall within the next 7 days.</span></div></section>
            <section v-if="hasAbility('sales', 'resales') && overdueInstallments.length" class="panel collection-alert-panel"><div class="panel-heading"><div><h2>Overdue installments</h2><p>Past-due scheduled customer payments</p></div><span class="stock-pill low">{{ overdueInstallments.length }} overdue</span></div><div class="collection-alert-list"><article v-for="alert in overdueInstallments" :key="alert.id" class="collection-alert-row"><span class="collection-alert-icon overdue"><AlertTriangle :size="17" /></span><div class="collection-alert-copy"><strong>{{ alert.document.contact?.name || 'Customer' }} · {{ alert.document.number }}</strong><span>{{ Math.abs(alert.days_until_due) }} days overdue · {{ date(alert.due_date) }}</span></div><strong class="collection-alert-amount">{{ money(alert.amount_due) }}</strong><button class="btn-secondary-app small" @click="openCollectionSale(alert)">View sale</button></article></div></section>
          </div></div>
          <section v-if="overview.cash" class="panel dashboard-cash-panel" aria-label="Paid balance and cash activity">
            <div class="panel-heading"><div><h2>{{ language === 'bn' ? 'পরিশোধিত ব্যালেন্স' : 'Paid balance' }}</h2><p>{{ language === 'bn' ? 'পরিশোধিত ব্যালেন্স দিয়ে হস্তান্তর ও উত্তোলন হিসাব করা হয়; নগদ গ্রহণ ও প্রদান আলাদা দেখানো হয়েছে' : 'Transfers and withdrawals use customer payments; physical cash receipts and payments are shown separately' }}</p></div><span class="stat-icon violet"><Banknote :size="20" /></span></div>
            <div class="dashboard-cash-cards">
              <div class="dashboard-cash-card primary"><span>{{ language === 'bn' ? 'আমার পরিশোধিত ব্যালেন্স' : 'My paid balance' }}</span><strong>{{ money(overview.cash.on_hand) }}</strong></div>
              <div class="dashboard-cash-card"><span>{{ language === 'bn' ? 'নগদ গ্রহণ' : 'Cash collected' }}</span><strong>{{ money(overview.cash.cash_received) }}</strong></div>
              <div class="dashboard-cash-card"><span>{{ language === 'bn' ? 'নগদ প্রদান' : 'Cash paid' }}</span><strong>{{ money(overview.cash.cash_paid) }}</strong></div>
              <div v-if="canViewDashboardFinances" class="dashboard-cash-card"><span>{{ language === 'bn' ? 'কর্মীদের কাছ থেকে প্রাপ্ত' : 'Received from staff' }}</span><strong>{{ money(overview.cash.received_transfers) }}</strong></div>
              <div v-if="canViewDashboardFinances" class="dashboard-cash-card"><span>{{ language === 'bn' ? 'মালিকের উত্তোলন' : 'Owner withdrawals' }}</span><strong>{{ money(overview.cash.withdrawn) }}</strong></div>
              <div v-else class="dashboard-cash-card"><span>{{ language === 'bn' ? 'মালিককে হস্তান্তর' : 'Transferred to owner' }}</span><strong>{{ money(overview.cash.transferred) }}</strong></div>
            </div>
            <div class="dashboard-cash-actions"><button v-if="!canViewDashboardFinances" type="button" class="btn-primary-app" :disabled="busy || Number(overview.cash.on_hand) <= 0" @click="cashAction = 'transfer'">{{ language === 'bn' ? 'পুরো পরিশোধিত ব্যালেন্স মালিককে দিন' : 'Transfer all paid balance to owner' }}</button><button v-else type="button" class="btn-primary-app" :disabled="busy || Number(overview.cash.on_hand) <= 0" @click="cashAction = 'withdraw'">{{ language === 'bn' ? 'পরিশোধিত ব্যালেন্স উত্তোলন' : 'Withdraw paid balance' }}</button></div>
            <form v-if="cashAction" class="dashboard-cash-form" @submit.prevent="saveCashMovement"><p v-if="cashAction === 'transfer'">{{ language === 'bn' ? 'আপনার সম্পূর্ণ পরিশোধিত ব্যালেন্স মালিকের হিসাবে যাবে।' : 'Your entire paid balance will move to the store owner.' }} <strong>{{ money(overview.cash.on_hand) }}</strong></p><label v-else class="field"><span>{{ language === 'bn' ? 'উত্তোলনের পরিমাণ' : 'Withdrawal amount' }} *</span><input v-model="cashWithdrawalAmount" type="number" min="0.01" :max="overview.cash.on_hand" step="0.01" required></label><label class="field"><span>{{ language === 'bn' ? 'নোট' : 'Notes' }}</span><input v-model.trim="cashNotes" maxlength="500" :placeholder="language === 'bn' ? 'ঐচ্ছিক বিবরণ' : 'Optional reference'"></label><div class="dashboard-cash-form-actions"><button type="button" class="btn-secondary-app" @click="cashAction = ''">{{ language === 'bn' ? 'বাতিল' : 'Cancel' }}</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Saving...' : cashAction === 'transfer' ? (language === 'bn' ? 'হস্তান্তর নিশ্চিত করুন' : 'Confirm transfer') : (language === 'bn' ? 'উত্তোলন নিশ্চিত করুন' : 'Confirm withdrawal') }}</button></div></form>
            <div v-if="canViewDashboardFinances && overview.cash.staff_balances?.length" class="dashboard-cash-staff"><h3>{{ language === 'bn' ? 'কর্মীদের পরিশোধিত ব্যালেন্স' : 'Staff paid balances' }}</h3><div v-for="staffCash in overview.cash.staff_balances" :key="staffCash.id"><span>{{ staffCash.name }}</span><strong>{{ money(staffCash.on_hand) }}</strong></div></div>
            <div v-if="overview.cash.recent_movements?.length" class="dashboard-cash-history"><h3>{{ language === 'bn' ? 'সাম্প্রতিক নগদ চলাচল' : 'Recent cash movements' }}</h3><div v-for="movement in overview.cash.recent_movements" :key="movement.id"><span>{{ movement.type === 'transfer' ? (language === 'bn' ? 'হস্তান্তর' : 'Transfer') : (language === 'bn' ? 'উত্তোলন' : 'Withdrawal') }} · {{ movement.sender?.name }}<template v-if="movement.recipient"> → {{ movement.recipient.name }}</template><small>{{ date(movement.created_at) }}<template v-if="movement.notes"> · {{ movement.notes }}</template></small></span><strong>{{ money(movement.amount) }}</strong></div></div>
          </section>
        </template>

        <template v-else-if="page === 'products'"><div class="page-heading"><div><span class="eyebrow">CATALOG</span><h1>Products</h1><p>Manage products, categories, barcodes and reorder levels.</p></div><button class="btn-primary-app" @click="openProduct()"><Plus :size="18" /> Add product</button></div><section class="panel table-panel"><div class="table-toolbar"><div><h2>Product catalog</h2><p>{{ products.length }} products</p></div><div class="search-box"><Search :size="17" /><input v-model="search" placeholder="Search products, category, SKU or barcode..."></div></div><div class="table-scroll"><table><thead><tr><th>PRODUCT</th><th>SKU</th><th>BARCODE</th><th>CATEGORY</th><th>ON HAND</th><th>AVG. COST</th><th>DEFAULT SALE PRICE</th><th></th></tr></thead><tbody><tr v-for="product in filteredProducts" :key="product.id"><td><div class="name-cell"><span class="product-avatar">{{ initial(product.name) }}</span><div><strong>{{ product.name }}</strong><small>{{ product.unit }} <span v-if="product.stock_source">· {{ product.stock_source.name }} থেকে মজুদ</span> <span v-if="!product.active">· Inactive</span></small></div></div></td><td class="mono">{{ product.sku || '—' }}</td><td class="mono">{{ product.barcode || 'Not generated' }}</td><td>{{ product.category?.name || 'Uncategorized' }}</td><td><div class="product-stock-balance"><span class="stock-pill" :class="lowStock(product) ? 'low' : 'good'">{{ qty(product.stock_source_product_id ? product.source_quantity_on_hand : product.quantity_on_hand) }} {{ product.stock_source_product_id ? product.stock_source?.unit : product.unit }}<small v-if="product.stock_source_product_id"> · shared</small></span><small v-if="product.stock_source_product_id">Sellable: {{ qty(product.available_quantity) }} {{ product.unit }} · Per sale: {{ qty(product.stock_source_quantity) }} {{ product.stock_source?.unit }}</small></div></td><td>{{ money(product.stock_source_product_id ? Number(products.find(source => source.id === Number(product.stock_source_product_id))?.cost_price || 0) * Number(product.stock_source_quantity || 1) : product.cost_price) }}</td><td>{{ money(product.sale_price) }}</td><td><div class="row-actions"><button class="icon-button" :title="product.barcode ? 'View and print barcode' : 'Generate barcode'" @click="openBarcode(product)"><Barcode :size="18" /></button><button class="icon-button" title="Edit product" @click="openProduct(product)"><Pencil :size="17" /></button><button v-if="canDeleteCatalogRecords" class="icon-button" type="button" title="Delete product" :disabled="busy" @click="deleteProduct(product)"><Trash2 :size="17" /></button></div></td></tr></tbody></table></div><div v-if="!filteredProducts.length" class="empty-state"><Package :size="32" /><strong>No products found</strong><span>Add a product or change your search.</span></div></section></template>

        <template v-else-if="page === 'categories'"><div class="page-heading"><div><span class="eyebrow">CATALOG SETUP</span><h1>Categories</h1><p>Organize products into reusable groups for faster searching and reporting.</p></div><button class="btn-primary-app" @click="openCategory()"><Plus :size="18" /> Add category</button></div><div class="category-stats"><div class="stat-card"><div class="stat-top"><span>Total categories</span><span class="stat-icon violet"><Tags :size="19" /></span></div><strong>{{ categories.length }}</strong><small>Available product groups</small></div><div class="stat-card"><div class="stat-top"><span>Active categories</span><span class="stat-icon green"><Check :size="19" /></span></div><strong>{{ categories.filter(category => category.active).length }}</strong><small>Visible when assigning products</small></div><div class="stat-card"><div class="stat-top"><span>Categorized products</span><span class="stat-icon blue"><Package :size="19" /></span></div><strong>{{ categories.reduce((total, category) => total + Number(category.products_count || 0), 0) }}</strong><small>Products assigned to a category</small></div></div><section class="panel table-panel category-table"><div class="table-toolbar"><div><h2>Category list</h2><p>{{ filteredCategories.length }} of {{ categories.length }} categories</p></div><div class="search-box"><Search :size="17" /><input v-model="search" placeholder="Search categories..."></div></div><div class="table-scroll"><table><thead><tr><th>CATEGORY</th><th>DESCRIPTION</th><th>PRODUCTS</th><th>STATUS</th><th></th></tr></thead><tbody><tr v-for="category in filteredCategories" :key="category.id"><td><div class="name-cell"><span class="product-avatar category-avatar"><Tags :size="16" /></span><strong>{{ category.name }}</strong></div></td><td class="category-description">{{ category.description || '—' }}</td><td><strong>{{ category.products_count }}</strong> products</td><td><span class="stock-pill" :class="category.active ? 'good' : 'low'">{{ category.active ? 'Active' : 'Inactive' }}</span></td><td><div class="row-actions"><button class="icon-button" title="Edit category" @click="openCategory(category)"><Pencil :size="17" /></button><button class="icon-button" title="Delete unused category" :disabled="category.products_count > 0 || busy" @click="deleteCategory(category)"><Trash2 :size="17" /></button></div></td></tr></tbody></table></div><div v-if="!filteredCategories.length" class="empty-state"><Tags :size="32" /><strong>No categories found</strong><span>Create a category to start organizing products.</span></div></section></template>

        <template v-else-if="page === 'branches'"><div class="page-heading"><div><span class="eyebrow">COMPANY SETUP</span><h1>Branches</h1><p>Maintain every store or location and keep its stock and transactions separate.</p></div><span class="branch-management-note">New branches are added by the superadmin.</span></div><div class="category-stats"><div class="stat-card"><div class="stat-top"><span>Total branches</span><span class="stat-icon violet"><MapPin :size="19" /></span></div><strong>{{ branches.length }}</strong><small>Company locations</small></div><div class="stat-card"><div class="stat-top"><span>Active branches</span><span class="stat-icon green"><Check :size="19" /></span></div><strong>{{ branches.filter(branch => branch.active).length }}</strong><small>Available for transactions</small></div><div class="stat-card"><div class="stat-top"><span>Total branch stock</span><span class="stat-icon blue"><Boxes :size="19" /></span></div><strong>{{ qty(branches.reduce((total, branch) => total + Number(branch.stocks_sum_quantity_on_hand || 0), 0)) }}</strong><small>Units across all locations</small></div></div><section class="panel table-panel"><div class="table-toolbar"><div><h2>Company branches</h2><p>Select the working branch from the top navigation.</p></div></div><div class="table-scroll"><table><thead><tr><th>BRANCH</th><th>CODE</th><th>CONTACT</th><th>STOCK</th><th>TRANSACTIONS</th><th>STATUS</th><th></th></tr></thead><tbody><tr v-for="branch in branches" :key="branch.id"><td><div class="name-cell"><span class="product-avatar category-avatar"><MapPin :size="16" /></span><div><strong>{{ branch.name }}</strong><small>{{ branch.address || 'No address' }}</small></div></div></td><td class="mono">{{ branch.code }}</td><td>{{ branch.phone || '—' }}</td><td>{{ qty(branch.stocks_sum_quantity_on_hand) }}</td><td>{{ branch.documents_count }}</td><td><div class="branch-status"><span class="stock-pill" :class="branch.active ? 'good' : 'low'">{{ branch.active ? 'Active' : 'Inactive' }}</span><span v-if="branch.is_default" class="type-pill">Default</span><span v-if="branch.id === Number(activeBranchId)" class="type-pill current">Current</span></div></td><td><div class="row-actions"><button class="icon-button" title="Edit branch" @click="openBranch(branch)"><Pencil :size="17" /></button></div></td></tr></tbody></table></div></section></template>

        <template v-else-if="['customers', 'suppliers'].includes(page)"><div class="page-heading"><div><span class="eyebrow">CONTACT DIRECTORY</span><h1>{{ page === 'customers' ? 'Customers' : 'Suppliers' }}</h1><p>{{ page === 'customers' ? 'Track customer profiles, sales, paid amounts and outstanding credit.' : 'Track supplier profiles, purchases, payments and payable balances.' }}</p></div><button class="btn-primary-app" @click="openContact()"><Plus :size="18" /> Add {{ page === 'customers' ? 'customer' : 'supplier' }}</button></div><div class="category-stats directory-stats"><div class="stat-card"><div class="stat-top"><span>Total {{ page }}</span><span class="stat-icon violet"><Users :size="19" /></span></div><strong>{{ directoryContacts.length }}</strong><small>Active and inactive directory records</small></div><div class="stat-card"><div class="stat-top"><span>{{ page === 'customers' ? 'Net sales' : 'Net purchases' }}</span><span class="stat-icon blue"><ReceiptText :size="19" /></span></div><strong>{{ money(directoryTotals.amount) }}</strong><small>Transaction value after returns</small></div><div class="stat-card"><div class="stat-top"><span>Outstanding balance</span><span class="stat-icon orange"><Banknote :size="19" /></span></div><strong>{{ money(directoryTotals.balance) }}</strong><small>{{ page === 'customers' ? 'Amount customers still owe' : 'Amount still payable to suppliers' }}</small></div></div><section class="panel table-panel directory-table"><div class="table-toolbar"><div><h2>{{ page === 'customers' ? 'Customer accounts' : 'Supplier accounts' }}</h2><p>{{ directoryContacts.length }} records · financial totals are calculated from saved transactions.</p></div><div class="search-box"><Search :size="17" /><input v-model="search" :placeholder="`Search ${page}...`"></div></div><div class="table-scroll"><table><thead><tr><th>SL</th><th>NAME</th><th v-if="page === 'suppliers'">COMPANY</th><th>MOBILE</th><th>ADDRESS</th><th v-if="page === 'customers'">AREA</th><th v-if="page === 'customers'">CATEGORY</th><th>{{ page === 'customers' ? 'SALES' : 'PURCHASE' }}</th><th>PAID</th><th>BALANCE</th><th v-if="page === 'customers'">CREDIT LIMIT</th><th>STATUS</th><th></th></tr></thead><tbody><tr v-for="(contact, index) in directoryContacts" :key="contact.id"><td class="mono strong">{{ index + 1 }}</td><td><div class="name-cell"><span class="product-avatar contact-avatar">{{ initial(contact.name) }}</span><div><strong>{{ contact.name }}</strong><small v-if="page === 'customers' && contact.company">{{ contact.company }}</small></div></div></td><td v-if="page === 'suppliers'">{{ contact.company || '—' }}</td><td>{{ contact.phone || '—' }}</td><td><div class="directory-address"><strong>{{ contact.address || '—' }}</strong><small>Added {{ date(contact.created_at) }}</small></div></td><td v-if="page === 'customers'">{{ contact.area || '—' }}</td><td v-if="page === 'customers'"><span class="type-pill">{{ contact.category || 'General' }}</span></td><td class="strong">{{ money(contact.sales_total || contact.purchases_total) }}</td><td>{{ money(contact.paid_total) }}</td><td :class="Number(contact.balance_total) > 0 ? 'directory-balance due' : 'directory-balance'">{{ money(contact.balance_total) }}</td><td v-if="page === 'customers'">{{ money(contact.credit_limit) }}</td><td><span class="stock-pill" :class="contact.active ? 'good' : 'low'">{{ contact.active ? 'Active' : 'Inactive' }}</span></td><td><div class="row-actions"><button class="icon-button" title="Edit contact" @click="openContact(contact)"><Pencil :size="17" /></button><button v-if="canDeleteCatalogRecords && !contact.is_default" class="icon-button" type="button" :title="page === 'customers' ? 'Delete customer' : 'Delete supplier'" :disabled="busy" @click="deleteContact(contact)"><Trash2 :size="17" /></button></div></td></tr></tbody><tfoot v-if="directoryContacts.length"><tr><td :colspan="page === 'customers' ? 7 : 6"><strong>Total ({{ directoryContacts.length }})</strong></td><td class="strong">{{ money(directoryTotals.amount) }}</td><td class="strong">{{ money(directoryTotals.paid) }}</td><td class="strong">{{ money(directoryTotals.balance) }}</td><td :colspan="page === 'customers' ? 3 : 2"></td></tr></tfoot></table></div><div v-if="!directoryContacts.length" class="empty-state"><Users :size="32" /><strong>No {{ page }} found</strong><span>Add a {{ page === 'customers' ? 'customer' : 'supplier' }} or change the search.</span></div></section></template>

        <template v-else-if="page === 'pos'">
          <div class="page-heading pos-heading"><div><span class="eyebrow">FAST CHECKOUT</span><h1>POS Sale</h1><p>Select products, collect payment and issue an invoice from one screen.</p></div><button class="btn-secondary-app" :disabled="!posForm.items.length" @click="resetPos"><Trash2 :size="16" /> Clear cart</button></div>
          <div v-if="error" class="alert-box error">{{ error }}</div>
          <div class="pos-layout">
            <section class="panel pos-catalog">
              <form class="pos-scanner" @submit.prevent="scanPosBarcode"><ScanLine :size="22" /><div><strong>Scan product barcode</strong><span>Keep this field focused and scan; the product is added when Enter is received.</span></div><input v-model="barcodeScan" inputmode="numeric" autocomplete="off" autofocus placeholder="Scan EAN-13 or enter SKU" aria-label="Scan product barcode"><button class="btn-primary-app" type="submit">Add</button></form>
              <div class="pos-catalog-head"><div class="search-box pos-search"><Search :size="18" /><input v-model="posSearch" placeholder="Search by product, SKU, barcode or category..."></div><span>{{ posProducts.length }} available products</span></div>
              <div v-if="posProducts.length" class="pos-product-grid"><button v-for="product in posProducts" :key="product.id" class="pos-product-card" @click="addPosProduct(product)"><span class="product-avatar">{{ initial(product.name) }}</span><div><strong>{{ product.name }}</strong><small>{{ product.sku || product.category?.name || 'No SKU' }}</small></div><span class="pos-product-price">{{ money(product.sale_price) }}</span><small class="pos-product-stock">{{ qty(product.available_quantity ?? product.quantity_on_hand) }} {{ product.unit }} in stock<span v-if="product.stock_source_product_id"> · shared</span></small><span class="pos-add"><Plus :size="15" /> Add</span></button></div>
              <div v-else class="empty-state"><Package :size="32" /><strong>No stocked products found</strong><span>Purchase or adjust stock before creating a POS sale.</span></div>
            </section>
            <aside class="panel pos-checkout">
              <section v-if="session.gold_store" class="pos-gold-exchange"><strong>Old gold exchange</strong><div class="pos-payment-grid"><label class="field"><span>Weight (g)</span><input v-model="posForm.gold_exchange_weight" type="number" min="0" step="0.001"></label><label class="field"><span>Purity</span><select v-model="posForm.gold_exchange_purity"><option v-for="rate in goldRates.filter(rate => rate.metal === 'gold')" :key="rate.id" :value="rate.purity">{{ rate.purity }}</option></select></label></div><small>Credit {{ money(posGoldExchangeAmount) }} at the selected purity's branch rate.</small></section>
              <div class="pos-checkout-head"><div><ReceiptText :size="19" /><div><h2>Current sale</h2><p>{{ posForm.items.length }} cart {{ posForm.items.length === 1 ? 'item' : 'items' }}</p></div></div><input v-model="posForm.document_date" type="date" :min="staffDocumentDatesLocked ? today() : undefined" :max="staffDocumentDatesLocked ? today() : undefined" aria-label="Sale date"></div>
              <div class="pos-customer"><label class="field"><span>Customer *</span><div class="field-action"><select v-model="posForm.contact_id" required><option value="" disabled>Select a customer</option><option v-for="customer in posCustomers" :key="customer.id" :value="customer.id">{{ customer.name }}{{ customer.phone ? ` · ${customer.phone}` : '' }}</option></select><button class="btn-secondary-app small create-inline" type="button" @click="createPosCustomer"><Plus :size="16" /> New</button></div><small v-if="!posCustomers.length">Create a customer before checkout.</small></label></div>
              <div v-if="posForm.items.length" class="pos-cart"><div v-for="(item, index) in posForm.items" :key="item.product_id" class="pos-cart-item"><div class="pos-cart-title"><div><strong>{{ item.name }}</strong><small>{{ item.sku || `${qty(item.stock)} ${item.base_unit} available` }}</small></div><button class="icon-button" title="Remove" @click="posForm.items.splice(index, 1)"><Trash2 :size="15" /></button></div><div class="pos-cart-controls"><div class="quantity-control"><button @click="changePosQuantity(item, -1)"><Minus :size="14" /></button><input v-model="item.quantity" type="number" :min="['pc', 'pcs'].includes(item.base_unit) && item.unit === item.base_unit ? 1 : 0.001" :max="Number(item.stock) / transactionUnitFactor(item)" :step="['pc', 'pcs'].includes(item.base_unit) && item.unit === item.base_unit ? 1 : 0.001" aria-label="Quantity"><button @click="changePosQuantity(item, 1)"><Plus :size="14" /></button></div><label><span>Unit</span><select v-model="item.unit" :aria-label="`Unit for ${item.name}`" @change="changeTransactionUnit(item)"><option v-for="unit in transactionUnitOptions(item.product_id)" :key="unit.value" :value="unit.value">{{ unit.label }}</option></select></label><label><span>Price per {{ item.unit }}</span><input v-model="item.unit_price" type="number" min="0" step="0.01"></label><strong>{{ money(Number(item.quantity) * Number(item.unit_price)) }}</strong></div><small v-if="Number(item.quantity) * transactionUnitFactor(item) > Number(item.stock)" class="pos-line-error">Only {{ qty(item.stock) }} {{ item.base_unit }} available.</small></div></div>
              <div v-else class="empty-state pos-empty"><ShoppingCart :size="30" /><strong>Your cart is empty</strong><span>Choose a product to begin the sale.</span></div>
              <div class="pos-summary"><div><span>Subtotal</span><strong>{{ money(posSubtotal) }}</strong></div><label><span>Discount</span><input v-model="posForm.discount" type="number" min="0" :max="posSubtotal" step="0.01"></label><label><span>Tax</span><input v-model="posForm.tax" type="number" min="0" step="0.01"></label><div class="pos-grand-total"><span>Total</span><strong>{{ money(posTotal) }}</strong></div></div>
              <div class="pos-payment"><div class="pos-section-title"><Banknote :size="17" /><strong>Payment terms</strong></div><label class="field"><span>Sale type *</span><select v-model="posForm.payment_type" @change="setPosPayment"><option value="full">Immediate / full payment</option><option value="installment">Down payment + installment</option><option value="due">Due sale</option></select></label><div v-if="posForm.payment_type !== 'due'" class="pos-payment-grid"><label class="field"><span>Payment method *</span><select v-model="posForm.payment_method"><option value="cash">Cash</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option><option value="bank_transfer">Bank transfer</option></select></label><label v-if="posForm.payment_type === 'installment'" class="field"><span>Down payment</span><input v-model="posForm.down_payment" type="number" min="0" :max="posBaseTotal" step="0.01" @input="setPosPayment"></label><div v-else class="field"><span>Collected now</span><strong class="payment-total">{{ money(posTotal) }}</strong></div></div><div v-if="posForm.payment_type === 'installment'" class="pos-payment-grid"><label class="field"><span>Interest rate (%)</span><input v-model="posForm.interest_rate" type="number" min="0" max="100" step="0.01"><small>Flat interest on the financed balance.</small></label><label class="field"><span>Installments</span><input v-model="posForm.installment_count" type="number" min="1" max="120" step="1"></label><label class="field"><span>Frequency</span><select v-model="posForm.installment_frequency"><option value="weekly">Weekly</option><option value="half_monthly">Every 15 days</option><option value="monthly">Monthly</option></select></label><label class="field"><span>Start date</span><input v-model="posForm.installment_start_date" type="date" :min="posForm.document_date" required><small>First payment: {{ posInstallmentRows[0]?.due_date ? date(posInstallmentRows[0].due_date) : 'Choose a valid start date' }}</small></label></div><div v-if="posInstallmentRows.length" class="installment-plan-preview"><div class="installment-plan-head"><strong>Installment plan</strong><small>{{ posInstallmentRows.length }} payments · {{ frequencyLabel(posForm.installment_frequency) }} · starts {{ date(posForm.installment_start_date) }}</small></div><div class="installment-plan-stats"><div><span>Financed amount</span><strong>{{ money(posFinancedAmount) }}</strong></div><div><span>Interest charge</span><strong>{{ money(posInterestAmount) }}</strong></div><div><span>Total to collect</span><strong>{{ money(posBalance) }}</strong></div></div><div class="installment-plan-table-wrap"><table class="installment-plan-table"><thead><tr><th>#</th><th>Due date</th><th>Day</th><th>Principal</th><th>Interest</th><th>Installment</th></tr></thead><tbody><tr v-for="row in posInstallmentRows" :key="row.sequence"><td>{{ row.sequence }}</td><td>{{ date(row.due_date) }}</td><td>{{ weekday(row.due_date) }}</td><td>{{ money(row.principal) }}</td><td>{{ money(row.interest) }}</td><td><strong>{{ money(row.amount) }}</strong></td></tr></tbody></table></div><small class="installment-plan-footnote">The final payment includes any rounding difference. These due dates will appear on the invoice.</small></div><div v-if="posForm.payment_type === 'due'" class="payment-plan-note">No payment is collected today. The full total is recorded as customer due.</div><div v-if="posInterestAmount > 0" class="payment-plan-note">Interest charge: {{ money(posInterestAmount) }}</div><div class="pos-balance" :class="{ due: posBalance > 0 }"><span>{{ posForm.payment_type === 'installment' ? 'Scheduled balance' : 'Balance due' }}</span><strong>{{ money(posBalance) }}</strong></div><label class="field"><span>Notes</span><textarea v-model="posForm.notes" rows="2" placeholder="Optional sale notes"></textarea></label></div>
              <button class="btn-primary-app pos-complete" :disabled="busy || !posCanCheckout" @click="completePosSale"><ReceiptText :size="18" /> {{ busy ? 'Completing sale...' : `Complete sale · ${money(posTotal)}` }}</button>
            </aside>
          </div>
        </template>

        <template v-else-if="['purchase', 'sale', 'resale', 'purchase_return', 'sale_return'].includes(page)"><div class="page-heading"><div><span class="eyebrow">TRANSACTIONS</span><h1>{{ titles[page] }}</h1><p>{{ page === 'purchase_return' ? 'Return items to suppliers with a linked purchase.' : page === 'sale_return' ? 'Refund sold items and restore them to inventory.' : `Track every ${label(page).toLowerCase()} and its stock movement.` }}</p></div><button class="btn-primary-app" @click="openDocument(page)"><Plus :size="18" /> New {{ label(page).toLowerCase() }}</button></div><section class="panel table-panel"><div class="table-toolbar"><div><h2>{{ titles[page] }} history</h2><p>{{ filteredDocuments.length }} records</p></div><div class="search-box"><Search :size="17" /><input v-model="search" placeholder="Search invoice or contact..."></div></div>
          <div class="table-scroll transaction-history-scroll">
            <table class="transaction-history-table">
              <thead><tr>
                <th>DATE / INVOICE</th>
                <th>{{ ['purchase', 'purchase_return'].includes(page) ? 'SUPPLIER' : 'CUSTOMER' }}</th>
                <th>ITEMS</th>
                <th>TOTAL</th>
                <th v-if="['sale', 'resale', 'purchase'].includes(page)">PAYMENT</th>
                <th>PROCESS BY</th>
                <th>ACTIONS</th>
              </tr></thead>
              <tbody v-for="doc in filteredDocuments" :key="doc.id">
                <tr class="history-summary">
                  <td class="history-date"><strong>{{ date(doc.document_date) }}</strong><small>{{ time(doc.created_at) }} · {{ doc.number }}</small></td>
                  <td class="history-party">{{ doc.contact?.name || '—' }}</td>
                  <td class="history-items">{{ doc.items.length }} {{ doc.items.length === 1 ? 'item' : 'items' }}</td>
                  <td class="history-total strong">{{ money(doc.total) }}</td>
                  <td v-if="['sale', 'resale', 'purchase'].includes(page)" class="history-payment"><span class="stock-pill" :class="Number(doc.balance_due) > 0 ? 'low' : 'good'">{{ historyPaymentLabel(doc) }}</span><small v-if="Number(doc.balance_due) > 0">Due {{ money(doc.balance_due) }}</small></td>
                  <td class="history-processor processed-by-name" :title="doc.creator?.name || ''"><small class="history-processor-label">Processed by</small>{{ doc.creator?.name?.trim() || '—' }}</td>
                  <td class="history-actions"><div class="row-actions">
                    <button class="btn-secondary-app small" title="View invoice" @click="openInvoice(doc)"><Eye :size="15" /> Invoice</button>
                    <button v-if="['sale', 'resale'].includes(page) && Number(doc.balance_due) > 0" class="btn-primary-app small" @click="openSalePayment(doc)">Collect</button>
                    <button v-if="session.user.role === 'admin' && ['sale', 'resale', 'purchase'].includes(page)" class="icon-button danger" type="button" title="Delete transaction" :disabled="busy" @click="deleteDocument(doc)"><Trash2 :size="17" /></button>
                    <button class="icon-button" :title="detailId === doc.id ? 'Hide details' : 'Show details'" :aria-expanded="detailId === doc.id" @click="detailId = detailId === doc.id ? null : doc.id"><ChevronDown :size="18" :class="{ rotated: detailId === doc.id }" /></button>
                  </div></td>
                </tr>
                <tr v-if="detailId === doc.id" class="detail-row history-detail"><td :colspan="['sale', 'resale', 'purchase'].includes(page) ? 7 : 6">
                  <div class="detail-card">
                    <div class="detail-meta">
                      <span>Invoice: <strong>{{ doc.number }}</strong></span>
                      <span>Processed by: <strong>{{ doc.creator?.name?.trim() || '—' }}</strong></span>
                      <span v-if="doc.purchase">Original purchase: <strong>{{ doc.purchase.number }}</strong></span>
                      <span v-if="doc.sale">Original sale: <strong>{{ doc.sale.number }}</strong></span>
                      <span v-if="['sale', 'resale', 'purchase'].includes(page)">Payment: <strong>{{ historyPaymentLabel(doc) }}</strong> · Paid {{ money(doc.amount_paid) }} · Due {{ money(doc.balance_due) }}</span>
                      <span v-if="['sale', 'resale'].includes(page)">Stock profit: <strong>{{ money(doc.stock_profit) }}</strong> · Total profit: <strong>{{ money(doc.total_profit) }}</strong></span>
                      <span v-if="doc.notes">{{ doc.notes }}</span>
                    </div>
                    <div v-for="item in doc.items" :key="item.id" class="detail-line"><span>{{ item.product?.name }} <small v-if="item.product?.sku">({{ item.product.sku }})</small></span><span>{{ qty(item.entered_quantity ?? item.quantity) }} {{ item.entered_unit || item.product?.unit }} × {{ money(item.entered_unit_price ?? item.unit_price) }}</span><strong>{{ money(item.line_total) }}</strong></div>
                    <div class="detail-totals"><span>Subtotal {{ money(doc.subtotal) }} · Discount {{ money(doc.discount) }} · Tax {{ money(doc.tax) }}</span><strong>Total {{ money(doc.total) }}</strong></div>
                  </div>
                </td></tr>
              </tbody>
            </table>
          </div><div v-if="!filteredDocuments.length" class="empty-state"><ShoppingCart :size="32" /><strong>No {{ titles[page].toLowerCase() }} yet</strong><span>Create a record to see it here.</span></div></section></template>

        <template v-else-if="page === 'inventory'"><div class="page-heading"><div><span class="eyebrow">STOCK CONTROL</span><h1>Inventory</h1><p>See current balances and the history behind every change.</p></div><button v-if="hasAbility('stock_adjustments')" class="btn-primary-app" @click="openAdjustment()"><Plus :size="18" /> Adjust stock</button></div><div class="inventory-grid"><section class="panel table-panel"><div class="table-toolbar"><div><h2>Stock on hand</h2><p>{{ stockProducts.length }} stock products</p></div></div><div class="table-scroll"><table><thead><tr><th>PRODUCT</th><th>{{ language === 'bn' ? 'স্টকে আছে' : 'AVAILABLE' }}</th><th>REORDER AT</th><th v-if="hasAbility('stock_adjustments')"></th></tr></thead><tbody><tr v-for="product in stockProducts" :key="product.id"><td><div class="name-cell"><span class="product-avatar">{{ initial(product.name) }}</span><div><strong>{{ product.name }}</strong><small>{{ product.sku || 'No SKU' }}</small></div></div></td><td><span class="stock-pill" :class="lowStock(product) ? 'low' : 'good'">{{ qty(product.quantity_on_hand) }} {{ product.unit }}</span></td><td>{{ qty(product.reorder_level) }}</td><td v-if="hasAbility('stock_adjustments')"><button class="icon-button" title="Adjust stock" @click="openAdjustment(product)"><Pencil :size="17" /></button></td></tr></tbody></table></div><div v-if="!stockProducts.length" class="empty-state"><Package :size="32" /><strong>No products yet</strong><span>Add products to start tracking stock.</span></div></section><section class="panel movements-panel"><div class="panel-heading"><div><h2>Recent movements</h2><p>Latest 200 entries</p></div></div><div v-if="movements.length" class="movement-list"><div v-for="movement in movements" :key="movement.id" class="movement-row"><span class="movement-symbol" :class="Number(movement.quantity_change) > 0 ? 'in' : 'out'"><ArrowDownRight v-if="Number(movement.quantity_change) > 0" :size="18" /><ArrowUpRight v-else :size="18" /></span><div class="movement-copy"><strong>{{ movement.product?.name }}</strong><span>{{ movement.document?.number || label(movement.type) }} · {{ date(movement.created_at) }}</span><small v-if="movement.notes">{{ movement.notes }}</small></div><div class="movement-qty" :class="Number(movement.quantity_change) > 0 ? 'positive' : 'negative'"><strong>{{ Number(movement.quantity_change) > 0 ? '+' : '' }}{{ qty(movement.quantity_change) }}</strong><span>Balance {{ qty(movement.balance_after) }}</span></div></div></div><div v-else class="empty-state compact"><Boxes :size="32" /><strong>No stock movements</strong><span>Purchases and adjustments will appear here.</span></div></section></div></template>

        <template v-else-if="page === 'product_damages'">
          <ProductDamages :branch-id="activeBranchId" :products="products" :api="api" :language="language" @saved="refresh" />
        </template>

        <template v-else-if="page === 'stock_checks'">
          <div class="page-heading"><div><span class="eyebrow">PHYSICAL INVENTORY</span><h1>Stock Check</h1><p>Count the products in the current branch and reconcile any variance.</p></div><button class="btn-primary-app" :disabled="busy || stockChecks.some(check => check.status === 'draft')" :title="stockChecks.some(check => check.status === 'draft') ? 'Complete the current draft first' : 'Start a new physical count'" @click="startStockCheck"><Plus :size="18" /> Start stock check</button></div>
          <div class="category-stats"><div class="stat-card"><div class="stat-top"><span>Total checks</span><span class="stat-icon violet"><ClipboardCheck :size="19" /></span></div><strong>{{ stockChecks.length }}</strong><small>Count sessions for this branch</small></div><div class="stat-card"><div class="stat-top"><span>Draft</span><span class="stat-icon blue"><Clock3 :size="19" /></span></div><strong>{{ stockChecks.filter(check => check.status === 'draft').length }}</strong><small>Count waiting to be completed</small></div><div class="stat-card"><div class="stat-top"><span>Completed</span><span class="stat-icon green"><Check :size="19" /></span></div><strong>{{ stockChecks.filter(check => check.status === 'completed').length }}</strong><small>Reconciled count sessions</small></div></div>
          <section class="panel table-panel"><div class="table-toolbar"><div><h2>Stock check history</h2><p>{{ stockChecks.length }} records in {{ branches.find(branch => branch.id === Number(activeBranchId))?.name || 'current branch' }}</p></div></div><div class="table-scroll"><table><thead><tr><th>SL</th><th>NUMBER</th><th>DATE</th><th>PRODUCTS</th><th>COUNTED</th><th>VARIANCE</th><th>STATUS</th><th>CREATED BY</th><th></th></tr></thead><tbody><tr v-for="(stockCheck, index) in stockChecks" :key="stockCheck.id"><td class="mono strong">{{ index + 1 }}</td><td class="mono strong">{{ stockCheck.number }}</td><td>{{ date(stockCheck.check_date) }}</td><td>{{ stockCheck.items.length }}</td><td>{{ stockCheck.items.filter(item => item.counted_quantity !== null).length }} / {{ stockCheck.items.length }}</td><td><span class="stock-check-variance" :class="{ positive: checkVariance(stockCheck) > 0, negative: checkVariance(stockCheck) < 0 }">{{ checkVariance(stockCheck) > 0 ? '+' : '' }}{{ qty(checkVariance(stockCheck)) }}</span></td><td><span class="stock-pill" :class="stockCheck.status === 'completed' ? 'good' : 'pending'">{{ stockCheck.status === 'completed' ? 'Completed' : 'Draft' }}</span></td><td>{{ stockCheck.creator?.name || '—' }}</td><td><button class="btn-secondary-app small" @click="openStockCheck(stockCheck)"><Eye :size="15" /> {{ stockCheck.status === 'draft' ? 'Continue count' : 'View' }}</button></td></tr></tbody></table></div><div v-if="!stockChecks.length" class="empty-state"><ClipboardCheck :size="32" /><strong>No stock checks yet</strong><span>Start a physical count for the selected branch.</span></div></section>
        </template>

        <template v-else-if="page === 'gold_rates'">
          <div class="page-heading"><div><span class="eyebrow">GOLD STORE · PRICING</span><h1>Metal rates</h1><p>Maintain branch-specific buying rates. Sale prices and old-gold exchange values use the effective rate for the transaction date.</p></div></div>
          <section class="panel gold-rate-panel"><form class="gold-rate-form" @submit.prevent="saveGoldRate"><label class="field"><span>Metal</span><select v-model="goldRateForm.metal"><option value="gold">Gold</option><option value="silver">Silver</option><option value="platinum">Platinum</option></select></label><label class="field"><span>Purity / karat</span><select v-model="goldRateForm.purity"><option v-for="purity in ['24K','22K','21K','18K','14K','9K','925','950']" :key="purity" :value="purity">{{ purity }}</option></select></label><label class="field"><span>Rate per gram</span><input v-model="goldRateForm.rate_per_gram" type="number" min="0.01" step="0.01" required></label><label class="field"><span>Effective from</span><input v-model="goldRateForm.effective_on" type="date" required></label><button class="btn-primary-app" :disabled="busy"><Check :size="17" /> Save rate</button></form></section>
          <section class="panel table-panel"><div class="table-toolbar"><div><h2>Current branch rates</h2><p>{{ goldRates.length }} active metal/purity rates · {{ branches.find(branch => branch.id === Number(activeBranchId))?.name || 'Current branch' }}</p></div></div><div class="table-scroll"><table><thead><tr><th>METAL</th><th>PURITY</th><th>RATE / GRAM</th><th>EFFECTIVE FROM</th></tr></thead><tbody><tr v-for="rate in goldRates" :key="rate.id"><td>{{ rate.metal }}</td><td><strong>{{ rate.purity }}</strong></td><td>{{ money(rate.rate_per_gram) }}</td><td>{{ date(rate.effective_on) }}</td></tr></tbody></table></div><div v-if="!goldRates.length" class="empty-state"><ChartNoAxesCombined :size="30"/><strong>No metal rates entered</strong><span>Enter a rate before selling gold items or accepting old gold.</span></div></section>
        </template>

        <template v-else-if="page === 'gold_jobs'">
          <div class="page-heading"><div><span class="eyebrow">GOLD STORE · WORKSHOP</span><h1>Goldsmith jobs</h1><p>Track jewelry manufacturing and repair work, metal handover, return weight, wastage and labor charge.</p></div></div>
          <section class="panel gold-job-create"><div class="panel-heading"><div><h2>Record a workshop job</h2><p>Job records are branch-scoped and retain the entered weight and charge history.</p></div></div><form class="gold-job-form" @submit.prevent="saveGoldJob"><label class="field"><span>Job type</span><select v-model="goldJobForm.job_type"><option value="manufacturing">Manufacturing</option><option value="repair">Repair</option></select></label><label class="field"><span>Customer</span><select v-model="goldJobForm.contact_id"><option value="">No customer</option><option v-for="contact in posCustomers" :key="contact.id" :value="contact.id">{{ contact.name }}</option></select></label><label class="field"><span>Related product</span><select v-model="goldJobForm.product_id"><option value="">No product</option><option v-for="product in products.filter(product => product.is_gold_item)" :key="product.id" :value="product.id">{{ product.name }}{{ product.tag_number ? ` · ${product.tag_number}` : '' }}</option></select></label><label class="field"><span>Purity</span><select v-model="goldJobForm.gold_purity"><option v-for="rate in goldRates.filter(rate => rate.metal === 'gold')" :key="rate.id" :value="rate.purity">{{ rate.purity }}</option></select></label><label class="field"><span>Input weight (g) *</span><input v-model="goldJobForm.input_weight" type="number" min="0" step="0.001" required></label><label class="field"><span>Due date</span><input v-model="goldJobForm.due_date" type="date"></label><label class="field span-2"><span>Notes</span><input v-model="goldJobForm.notes" maxlength="2000" placeholder="Design, repair instructions, stone details…"></label><button class="btn-primary-app" :disabled="busy"><Plus :size="17"/> Create job</button></form></section>
          <section class="panel table-panel"><div class="table-toolbar"><div><h2>Workshop register</h2><p>{{ goldJobs.length }} jobs</p></div></div><div class="table-scroll"><table><thead><tr><th>JOB</th><th>TYPE / CONTACT</th><th>INPUT (g)</th><th>OUTPUT (g)</th><th>WASTAGE (g)</th><th>LABOR</th><th>DUE</th><th>STATUS</th><th></th></tr></thead><tbody><tr v-for="job in goldJobs" :key="job.id"><td><strong>{{ job.number }}</strong><small>{{ job.gold_purity || '—' }}</small></td><td>{{ job.job_type }}<small>{{ job.contact?.name || 'No customer' }}</small></td><td>{{ qty(job.input_weight) }}</td><td><input v-model="job.output_weight" class="table-number-input" type="number" min="0" step="0.001" aria-label="Output weight"></td><td><input v-model="job.wastage_weight" class="table-number-input" type="number" min="0" step="0.001" aria-label="Wastage weight"></td><td><input v-model="job.labor_amount" class="table-number-input" type="number" min="0" step="0.01" aria-label="Labor charge"></td><td>{{ date(job.due_date) }}</td><td><select v-model="job.status" class="table-status-select"><option value="open">Open</option><option value="in_progress">In progress</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></td><td><button class="btn-secondary-app small" @click="updateGoldJob(job)">Save</button></td></tr></tbody></table></div><div v-if="!goldJobs.length" class="empty-state"><ClipboardList :size="30"/><strong>No workshop jobs</strong><span>Jobs will appear here after creation.</span></div></section>
        </template>

        <template v-else-if="page === 'gold_report'">
          <div class="page-heading"><div><span class="eyebrow">GOLD STORE · RECONCILIATION</span><h1>Gold stock report</h1><p>Branch-level net metal weight and indicative metal value at current recorded rates. Values exclude stones, making charges and tax.</p></div><button class="btn-secondary-app" @click="refresh"><ArrowUpRight :size="17"/> Refresh</button></div>
          <div class="category-stats gold-report-stats"><div class="stat-card"><div class="stat-top"><span>Gold product types</span><span class="stat-icon violet"><Package :size="19"/></span></div><strong>{{ goldSummary.summary?.gold_items || 0 }}</strong><small>Active and inactive catalog items</small></div><div class="stat-card"><div class="stat-top"><span>Net stock weight</span><span class="stat-icon blue"><Boxes :size="19"/></span></div><strong>{{ qty(goldSummary.summary?.net_weight) }} g</strong><small>On-hand quantity × net weight per item</small></div><div class="stat-card"><div class="stat-top"><span>Indicative metal value</span><span class="stat-icon green"><Banknote :size="19"/></span></div><strong>{{ money(goldSummary.summary?.estimated_metal_value) }}</strong><small>Excludes stones, making and tax</small></div><div class="stat-card"><div class="stat-top"><span>Open workshop jobs</span><span class="stat-icon orange"><ClipboardList :size="19"/></span></div><strong>{{ goldSummary.summary?.open_jobs || 0 }}</strong><small>Open or in progress</small></div></div>
          <section class="panel table-panel"><div class="table-toolbar"><div><h2>Gold item stock by purity</h2><p>Current branch inventory and estimated metal value</p></div></div><div class="table-scroll"><table><thead><tr><th>PRODUCT / TAG</th><th>PURITY</th><th>QTY</th><th>NET / ITEM (g)</th><th>NET STOCK (g)</th><th>RATE / g</th><th>METAL VALUE</th><th>HALLMARK</th></tr></thead><tbody><tr v-for="item in goldSummary.inventory" :key="item.id"><td><strong>{{ item.name }}</strong><small>{{ item.tag_number || 'No tag' }}</small></td><td>{{ item.purity }}</td><td>{{ qty(item.quantity) }}</td><td>{{ qty(item.net_weight_each) }}</td><td>{{ qty(item.net_stock_weight) }}</td><td>{{ item.rate_per_gram ? money(item.rate_per_gram) : '—' }}</td><td>{{ money(item.estimated_metal_value) }}</td><td>{{ item.hallmark_code || '—' }}</td></tr></tbody></table></div><div v-if="!goldSummary.inventory?.length" class="empty-state"><Boxes :size="30"/><strong>No gold items in this branch</strong><span>Add gold products to see their weight and valuation.</span></div></section>
          <section class="panel table-panel"><div class="table-toolbar"><div><h2>Old-gold exchanges</h2><p>{{ qty(goldSummary.summary?.exchange_weight) }} g received · {{ money(goldSummary.summary?.exchange_value) }} credited (latest 20 records)</p></div></div><div class="table-scroll"><table><thead><tr><th>DATE</th><th>INVOICE</th><th>CUSTOMER</th><th>PURITY</th><th>WEIGHT</th><th>RATE / g</th><th>EXCHANGE CREDIT</th></tr></thead><tbody><tr v-for="exchange in goldSummary.exchanges" :key="exchange.id"><td>{{ date(exchange.document_date) }}</td><td>{{ exchange.number }}</td><td>{{ exchange.contact?.name || '—' }}</td><td>{{ exchange.gold_exchange_purity }}</td><td>{{ qty(exchange.gold_exchange_weight) }} g</td><td>{{ money(exchange.gold_exchange_rate) }}</td><td>{{ money(exchange.gold_exchange_value) }}</td></tr></tbody></table></div><div v-if="!goldSummary.exchanges?.length" class="empty-state compact">No old-gold exchanges recorded yet.</div></section>
        </template>

        <template v-else-if="page === 'system_setup'">
          <div class="page-heading"><div><span class="eyebrow">COMPANY PREFERENCES</span><h1>Settings / System Setup</h1><p>Manage your company details and the way your company sells, buys and manages stock.</p></div></div>
          <div v-if="error" class="alert-box error">{{ error }}</div>
          <form v-if="systemSetup" class="setup-layout" :class="`setup-section-${systemSetupSection}`" @submit.prevent="saveSystemSetup">
            <div class="setup-intro"><div class="setup-intro-icon"><Settings2 :size="22" /></div><div><strong>How your workspace works</strong><p>Company details and preferences apply across your workspace. Manage each team member’s access on the Users page.</p></div><button type="button" class="btn-secondary-app" @click="navigate('users')"><UserCog :size="16" /> Manage users</button></div>
            <div class="setup-section-tabs" role="tablist" aria-label="Settings sections"><button v-for="section in [{ id: 'company', label: 'Company details', icon: Building2 }, { id: 'sales', label: 'Sales', icon: ShoppingCart }, { id: 'purchases', label: 'Purchases', icon: ShoppingBag }, { id: 'store', label: 'Store & stock', icon: Boxes }]" :key="section.id" type="button" role="tab" :aria-selected="systemSetupSection === section.id" :class="{ active: systemSetupSection === section.id }" @click="systemSetupSection = section.id"><component :is="section.icon" :size="17" />{{ section.label }}</button></div>
            <section class="panel setup-panel setup-company">
              <div class="setup-panel-heading"><span class="setup-section-icon company"><Building2 :size="20" /></span><div><h2>Company details</h2><p>Edit your business name and contact information</p></div></div>
              <div class="setup-company-fields">
                <label class="field"><span>Company name *</span><input v-model.trim="systemSetup.company.name" required maxlength="255" autocomplete="organization"></label>
                <label class="field"><span>Company phone</span><input v-model.trim="systemSetup.company.phone" type="tel" maxlength="50" autocomplete="tel" placeholder="e.g. 01700000000"></label>
                <label class="field setup-company-address"><span>Company address</span><textarea v-model.trim="systemSetup.company.address" rows="3" maxlength="1000" autocomplete="street-address" placeholder="Street, city and postal code"></textarea></label>
              </div>
            </section>
            <section class="panel setup-panel setup-sales"><div class="setup-panel-heading"><span class="setup-section-icon sales"><ShoppingCart :size="20" /></span><div><h2>Sales</h2><p>Payment choices and optional sale types</p></div></div><div class="setup-options"><label class="setup-option"><span><strong>Due sales</strong><small>Let customers pay the full amount later</small></span><input v-model="systemSetup.sales.allow_due_sales" type="checkbox" role="switch"></label><label class="setup-option"><span><strong>Installments</strong><small>Accept a down payment and schedule the rest</small></span><input v-model="systemSetup.sales.allow_installment_sales" type="checkbox" role="switch"></label><label class="setup-option"><span><strong>Sales returns</strong><small>Allow refunds against earlier sales</small></span><input v-model="systemSetup.sales.allow_sales_returns" type="checkbox" role="switch"></label><label class="setup-option"><span><strong>Resales</strong><small>Allow resale transactions</small></span><input v-model="systemSetup.sales.allow_resales" type="checkbox" role="switch"></label></div><label class="field setup-field"><span>Default payment method</span><select v-model="systemSetup.sales.default_payment_method"><option value="cash">Cash</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option><option value="bank_transfer">Bank transfer</option></select><small>Preselected for new sales; staff can change it per transaction.</small></label></section>
            <div class="setup-side">
              <section class="panel setup-panel">
                <div class="setup-panel-heading"><span class="setup-section-icon purchases"><ShoppingBag :size="20" /></span><div><h2>Purchases</h2><p>Supplier return preferences</p></div></div>
                <label class="setup-option"><span><strong>Purchase returns</strong><small>Return purchased items to suppliers</small></span><input v-model="systemSetup.purchases.allow_purchase_returns" type="checkbox" role="switch"></label>
              </section>
              <section class="panel setup-panel">
                <div class="setup-panel-heading"><span class="setup-section-icon purchases"><Banknote :size="20" /></span><div><h2>Supplier Advances</h2><p>Independent TT register</p></div></div>
                <label class="setup-option"><span><strong>Enable Supplier Advances</strong><small>Record advance transfers and coded product deliveries separately from purchases and stock.</small></span><input v-model="systemSetup.supplier_advances.enabled" type="checkbox" role="switch"></label>
              </section>
              <section class="panel setup-panel">
                <div class="setup-panel-heading"><span class="setup-section-icon store"><Boxes :size="20" /></span><div><h2>Store &amp; stock</h2><p>Inventory movement controls</p></div></div>
                <div class="setup-options">
                  <label class="setup-option"><span><strong>Stock adjustments</strong><small>Correct quantities manually</small></span><input v-model="systemSetup.store.allow_stock_adjustments" type="checkbox" role="switch"></label>
                  <label class="setup-option"><span><strong>Stock transfers</strong><small>Move items between branches</small></span><input v-model="systemSetup.store.allow_stock_transfers" type="checkbox" role="switch"></label>
                  <label class="setup-option"><span><strong>Cash handover and owner withdrawal</strong><small>Track each user's cash, transfer the full balance to the owner, and record owner withdrawals.</small></span><input v-model="systemSetup.store.allow_cash_handover" type="checkbox" role="switch"></label>
                  <label class="setup-option"><span><strong>Allow staff past and future dates</strong><small>Managers and staff can choose any date for sales and purchases. Off: today only.</small></span><input v-model="systemSetup.store.allow_staff_custom_dates" type="checkbox" role="switch"></label>
                </div>
                <label class="field setup-field"><span>Default reorder level</span><input v-model="systemSetup.store.default_reorder_level" type="number" min="0" max="999999" step="0.001" required><small>Suggested minimum stock for each new product.</small></label>
                <div class="field setup-field unit-select-field">
                  <span>Product units</span>
                  <div class="select2-multiple" :class="{ open: unitPickerOpen }" @focusout="($event) => { if (!$event.currentTarget.contains($event.relatedTarget)) unitPickerOpen = false; }">
                    <div class="select2-selection" @click="unitPickerOpen = true">
                      <span v-for="unit in systemSetup.store.units" :key="unit" class="select2-chip">{{ unit }}<button type="button" :aria-label="`Remove ${unit}`" @click.stop="toggleSetupUnit(unit)">×</button></span>
                      <input v-model="unitQuery" role="combobox" aria-label="Search product units" aria-autocomplete="list" :aria-expanded="unitPickerOpen" placeholder="Search or add units..." @focus="unitPickerOpen = true" @keydown.enter.prevent="addSetupUnitFromSearch">
                      <ChevronDown :size="16" />
                    </div>
                    <div v-if="unitPickerOpen" class="select2-dropdown" role="listbox" aria-label="Available product units">
                      <button v-for="choice in filteredUnitChoices" :key="choice.value" type="button" role="option" :aria-selected="systemSetup.store.units.includes(choice.value)" :class="{ selected: systemSetup.store.units.includes(choice.value) }" @click="toggleSetupUnit(choice.value)"><span>{{ choice.label }}</span><Check v-if="systemSetup.store.units.includes(choice.value)" :size="15" /></button>
                      <button v-if="unitQuery.trim() && !filteredUnitChoices.some(choice => choice.value.toLocaleLowerCase() === unitQuery.trim().toLocaleLowerCase())" type="button" class="select2-create-option" role="option" @click="addSetupUnitFromSearch">Add “{{ unitQuery.trim() }}”</button>
                      <p v-if="!filteredUnitChoices.length && !unitQuery.trim()" class="select2-empty">No units available.</p>
                    </div>
                  </div>
                  <small>Choose multiple units or search to add a custom unit such as g, oz, ভরি, or রতি.</small>
                </div>
                <div class="field setup-field unit-conversions-field">
                  <span>Unit conversions for piece-based products</span>
                  <small>Set how many single pieces make one transaction unit. For example, 1 hali = 4 pcs and 1 dozen = 12 pcs. Changes affect new transactions only.</small>
                  <div class="unit-conversions-grid">
                    <label v-for="unit in Object.keys(systemSetup.store.unit_conversions).filter(value => !nonPieceTransactionUnits.includes(value))" :key="unit" class="unit-conversion-row"><span>1 {{ unit }} =</span><input v-model.number="systemSetup.store.unit_conversions[unit]" type="number" min="1" max="1000000" step="1" :disabled="unit === 'pc'" required :aria-label="`Pieces in one ${unit}`"><span>pcs</span></label>
                  </div>
                  <small>Weight and volume units are used only with matching product base units.</small>
                </div>
              </section>
            </div>
            <div class="setup-actions"><span>Changes take effect after you save.</span><button class="btn-primary-app" :disabled="busy" type="submit"><Check :size="17" /> {{ busy ? 'Saving...' : 'Save settings' }}</button></div>
          </form>
        </template>

        <template v-else-if="page === 'stock_transfers'">
          <div class="page-heading"><div><span class="eyebrow">BRANCH INVENTORY</span><h1>Stock Transfer</h1><p>Move available products from the current branch to another company branch.</p></div><button class="btn-primary-app" :disabled="busy || !transferDestinations.length" :title="!transferDestinations.length ? 'Create another active branch before transferring stock' : 'Transfer stock to another branch'" @click="openStockTransfer"><Plus :size="18" /> New transfer</button></div>
          <div class="transfer-route-banner"><div class="transfer-branch"><span class="stat-icon violet"><MapPin :size="19" /></span><div><small>TRANSFER FROM</small><strong>{{ branches.find(branch => branch.id === Number(activeBranchId))?.name }}</strong></div></div><ArrowLeftRight :size="22" /><div><strong>{{ transferDestinations.length }}</strong><span>available destination {{ transferDestinations.length === 1 ? 'branch' : 'branches' }}</span></div></div>
          <section class="panel table-panel"><div class="table-toolbar"><div><h2>Transfer history</h2><p>{{ stockTransfers.length }} records involving the current branch</p></div></div><div class="table-scroll"><table><thead><tr><th>SL</th><th>NUMBER</th><th>DATE</th><th>DIRECTION</th><th>ROUTE</th><th>ITEMS</th><th>TOTAL QTY</th><th>PROCESS BY</th></tr></thead><tbody><tr v-for="(transfer, index) in stockTransfers" :key="transfer.id"><td class="mono strong">{{ index + 1 }}</td><td class="mono strong">{{ transfer.number }}</td><td>{{ date(transfer.transfer_date) }}</td><td><span class="transfer-direction" :class="transfer.from_branch_id === Number(activeBranchId) ? 'out' : 'in'">{{ transfer.from_branch_id === Number(activeBranchId) ? 'Sent' : 'Received' }}</span></td><td><div class="transfer-route"><strong>{{ transfer.from_branch?.name }}</strong><ArrowLeftRight :size="14" /><strong>{{ transfer.to_branch?.name }}</strong></div></td><td>{{ transfer.items.length }}</td><td>{{ qty(transfer.items.reduce((total, item) => total + Number(item.quantity), 0)) }}</td><td class="processed-by-name">{{ transfer.creator?.name?.trim() || '—' }}</td></tr></tbody></table></div><div v-if="!stockTransfers.length" class="empty-state"><ArrowLeftRight :size="32" /><strong>No stock transfers yet</strong><span>Move stock between branches to see the transfer history here.</span></div></section>
        </template>

        <template v-else-if="page === 'warranties'">
          <div class="page-heading"><div><span class="eyebrow">CUSTOMER SUPPORT</span><h1>Warranty Search</h1><p>Find sales warranty coverage by invoice, barcode, SKU, product, customer or phone.</p></div></div>
          <section class="panel table-panel"><form class="table-toolbar warranty-toolbar" @submit.prevent="searchWarranties"><div><h2>Warranty records</h2><p>{{ warrantyResults.length }} matching sold products</p></div><div class="company-toolbar-actions"><select v-model="warrantyStatusFilter" class="filter-select" aria-label="Filter warranties by status" @change="searchWarranties"><option value="all">All warranty states</option><option value="active">Active coverage</option><option value="expired">Expired coverage</option><option value="no_warranty">No warranty</option></select><div class="search-box"><Search :size="17" /><input v-model="warrantySearch" placeholder="Invoice, barcode, customer or phone..."></div><button class="btn-primary-app small" :disabled="busy"><Search :size="15" /> Search</button></div></form><div class="table-scroll"><table><thead><tr><th>SL</th><th>STATUS</th><th>PRODUCT</th><th>CUSTOMER</th><th>INVOICE</th><th>SALE DATE</th><th>WARRANTY UNTIL</th><th>BRANCH</th></tr></thead><tbody><tr v-for="(item, index) in warrantyResults" :key="item.id"><td class="mono strong">{{ index + 1 }}</td><td><span class="warranty-status" :class="item.warranty_status">{{ warrantyLabel(item.warranty_status) }}</span></td><td><div class="name-cell"><span class="product-avatar">{{ initial(item.product?.name) }}</span><div><strong>{{ item.product?.name || 'Deleted product' }}</strong><small>{{ item.product?.barcode || item.product?.sku || 'No barcode or SKU' }}</small></div></div></td><td><div><strong>{{ item.document?.contact?.name || 'Walk-in customer' }}</strong><small v-if="item.document?.contact?.phone">{{ item.document.contact.phone }}</small></div></td><td class="mono strong">{{ item.document?.number }}</td><td>{{ date(item.document?.document_date) }}</td><td><template v-if="item.warranty_expires_at">{{ date(item.warranty_expires_at) }}<small class="warranty-term">{{ item.warranty_months }} month{{ item.warranty_months === 1 ? '' : 's' }}</small></template><span v-else>—</span></td><td>{{ item.document?.branch?.name || '—' }}</td></tr></tbody></table></div><div v-if="!warrantyResults.length" class="empty-state"><ShieldCheck :size="32" /><strong>No warranty records found</strong><span>Try another search or record a sale for a product with a warranty period.</span></div></section>
        </template>

        <template v-else-if="page === 'sales_targets'">
          <div class="page-heading"><div><span class="eyebrow">SALES PLANNING</span><h1>Sales Targets</h1><p>Set monthly goals for a branch or employee, then follow net sales progress.</p></div><button class="btn-primary-app" @click="openSalesTarget()"><Plus :size="18" /> Set target</button></div>
          <div class="category-stats sales-target-stats"><div class="stat-card"><div class="stat-top"><span>Monthly target</span><span class="stat-icon violet"><Target :size="19" /></span></div><strong>{{ money(salesTargetTotals.target) }}</strong><small>{{ salesTargets.length }} goal{{ salesTargets.length === 1 ? '' : 's' }} for this period</small></div><div class="stat-card"><div class="stat-top"><span>Net sales</span><span class="stat-icon green"><ShoppingCart :size="19" /></span></div><strong>{{ money(salesTargetTotals.actual) }}</strong><small>Sales minus returns for assigned targets</small></div><div class="stat-card"><div class="stat-top"><span>Progress</span><span class="stat-icon blue"><ArrowUpRight :size="19" /></span></div><strong>{{ salesTargetTotals.target ? Math.round((salesTargetTotals.actual / salesTargetTotals.target) * 100) : 0 }}%</strong><small>Combined progress toward monthly goals</small></div></div>
          <section class="panel table-panel"><div class="table-toolbar"><div><h2>Monthly goals</h2><p>Actuals include sales and resales, less sales returns.</p></div><div class="company-toolbar-actions"><input v-model="salesTargetPeriod" class="target-period" type="month" aria-label="Target month" @change="refreshSalesTargets"><button class="btn-secondary-app small" :disabled="busy" @click="refreshSalesTargets"><Search :size="15" /> Load</button></div></div><div class="table-scroll"><table><thead><tr><th>SL</th><th>BRANCH</th><th>ASSIGNED TO</th><th>TARGET</th><th>NET SALES</th><th>REMAINING</th><th>PROGRESS</th><th>STATUS</th><th></th></tr></thead><tbody><tr v-for="(target, index) in salesTargets" :key="target.id"><td class="mono strong">{{ index + 1 }}</td><td><strong>{{ target.branch?.name }}</strong><small>{{ target.branch?.code }}</small></td><td><div v-if="target.user"><strong>{{ target.user.name }}</strong><small>{{ target.user.email }}</small></div><span v-else>Whole branch</span></td><td class="strong">{{ money(target.target_amount) }}</td><td class="profit-cell">{{ money(target.actual_amount) }}</td><td>{{ money(target.remaining_amount) }}</td><td><div class="target-progress"><span><i :style="{ width: `${Math.min(target.progress_percent, 100)}%` }"></i></span><strong>{{ target.progress_percent }}%</strong></div></td><td><span class="sales-target-status" :class="target.status">{{ salesTargetStatusLabel(target.status) }}</span></td><td><div class="row-actions"><button class="icon-button" title="Edit target" @click="openSalesTarget(target)"><Pencil :size="17" /></button><button class="icon-button danger" title="Delete target" :disabled="busy" @click="deleteSalesTarget(target)"><Trash2 :size="17" /></button></div></td></tr></tbody></table></div><div v-if="!salesTargets.length" class="empty-state"><Target :size="32" /><strong>No sales targets for this month</strong><span>Set a branch or employee target to begin tracking progress.</span></div></section>
        </template>

        <template v-else-if="page === 'clients'"><div class="page-heading"><div><span class="eyebrow">SUPERADMIN</span><h1>Companies</h1><p>Manage store owners, subscription validity and support access.</p></div><button class="btn-primary-app" @click="openClient()"><Plus :size="18" /> Add company</button></div><section class="panel table-panel"><div class="table-toolbar"><div><h2>All companies</h2><p>{{ filteredClients.length }} of {{ clients.length }} companies</p></div><div class="company-toolbar-actions"><select v-model="clientStatusFilter" class="filter-select" aria-label="Filter companies by subscription"><option value="all">All subscriptions</option><option value="active">Currently valid</option><option value="expiring">Expiring in 30 days</option><option value="expired">Expired</option><option value="past_due">Past due</option><option value="suspended">Suspended</option><option value="cancelled">Cancelled</option></select><div class="search-box"><Search :size="17" /><input v-model="search" placeholder="Search companies..."></div></div></div><div class="table-scroll"><table><thead><tr><th>COMPANY</th><th>OWNER ADMIN</th><th>PLAN</th><th>SUBSCRIPTION</th><th>VALID UNTIL</th><th>ACTIVITY</th><th></th></tr></thead><tbody><tr v-for="client in filteredClients" :key="client.id"><td><button class="company-name-button" @click="openClientDetails(client)"><span class="product-avatar contact-avatar">{{ initial(client.name) }}</span><strong>{{ client.name }}</strong></button><small v-if="client.expected_tenant_database && !client.tenant_database">Database setup needed</small></td><td><div v-if="client.admins?.length" class="name-cell"><div><strong>{{ client.admins[0].name }}</strong><small>{{ client.admins[0].email || client.admins[0].mobile }}</small></div></div><span v-else>Not assigned</span></td><td>{{ client.plan_name || '—' }}</td><td><span class="stock-pill" :class="client.subscription_active ? 'good' : 'low'">{{ clientValidityLabel(client) }}</span></td><td>{{ date(client.subscription_ends_at) }}</td><td><span class="activity-summary">{{ client.users_count }} users · {{ client.products_count }} products · {{ client.documents_count }} transactions</span></td><td><div class="row-actions"><button v-if="client.expected_tenant_database && !client.tenant_database" class="btn-secondary-app small" :disabled="busy" @click="openClientProvision(client)">Set up database</button><button class="icon-button" title="View company details" @click="openClientDetails(client)"><Info :size="17" /></button><button v-if="client.admins?.some(owner => owner.active)" class="btn-secondary-app small" :disabled="busy" title="Open this company as its owner administrator" @click="startSupportSession(client)"><Eye :size="15" /> View as owner</button><button v-else class="btn-secondary-app small" title="Create or activate this company's owner administrator" @click="openClient(client)"><UserCog :size="15" /> Set up owner</button><button class="icon-button" title="Edit company" @click="openClient(client)"><Pencil :size="17" /></button></div></td></tr></tbody></table></div><div v-if="!filteredClients.length" class="empty-state"><Building2 :size="32" /><strong>No companies found</strong><span>Add a subscribed company or change your filters.</span></div></section></template>

        <template v-else-if="page === 'users'">
          <div class="page-heading"><div><span class="eyebrow">ACCESS CONTROL</span><h1>Users</h1><p>{{ session.user.role === 'superadmin' ? 'Manage owner admins and users across every company.' : 'Create manager and staff accounts and choose exactly what they can use.' }}</p></div><button class="btn-primary-app" @click="openUser()"><Plus :size="18" /> Add user</button></div>
          <section class="panel table-panel"><div class="table-toolbar"><div><h2>User accounts</h2><p>{{ users.length }} users</p></div><div class="search-box"><Search :size="17" /><input v-model="search" placeholder="Search users..."></div></div><div class="table-scroll"><table><thead><tr><th>USER</th><th>ROLE</th><th v-if="session.user.role === 'superadmin'">COMPANY</th><th>ACCESS</th><th>STATUS</th><th></th></tr></thead><tbody><tr v-for="user in filteredUsers" :key="user.id"><td><div class="name-cell"><span class="product-avatar">{{ initial(user.name) }}</span><div><strong>{{ user.name }}</strong><small>{{ user.email || user.mobile }}</small></div></div></td><td><span class="type-pill">{{ roleLabel(user.role) }}</span></td><td v-if="session.user.role === 'superadmin'">{{ user.organization?.name || 'System' }}</td><td><div v-if="user.role === 'superadmin' || (user.role === 'admin' && user.permissions === null && user.report_permissions === null)">Full access</div><div v-else><div>{{ user.permissions?.length ?? (user.role === 'admin' ? permissionOptions.length : 0) }} modules · {{ userReportCount(user) }} reports</div><small v-if="['manager', 'staff'].includes(user.role)" class="assigned-branches">{{ user.accessible_branches?.map(branch => branch.name).join(', ') || 'No branches assigned' }}</small></div></td><td><span class="stock-pill" :class="user.active && !user.access_paused && !user.organization?.access_paused ? 'good' : 'low'">{{ user.access_paused ? 'Paused by admin' : !user.active ? 'Inactive' : user.organization?.access_paused ? 'Company paused' : 'Active' }}</span></td><td><div class="row-actions"><button v-if="session.user.role === 'admin' && user.active && !user.access_paused" class="icon-button" type="button" :title="`View as ${user.name}`" :aria-label="`View as ${user.name}`" :disabled="busy" @click="viewAsUser(user)"><Eye :size="17" /></button><button class="icon-button" title="Edit user" @click="openUser(user)"><Pencil :size="17" /></button><button v-if="session.user.role === 'admin' && !user.active" class="icon-button" type="button" :title="`Permanently delete ${user.name}`" :aria-label="`Permanently delete ${user.name}`" :disabled="busy" @click="deleteInactiveUser(user)"><Trash2 :size="17" /></button></div></td></tr></tbody></table></div><div v-if="!filteredUsers.length" class="empty-state"><UserCog :size="32" /><strong>No staff found</strong><span>Add a manager or staff account to begin delegating access.</span></div></section>
        </template>

        <template v-else-if="page === 'access_control'">
          <div class="page-heading"><div><span class="eyebrow">SUPERADMIN · ACCESS</span><h1>Access control</h1><p>Pause or restore a company or one of its users without changing the subscription plan, expiry date, or saved data.</p></div></div>
          <div class="access-control-explainer"><ShieldCheck :size="21" /><div><strong>How access works</strong><p>Pausing a company blocks its entire team. Pausing one user blocks only that account. Restoring a company does not extend an expired subscription, and individually paused users stay paused.</p></div></div>
          <section class="panel table-panel access-control-panel"><div class="table-toolbar"><div><h2>Company access</h2><p>{{ accessClients.length }} companies · a pause takes effect on the next request.</p></div><div class="search-box"><Search :size="17" /><input v-model="accessCompanySearch" aria-label="Search companies for access control" placeholder="Search companies..."></div></div><div class="table-scroll"><table><thead><tr><th>COMPANY</th><th>PLAN & VALIDITY</th><th>ACCESS</th><th>ACTION</th></tr></thead><tbody><tr v-for="client in accessClients" :key="client.id"><td><div class="name-cell"><span class="product-avatar contact-avatar">{{ initial(client.name) }}</span><div><strong>{{ client.name }}</strong><small>{{ client.users_count }} users</small></div></div></td><td><strong>{{ client.plan_name || 'No plan assigned' }}</strong><small class="access-cell-subline">{{ clientValidityLabel(client) }} · {{ client.subscription_ends_at ? date(client.subscription_ends_at) : 'No expiry date' }}</small></td><td><span class="stock-pill" :class="client.access_paused || !client.subscription_active ? 'low' : 'good'">{{ clientAccessLabel(client) }}</span></td><td><button class="btn-secondary-app small access-action" :class="{ 'access-action-danger': !client.access_paused }" :disabled="busy" :aria-label="`${client.access_paused ? 'Restore' : 'Pause'} access for ${client.name}`" @click="setClientAccess(client)"><ShieldCheck v-if="client.access_paused" :size="15" /><AlertTriangle v-else :size="15" /> {{ client.access_paused ? 'Restore access' : 'Pause company' }}</button></td></tr></tbody></table></div><div v-if="!accessClients.length" class="empty-state"><Building2 :size="32" /><strong>No companies found</strong><span>Try another company name.</span></div></section>
          <section class="panel table-panel access-control-panel">
            <div class="table-toolbar"><div><h2>Individual user access</h2><p>{{ accessUsers.length }} owner, manager, and staff accounts shown.</p></div><div class="access-control-filters"><select v-model="accessCompanyFilter" class="filter-select" aria-label="Filter users by company"><option value="all">All companies</option><option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}</option></select><div class="search-box"><Search :size="17" /><input v-model="accessUserSearch" aria-label="Search users for access control" placeholder="Search users..."></div></div></div>
            <div class="table-scroll"><table><thead><tr><th>USER</th><th>COMPANY</th><th>ROLE</th><th>ACCESS</th><th>ACTION</th></tr></thead><tbody><tr v-for="user in accessUsers" :key="user.id"><td><div class="name-cell"><span class="product-avatar">{{ initial(user.name) }}</span><div><strong>{{ user.name }}</strong><small>{{ user.email || user.mobile }}</small></div></div></td><td>{{ user.organization?.name || '—' }}</td><td>{{ roleLabel(user.role) }}</td><td><span class="stock-pill" :class="user.active && !user.access_paused && !user.organization?.access_paused ? 'good' : 'low'">{{ user.access_paused ? 'User paused' : !user.active ? 'Inactive' : user.organization?.access_paused ? 'Company paused' : 'Active' }}</span></td><td><button class="btn-secondary-app small access-action" :class="{ 'access-action-danger': !user.access_paused }" :disabled="busy" :aria-label="`${user.access_paused ? 'Restore' : 'Pause'} ${user.name}'s account`" @click="setUserAccess(user)"><ShieldCheck v-if="user.access_paused" :size="15" /><AlertTriangle v-else :size="15" /> {{ user.access_paused ? 'Restore user' : 'Pause user' }}</button></td></tr></tbody></table></div>
            <div v-if="!accessUsers.length" class="empty-state"><UserCog :size="32" /><strong>No users found</strong><span>Change the company filter or search term.</span></div>
          </section>
        </template>
        <template v-else-if="page === 'profile'">
          <div class="page-heading"><div><span class="eyebrow">MY ACCOUNT</span><h1>My profile</h1><p>Update your personal account details.</p></div><button class="btn-secondary-app" @click="navigate(nav[0]?.id || 'dashboard')">{{ session.permissions.use_workspace ? 'Back to workspace' : 'Back to subscription notice' }}</button></div>
          <div class="profile-layout" :class="{ 'with-subscription': session.user.role === 'admin' }">
            <section class="panel profile-card"><div class="profile-summary"><span class="profile-avatar">{{ initial(session.user.name) }}</span><div><strong>{{ session.user.name }}</strong><span>{{ roleLabel(session.user.role) }}</span><small v-if="session.user.organization?.name">{{ session.user.organization.name }}</small></div></div><form class="form-grid profile-form" @submit.prevent="saveProfile"><label class="field"><span>Full name *</span><input v-model="profileForm.name" required maxlength="100" autocomplete="name"></label><label class="field"><span>Email address</span><input :value="session.user.email" readonly aria-readonly="true"></label><label class="field"><span>Mobile number</span><input v-model="profileForm.mobile" type="tel" maxlength="30" autocomplete="tel" placeholder="e.g. 01700000000"></label><label class="field"><span>Current password</span><input v-model="profileForm.current_password" type="password" :required="!!profileForm.password" autocomplete="current-password" placeholder="Required to change password"></label><label class="field span-2"><span>New password</span><input v-model="profileForm.password" type="password" minlength="8" autocomplete="new-password" placeholder="At least 8 characters"><small>Enter your current password before setting a new password. Leave both password fields empty to keep your current password.</small></label><div class="span-2 profile-actions"><button class="btn-primary-app" :disabled="busy"><UserCog :size="17" /> {{ busy ? 'Saving...' : 'Update profile' }}</button></div></form></section>
            <section v-if="session.user.role === 'admin' && currentSubscription" class="panel subscription-card" :class="{ inactive: !currentSubscription.active }">
              <div class="subscription-card-header"><span class="subscription-card-icon"><CalendarPlus :size="22" /></span><span class="stock-pill" :class="currentSubscription.active ? 'good' : 'low'">{{ subscriptionDisplayStatus }}</span></div>
              <span class="eyebrow">YOUR SUBSCRIPTION</span><h2>{{ currentSubscription.plan_name || 'No plan assigned' }}</h2><p class="subscription-company">{{ session.user.organization?.name }}</p>
              <div class="subscription-facts"><div><span>Valid until</span><strong>{{ currentSubscription.ends_on ? date(currentSubscription.ends_on) : 'No expiry date' }}</strong></div><div><span>Time remaining</span><strong v-if="currentSubscription.days_remaining === null">No fixed expiry</strong><strong v-else-if="currentSubscription.days_remaining > 0">{{ currentSubscription.days_remaining }} {{ currentSubscription.days_remaining === 1 ? 'day left' : 'days left' }}</strong><strong v-else-if="currentSubscription.days_remaining === 0">Expires today</strong><strong v-else>Expired {{ Math.abs(currentSubscription.days_remaining) }} {{ Math.abs(currentSubscription.days_remaining) === 1 ? 'day ago' : 'days ago' }}</strong></div></div>
              <div class="subscription-note" :class="{ warning: !currentSubscription.active || currentSubscription.days_remaining === 0 }"><AlertTriangle v-if="!currentSubscription.active || currentSubscription.days_remaining === 0" :size="17" /><ShieldCheck v-else :size="17" /><p v-if="currentSubscription.access_paused">Company access is paused by the platform superadmin. Your plan and validity date are unchanged.</p><p v-else-if="!currentSubscription.active">Workspace access is paused. Contact the platform superadmin to renew or reactivate your company.</p><p v-else-if="currentSubscription.days_remaining === 0">Your plan is valid through today. Contact the platform superadmin to renew it.</p><p v-else>Your workspace remains available while the subscription is active.</p></div>
            </section>
          </div>
        </template>
        <section v-if="page === 'customers' && hasAbility('sales', 'resales') && customerDueContacts.length" class="panel collection-alert-panel customer-collection-panel"><div class="panel-heading"><div><h2>Collect customer dues</h2><p>Outstanding sales for the selected branch, highest balance first</p></div><span class="stock-pill pending">{{ customerDueContacts.length }} customers</span></div><div class="collection-alert-list"><article v-for="customer in customerDueContacts" :key="customer.id" class="collection-alert-row"><span class="collection-alert-icon overdue"><Users :size="17" /></span><div class="collection-alert-copy"><strong>{{ customer.name }}</strong><span>{{ customer.dueDocuments.length }} unpaid invoices · {{ customer.phone || 'No phone' }}</span></div><strong class="collection-alert-amount">{{ money(customer.branch_due) }}</strong><button class="btn-primary-app small" @click="openSalePayment(customer.dueDocuments[0])">Collect</button></article></div></section>
        <StaffModule v-if="page === 'staff'" :branch-id="activeBranchId" :request="api" :money="money" :date="date" :payment-label="paymentLabel" :language="language" @payroll-recorded="loadExpenses" />
        <SupplierAdvances v-if="page === 'supplier_advances'" :branch-id="activeBranchId" :request="api" :money="money" :date="date" :language="language" :can-view-report="session.permissions.reports.includes('supplier_tt')" />
        <template v-if="page === 'expenses'">
          <div class="page-heading"><div><span class="eyebrow">CASH MANAGEMENT</span><h1>Expenses</h1><p>Record branch operating costs and include them in net profit reporting.</p></div><div class="company-toolbar-actions"><button class="btn-secondary-app" @click="selectReport('profit_loss')"><ChartNoAxesCombined :size="16" /> Net profit report</button><button class="btn-primary-app" @click="openExpense()"><Plus :size="18" /> Add expense</button></div></div>
          <form class="panel expense-filter-panel" @submit.prevent="applyExpenseFilters">
            <div class="expense-filter-grid">
              <label class="field"><span>From</span><input v-model="expenseFilters.date_from" type="date" :max="expenseFilters.date_to || undefined"></label>
              <label class="field"><span>To</span><input v-model="expenseFilters.date_to" type="date" :min="expenseFilters.date_from || undefined"></label>
              <label class="field"><span>Category</span><select v-model="expenseFilters.category"><option value="">All categories</option><option v-for="category in expenseCategories" :key="category" :value="category">{{ translate(category, language) }}</option></select></label>
              <label class="field"><span>Payment method</span><select v-model="expenseFilters.payment_method"><option value="">All methods</option><option value="cash">Cash</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option><option value="bank_transfer">Bank transfer</option></select></label>
              <label class="field"><span>Search</span><input v-model.trim="expenseFilters.search" maxlength="255" placeholder="Description or notes"></label>
              <button class="btn-primary-app expense-filter-button" type="submit"><Search :size="17" /> Apply filters</button>
            </div>
          </form>
          <div class="category-stats">
            <div class="stat-card"><div class="stat-top"><span>Recorded expenses</span><span class="stat-icon violet"><ReceiptText :size="19" /></span></div><strong>{{ expenseSummary.count }}</strong><small>For the selected filters</small></div>
            <div class="stat-card"><div class="stat-top"><span>Total expenses</span><span class="stat-icon orange"><Banknote :size="19" /></span></div><strong>{{ money(expenseSummary.total) }}</strong><small>For the selected filters</small></div>
            <div class="stat-card"><div class="stat-top"><span>Average expense</span><span class="stat-icon blue"><Clock3 :size="19" /></span></div><strong>{{ money(expenseSummary.count ? Number(expenseSummary.total) / expenseSummary.count : 0) }}</strong><small>Per recorded expense</small></div>
          </div>
          <section class="panel table-panel"><div class="table-toolbar"><div><h2>Operating expenses</h2><p>{{ expenseSummary.count }} {{ language === 'bn' ? 'রেকর্ড' : 'records' }} · {{ branches.find(branch => branch.id === Number(activeBranchId))?.name || 'Current branch' }}</p></div></div><div class="table-scroll"><table><thead><tr><th>DATE</th><th>CATEGORY</th><th>DESCRIPTION</th><th>PAYMENT METHOD</th><th>RECORDED BY</th><th>AMOUNT</th><th></th></tr></thead><tbody><tr v-for="expense in expenses" :key="expense.id"><td>{{ date(expense.expense_date) }}</td><td><span class="type-pill">{{ translate(expense.category, language) }}</span></td><td><strong>{{ expense.description }}</strong><small v-if="expense.notes">{{ expense.notes }}</small></td><td>{{ paymentLabel(expense.payment_method) }}</td><td>{{ expense.creator?.name || '—' }}</td><td class="strong">{{ money(expense.amount) }}</td><td><div v-if="!expense.payroll_item_exists" class="row-actions"><button class="icon-button" title="Edit expense" @click="openExpense(expense)"><Pencil :size="17" /></button><button class="icon-button danger" title="Delete expense" :disabled="busy" @click="deleteExpense(expense)"><Trash2 :size="17" /></button></div><span v-else class="type-pill" title="Generated from Staff & Payroll; edit the staff profile for future months">Salary run</span></td></tr></tbody></table></div><div v-if="!expenses.length" class="empty-state"><ReceiptText :size="32" /><strong>No expenses recorded</strong><span>No expenses match the selected filters.</span></div><div v-if="expensePagination.last_page > 1" class="expense-pagination"><button class="btn-secondary-app" :disabled="expensePagination.page <= 1" @click="changeExpensePage(expensePagination.page - 1)">Previous</button><span>{{ language === 'bn' ? 'পৃষ্ঠা' : 'Page' }} {{ expensePagination.page }} {{ language === 'bn' ? 'মোট' : 'of' }} {{ expensePagination.last_page }}</span><button class="btn-secondary-app" :disabled="expensePagination.page >= expensePagination.last_page" @click="changeExpensePage(expensePagination.page + 1)">Next</button></div></section>
        </template>
        <template v-if="page === 'purchase'"><section class="panel table-panel supplier-settlement-list"><div class="table-toolbar"><div><h2>Supplier settlements</h2><p>{{ supplierDueDocuments.length }} unpaid purchase balances</p></div></div><div v-if="supplierDueDocuments.length" class="table-scroll"><table><thead><tr><th>DATE</th><th>PURCHASE</th><th>SUPPLIER</th><th>OUTSTANDING</th><th></th></tr></thead><tbody><tr v-for="purchase in supplierDueDocuments" :key="purchase.id"><td>{{ date(purchase.document_date) }}</td><td class="mono strong">{{ purchase.number }}</td><td>{{ purchase.contact?.name || 'Supplier' }}</td><td class="strong">{{ money(purchase.balance_due) }}</td><td><button class="btn-secondary-app small" @click="openPurchasePayment(purchase)">Settle balance</button></td></tr></tbody></table></div><div v-else class="empty-state compact"><Check :size="28" /><strong>All supplier balances are settled</strong></div></section></template>
      </main>
      <nav v-if="session.user.role !== 'superadmin' && !session.impersonation && session.permissions.use_workspace && !isReportView" class="client-mobile-tabbar" aria-label="Mobile navigation">
        <button v-for="tab in mobileTabs" :key="tab.id" type="button" :class="{ active: page === tab.id }" :aria-current="page === tab.id ? 'page' : undefined" @click="navigate(tab.id)"><component :is="tab.icon" :size="20" /><span>{{ tab.label }}</span></button>
        <button type="button" :class="{ active: mobileOpen || !mobileTabs.some(tab => tab.id === page) }" aria-label="More sections" :aria-expanded="mobileOpen" @click="mobileOpen = true"><Menu :size="20" /><span>More</span></button>
      </nav>
      <button v-if="canShowInstall && !modal && !mobileOpen && !isReportView" type="button" class="install-app-floating" @click="installApp"><Download :size="18" aria-hidden="true" /><span>{{ language === 'bn' ? 'অ্যাপ ইনস্টল করুন' : 'Install App' }}</span></button>
    </div>

    <div v-if="modal === 'system_update'" class="modal-backdrop-app" role="dialog" aria-modal="true" aria-labelledby="system-update-title">
      <div class="modal-card">
        <div class="modal-head"><div><span class="eyebrow">SYSTEM UPDATE</span><h2 id="system-update-title">Database update available</h2></div><button class="icon-button" type="button" aria-label="Close update dialog" :disabled="busy" @click="closeModal()"><X :size="20" /></button></div>
        <div v-if="error" class="alert-box error">{{ error }}</div>
        <p>The deployed code has {{ systemUpdateStatus?.total }} pending migration{{ systemUpdateStatus?.total === 1 ? '' : 's' }}: {{ systemUpdateStatus?.central }} for the main database and {{ systemUpdateStatus?.tenants }} across {{ systemUpdateStatus?.companies }} compan{{ systemUpdateStatus?.companies === 1 ? 'y' : 'ies' }}.</p>
        <p>Back up the main and company databases first. This runs pending migrations with <code>--force</code>; it does not delete and recreate databases. Large updates may take longer than a web request allows.</p>
        <label class="field checkbox-field"><input v-model="systemUpdateConfirmed" type="checkbox" :disabled="busy"><span>I have backed up the databases and want to run this update.</span></label>
        <div class="modal-actions"><button type="button" class="btn-secondary-app" :disabled="busy" @click="closeModal()">Later</button><button type="button" class="btn-primary-app" :disabled="busy || !systemUpdateConfirmed" @click="applySystemUpdate()">{{ busy ? 'Updating...' : 'Update databases' }}</button></div>
      </div>
    </div>
    <div v-if="modal && modal !== 'system_update'" class="modal-backdrop-app" @click.self="closeModal()"><div class="modal-card" :class="{ wide: ['document', 'client_details', 'invoice', 'stock_check', 'stock_transfer', 'user'].includes(modal) }"><div class="modal-head"><div><span class="eyebrow">{{ modal === 'document' ? 'NEW TRANSACTION' : modal === 'invoice' ? 'INVOICE' : modal === 'ledger_expense' ? 'EXPENSE DOCUMENT' : modal === 'ledger_cash_movement' ? 'CASH MOVEMENT' : modal === 'stock_check' ? 'PHYSICAL COUNT' : modal === 'stock_transfer' ? 'BRANCH INVENTORY' : modal === 'barcode' ? 'PRODUCT LABEL' : modal === 'category' ? 'CATALOG SETUP' : ['branch', 'client_branch'].includes(modal) ? 'COMPANY LOCATION' : modal === 'adjustment' ? 'STOCK CONTROL' : modal === 'client_details' ? 'COMPANY DETAILS' : ['client', 'user'].includes(modal) ? 'ACCESS MANAGEMENT' : 'DETAILS' }}</span><h2>{{ modal === 'product' ? (editingId ? 'Edit product' : 'Add product') : modal === 'category' ? (editingId ? 'Edit category' : 'Add category') : ['branch', 'client_branch'].includes(modal) ? (modal === 'client_branch' ? 'Add branch' : 'Edit branch') : modal === 'stock_check' ? selectedStockCheck?.number : modal === 'stock_transfer' ? 'New stock transfer' : modal === 'barcode' ? 'Print barcode' : modal === 'contact' ? (editingId ? 'Edit contact' : documentCreateContext === 'contact' ? (relatedContactName === 'supplier' ? 'Add supplier' : 'Add customer') : 'Add contact') : modal === 'adjustment' ? 'Adjust stock' : modal === 'client_provision' ? 'Initialize company database' : modal === 'client' ? (editingId ? 'Edit company' : 'Add company') : modal === 'user' ? (editingId ? 'Edit user' : 'Add user') : modal === 'client_details' ? selectedClient?.name : modal === 'invoice' ? selectedDocument?.number : modal === 'ledger_expense' ? ledgerExpense?.reference : modal === 'ledger_cash_movement' ? ledgerCashMovement?.reference : `New ${label(documentForm.type).toLowerCase()}` }}</h2></div><button class="icon-button" @click="closeModal()"><X :size="20" /></button></div><div v-if="error" class="alert-box error">{{ error }}</div>
      <form v-if="modal === 'expense'" @submit.prevent="saveExpense"><div class="form-grid"><label class="field"><span>Expense date *</span><input v-model="expenseForm.expense_date" type="date" required></label><label class="field"><span>Category *</span><select v-model="expenseForm.category" required><option v-for="category in expenseCategoryOptions" :key="category" :value="category">{{ translate(category, language) }}</option><option value="__new__">+ {{ language === 'bn' ? 'নতুন ক্যাটাগরি যোগ করুন' : 'Add new category' }}</option></select><input v-if="expenseForm.category === '__new__'" v-model.trim="newExpenseCategory" maxlength="100" required :placeholder="language === 'bn' ? 'নতুন ক্যাটাগরির নাম' : 'New category name'" class="expense-new-category-input"></label><label class="field span-2"><span>Description *</span><input v-model="expenseForm.description" maxlength="255" required placeholder="What was this expense for?"></label><label class="field"><span>Amount *</span><input v-model="expenseForm.amount" type="number" min="0.01" step="0.01" required><small v-if="!editingId && session.operational_setup?.store?.allow_cash_handover && ['manager', 'staff'].includes(session.user?.role) && overview.cash">{{ language === 'bn' ? 'খরচের জন্য অবশিষ্ট পরিশোধিত ব্যালেন্স:' : 'Available paid balance for expenses:' }} {{ money(overview.cash.paid_balance) }}</small></label><label class="field"><span>Payment method *</span><select v-model="expenseForm.payment_method" required><option value="cash">Cash</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option><option value="bank_transfer">Bank transfer</option></select></label><label class="field span-2"><span>Notes</span><textarea v-model="expenseForm.notes" rows="3" maxlength="1000" placeholder="Optional receipt or reference details"></textarea></label></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Saving...' : editingId ? 'Update expense' : 'Record expense' }}</button></div></form>
      <div v-else-if="modal === 'ledger_cash_movement' && ledgerCashMovement" class="ledger-expense-details"><div><span>Date &amp; time</span><strong>{{ `${date(ledgerCashMovement.created_at?.slice(0, 10))} · ${time(ledgerCashMovement.created_at)}` }}</strong></div><div><span>Movement</span><strong>{{ translate(ledgerCashMovement.type === 'transfer' ? 'Staff transfer' : 'Owner withdrawal', language) }}</strong></div><div><span>From</span><strong>{{ ledgerCashMovement.from || '—' }}</strong></div><div><span>To</span><strong>{{ ledgerCashMovement.to || '—' }}</strong></div><div><span>Amount</span><strong>{{ money(ledgerCashMovement.amount) }}</strong></div><div v-if="ledgerCashMovement.notes" class="ledger-expense-notes"><span>Notes</span><strong>{{ ledgerCashMovement.notes }}</strong></div><div class="modal-actions"><button type="button" class="btn-primary-app" @click="closeModal()">Close</button></div></div>
      <div v-else-if="modal === 'ledger_expense' && ledgerExpense" class="ledger-expense-details"><div><span>Date</span><strong>{{ date(ledgerExpense.expense_date) }}</strong></div><div><span>Category</span><strong>{{ translate(ledgerExpense.category, language) }}</strong></div><div><span>Description</span><strong>{{ ledgerExpense.description }}</strong></div><div><span>Payment method</span><strong>{{ paymentLabel(ledgerExpense.payment_method) }}</strong></div><div><span>Amount</span><strong>{{ money(ledgerExpense.amount) }}</strong></div><div v-if="ledgerExpense.notes" class="ledger-expense-notes"><span>Notes</span><strong>{{ ledgerExpense.notes }}</strong></div><div class="modal-actions"><button type="button" class="btn-primary-app" @click="closeModal()">Close</button></div></div>
      <form v-else-if="modal === 'sale_payment'" @submit.prevent="recordSalePayment"><div class="payment-plan-note"><strong>{{ saleBeingPaid?.contact?.name || 'Customer' }}</strong> · {{ saleBeingPaid?.number }}<br><span>{{ salePaymentTypeLabel(saleBeingPaid?.payment_type) }}</span> · Remaining due: <strong>{{ money(saleBeingPaid?.balance_due) }}</strong></div><div class="form-grid"><label v-if="saleBeingPaid?.payment_type === 'installment'" class="field span-2"><span>Installment *</span><select v-model="salePaymentForm.sale_installment_id" required @change="setSaleInstallmentAmount"><option v-for="installment in saleBeingPaid.installments.filter(item => Number(item.balance_due) > 0)" :key="installment.id" :value="installment.id">#{{ installment.sequence }} · Due {{ date(installment.due_date) }} · {{ money(installment.balance_due) }} remaining</option></select></label><label class="field"><span>Payment amount *</span><input v-model="salePaymentForm.amount" type="number" min="0.01" :max="salePaymentForm.sale_installment_id ? saleBeingPaid?.installments.find(item => Number(item.id) === Number(salePaymentForm.sale_installment_id))?.balance_due : saleBeingPaid?.balance_due" step="0.01" required></label><label class="field"><span>Payment date *</span><input v-model="salePaymentForm.payment_date" type="date" required></label><label class="field"><span>Payment method *</span><select v-model="salePaymentForm.payment_method" required><option value="cash">Cash</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option><option value="bank_transfer">Bank transfer</option></select></label><label class="field"><span>Notes</span><input v-model="salePaymentForm.notes" maxlength="1000" placeholder="Optional reference"></label></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Recording...' : 'Record customer payment' }}</button></div></form>
      <form v-else-if="modal === 'purchase_payment'" @submit.prevent="recordPurchasePayment"><div class="payment-plan-note"><strong>{{ purchaseBeingPaid?.contact?.name || purchaseBeingPaid?.supplier }}</strong> · {{ purchaseBeingPaid?.number }}<br><span>{{ salePaymentTypeLabel(purchaseBeingPaid?.payment_type) }}</span> · Remaining payable to supplier: <strong>{{ money(purchaseBeingPaid?.balance_due ?? purchaseBeingPaid?.amount_due) }}</strong></div><div class="form-grid"><label v-if="purchaseBeingPaid?.payment_type === 'installment'" class="field span-2"><span>Installment *</span><select v-model="purchasePaymentForm.purchase_installment_id" required @change="setPurchaseInstallmentAmount"><option v-for="installment in purchaseBeingPaid.purchaseInstallments.filter(item => Number(item.balance_due) > 0)" :key="installment.id" :value="installment.id">#{{ installment.sequence }} · Due {{ date(installment.due_date) }} · {{ money(installment.balance_due) }} remaining</option></select></label><label class="field"><span>Payment amount *</span><input v-model="purchasePaymentForm.amount" type="number" min="0.01" :max="purchasePaymentForm.purchase_installment_id ? purchaseBeingPaid?.purchaseInstallments.find(item => Number(item.id) === Number(purchasePaymentForm.purchase_installment_id))?.balance_due : purchaseBeingPaid?.balance_due ?? purchaseBeingPaid?.amount_due" step="0.01" required></label><label class="field"><span>Payment date *</span><input v-model="purchasePaymentForm.payment_date" type="date" required></label><label class="field"><span>Payment method *</span><select v-model="purchasePaymentForm.payment_method" required><option value="cash">Cash</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option><option value="bank_transfer">Bank transfer</option></select></label><label class="field"><span>Notes</span><input v-model="purchasePaymentForm.notes" maxlength="1000" placeholder="Optional reference"></label></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Recording...' : 'Record supplier payment' }}</button></div></form>
      <form v-else-if="modal === 'stock_transfer'" class="stock-transfer-sheet" @submit.prevent="saveStockTransfer">
        <div class="stock-transfer-route"><div><span>FROM BRANCH</span><strong>{{ branches.find(branch => branch.id === Number(activeBranchId))?.name }}</strong><small>{{ stockProducts.filter(product => product.active && Number(product.quantity_on_hand) > 0).length }} products available</small></div><ArrowLeftRight :size="24" /><label><span>TO BRANCH *</span><select v-model="stockTransferForm.to_branch_id" required><option value="" disabled>Select destination</option><option v-for="branch in transferDestinations" :key="branch.id" :value="branch.id">{{ branch.name }} · {{ branch.code }}</option></select></label></div>
        <div class="form-grid transfer-details"><label class="field"><span>Transfer date *</span><input v-model="stockTransferForm.transfer_date" type="date" required></label><label class="field"><span>Notes</span><input v-model="stockTransferForm.notes" maxlength="2000" placeholder="Optional transfer notes"></label></div>
        <div class="items-heading"><div><h3>Products to transfer</h3><p>Quantities are taken from the currently selected source branch.</p></div><button type="button" class="btn-secondary-app small" @click="stockTransferForm.items.push({ product_id: '', quantity: 1 })"><Plus :size="16" /> Add line</button></div>
        <div class="items-list"><div v-for="(item, index) in stockTransferForm.items" :key="index" class="transfer-item-row"><label class="field"><span>Product *</span><select v-model="item.product_id" required><option value="" disabled>Select stocked product</option><option v-for="product in products.filter(product => product.active && Number(product.quantity_on_hand) > 0)" :key="product.id" :value="product.id">{{ productOption(product) }} · {{ qty(product.quantity_on_hand) }} {{ product.unit }}</option></select></label><label class="field"><span>Quantity *</span><input v-model="item.quantity" type="number" :min="['pc', 'pcs'].includes(products.find(product => product.id === Number(item.product_id))?.unit) ? 1 : 0.001" :max="products.find(product => product.id === Number(item.product_id))?.quantity_on_hand" :step="['pc', 'pcs'].includes(products.find(product => product.id === Number(item.product_id))?.unit) ? 1 : 0.001" required><small v-if="item.product_id">Available: {{ qty(products.find(product => product.id === Number(item.product_id))?.quantity_on_hand) }} {{ products.find(product => product.id === Number(item.product_id))?.unit }}</small></label><button type="button" class="icon-button remove-line" title="Remove line" :disabled="stockTransferForm.items.length === 1" @click="stockTransferForm.items.splice(index, 1)"><Trash2 :size="17" /></button></div></div>
        <div v-if="!products.some(product => product.active && Number(product.quantity_on_hand) > 0)" class="alert-box error">The current branch has no available stock to transfer.</div>
        <div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy || !stockTransferCanSave"><ArrowLeftRight :size="17" /> {{ busy ? 'Transferring...' : 'Complete transfer' }}</button></div>
      </form>
      <form v-else-if="modal === 'sales_target'" @submit.prevent="saveSalesTarget"><div class="form-grid"><label class="field"><span>Branch *</span><select v-model="salesTargetForm.branch_id" required><option value="" disabled>Select branch</option><option v-for="branch in branches.filter(branch => branch.active)" :key="branch.id" :value="branch.id">{{ branch.name }} · {{ branch.code }}</option></select></label><label class="field"><span>Month *</span><input v-model="salesTargetForm.period" type="month" required></label><label class="field"><span>Assigned employee</span><select v-model="salesTargetForm.user_id"><option value="">Whole branch</option><option v-for="user in salesTargetUsers" :key="user.id" :value="user.id">{{ user.name }} · {{ user.email }}</option></select><small>Leave empty to track the whole branch.</small></label><label class="field"><span>Sales target *</span><input v-model="salesTargetForm.target_amount" type="number" min="0.01" step="0.01" required placeholder="0.00"></label><label class="field span-2"><span>Notes</span><textarea v-model="salesTargetForm.notes" rows="3" maxlength="1000" placeholder="Optional target context or campaign notes"></textarea></label></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy"><Target :size="17" /> {{ busy ? 'Saving...' : editingId ? 'Save target' : 'Set target' }}</button></div></form>
      <div v-else-if="modal === 'stock_check' && selectedStockCheck" class="stock-check-sheet">
        <div class="stock-check-meta"><div><span>Branch</span><strong>{{ selectedStockCheck.branch?.name }}</strong></div><label><span>Count date</span><input v-model="stockCheckForm.check_date" type="date" :disabled="selectedStockCheck.status === 'completed'"></label><div><span>Status</span><strong><span class="stock-pill" :class="selectedStockCheck.status === 'completed' ? 'good' : 'pending'">{{ selectedStockCheck.status === 'completed' ? 'Completed' : 'Draft' }}</span></strong></div><div><span>Total variance</span><strong class="stock-check-variance" :class="{ positive: stockCheckVariance > 0, negative: stockCheckVariance < 0 }">{{ stockCheckVariance > 0 ? '+' : '' }}{{ qty(stockCheckVariance) }}</strong></div></div>
        <div class="stock-check-table-wrap"><table class="stock-check-table"><thead><tr><th>SL</th><th>PRODUCT</th><th>EXPECTED</th><th>PHYSICAL COUNT</th><th>VARIANCE</th></tr></thead><tbody><tr v-for="(item, index) in stockCheckForm.items" :key="item.id"><td>{{ index + 1 }}</td><td><div class="name-cell"><span class="product-avatar">{{ initial(item.product?.name) }}</span><div><strong>{{ item.product?.name }}</strong><small>{{ item.product?.sku || 'No SKU' }}</small></div></div></td><td>{{ qty(item.expected_quantity) }} {{ item.product?.unit }}</td><td><input v-model="item.counted_quantity" class="stock-count-input" type="number" min="0" :step="['pc', 'pcs'].includes(item.product?.unit) ? 1 : 0.001" placeholder="Enter count" :disabled="selectedStockCheck.status === 'completed'"></td><td><span v-if="item.counted_quantity !== ''" class="stock-check-variance" :class="{ positive: Number(item.counted_quantity) - Number(item.expected_quantity) > 0, negative: Number(item.counted_quantity) - Number(item.expected_quantity) < 0 }">{{ Number(item.counted_quantity) - Number(item.expected_quantity) > 0 ? '+' : '' }}{{ qty(Number(item.counted_quantity) - Number(item.expected_quantity)) }}</span><span v-else>—</span></td></tr></tbody></table></div>
        <label class="field stock-check-notes"><span>Count notes</span><textarea v-model="stockCheckForm.notes" rows="3" maxlength="2000" placeholder="Optional notes about this physical count" :disabled="selectedStockCheck.status === 'completed'"></textarea></label>
        <div v-if="selectedStockCheck.status === 'draft'" class="stock-check-completion"><div><strong>{{ uncountedStockItems ? `${uncountedStockItems} products still need a count` : 'Ready to complete' }}</strong><span>{{ uncountedStockItems ? 'Enter a physical quantity for every product.' : 'Completing will reconcile branch stock and create an audit movement for each variance.' }}</span></div></div>
        <div class="modal-actions"><button class="btn-secondary-app" @click="closeModal()">Close</button><template v-if="selectedStockCheck.status === 'draft'"><button class="btn-secondary-app" :disabled="busy" @click="saveStockCheck()">{{ busy ? 'Saving...' : 'Save draft' }}</button><button class="btn-primary-app" :disabled="busy || uncountedStockItems > 0" @click="completeStockCheck"><Check :size="17" /> {{ busy ? 'Completing...' : 'Complete and reconcile' }}</button></template></div>
      </div>
      <div v-else-if="modal === 'barcode' && barcodeProduct" class="barcode-sheet">
        <div class="barcode-print-controls">
          <label class="field"><span>{{ translate('Number of barcode labels', language) }} *</span><input v-model="barcodeQuantityInput" type="number" min="1" max="1000" step="1" inputmode="numeric" required></label>
          <p>{{ qty(barcodeProduct.quantity_on_hand) }} {{ barcodeProduct.unit }} {{ translate('on hand in this branch', language) }}. {{ translate('Printing labels does not change stock.', language) }}</p>
        </div>
        <div v-if="barcodeLabelCount" class="barcode-label-grid"><div v-for="copy in barcodeLabelCount" :key="copy" class="barcode-label"><strong>{{ barcodeProduct.name }}</strong><small v-if="barcodeProduct.sku">SKU: {{ barcodeProduct.sku }}</small><div class="barcode-art" v-html="barcodeMarkup"></div><span>{{ money(barcodeProduct.sale_price) }}</span></div></div>
        <p v-else>{{ translate('Enter a whole number of labels between 1 and 1000.', language) }}</p>
        <div class="modal-actions"><button class="btn-secondary-app" @click="closeModal()">Close</button><button class="btn-primary-app" :disabled="busy || !barcodeLabelCount" @click="printBarcode"><Printer :size="17" /> {{ busy ? translate('Preparing labels...', language) : `${translate('Print', language)} ${qty(barcodeLabelCount)} ${translate(barcodeLabelCount === 1 ? 'barcode' : 'barcodes', language)}` }}</button></div>
      </div>
      <div v-else-if="modal === 'invoice' && selectedDocument" :class="['invoice-sheet', { 'invoice-sheet-pos': selectedDocument.sale_channel === 'pos' }]"><PosReceipt v-if="selectedDocument.sale_channel === 'pos'" :document="selectedDocument" :organization-name="session.user.organization?.name || 'Uddog Inventory'" :cashier-name="selectedDocument.creator?.name?.trim() || '—'" :installments="invoiceInstallmentRows" :money="money" :qty="qty" :date="date" :weekday="weekday" :document-date-time="documentDateTime" :payment-label="paymentLabel" :payment-type-label="salePaymentTypeLabel" /><div class="invoice-company"><div><span class="brand-mark">U</span><div><h3>{{ session.user.organization?.name || 'Uddog Inventory' }}</h3><p>Inventory management invoice</p></div></div><span class="invoice-type">{{ selectedDocument.sale_channel === 'pos' ? 'POS SALE' : label(selectedDocument.type) }}</span></div><div class="invoice-meta"><div><span>Invoice number</span><strong>{{ selectedDocument.number }}</strong></div><div><span>Date &amp; time</span><strong>{{ documentDateTime(selectedDocument) }}</strong></div><div><span>{{ ['purchase', 'purchase_return'].includes(selectedDocument.type) ? 'Supplier' : 'Customer' }}</span><strong>{{ selectedDocument.contact?.name }}</strong><small v-if="selectedDocument.contact?.phone">{{ selectedDocument.contact.phone }}</small></div><div><span>Processed by</span><strong>{{ selectedDocument.creator?.name?.trim() || '—' }}</strong></div></div><div class="invoice-table-wrap"><table class="invoice-table"><thead><tr><th>SL</th><th>PRODUCT</th><th>QTY</th><th>UNIT PRICE</th><th>AMOUNT</th></tr></thead><tbody><tr v-for="(item, index) in selectedDocument.items" :key="item.id"><td>{{ index + 1 }}</td><td><strong>{{ item.product?.name }}</strong><small v-if="item.product?.sku">{{ item.product.sku }}</small></td><td>{{ qty(item.entered_quantity ?? item.quantity) }} {{ item.entered_unit || item.product?.unit }}</td><td>{{ money(item.entered_unit_price ?? item.unit_price) }}</td><td>{{ money(item.line_total) }}</td></tr></tbody></table></div><div class="invoice-summary"><div v-if="selectedDocument.notes" class="invoice-notes"><strong>Notes</strong><p>{{ selectedDocument.notes }}</p></div><div class="invoice-totals"><div><span>Subtotal</span><strong>{{ money(selectedDocument.subtotal) }}</strong></div><div><span>Discount</span><strong>{{ money(selectedDocument.discount) }}</strong></div><div><span>Tax</span><strong>{{ money(selectedDocument.tax) }}</strong></div><div v-if="Number(selectedDocument.interest_amount) > 0"><span>{{ translate('Interest charge', language) }} ({{ selectedDocument.interest_rate }}%)</span><strong>{{ money(selectedDocument.interest_amount) }}</strong></div><div class="invoice-grand-total"><span>Total</span><strong>{{ money(selectedDocument.total) }}</strong></div><template v-if="['sale', 'resale'].includes(selectedDocument.type)"><div><span>Sale payment type</span><strong>{{ salePaymentTypeLabel(selectedDocument.payment_type) }}</strong></div><div><span>Paid</span><strong>{{ money(selectedDocument.amount_paid) }}</strong></div><div><span>Balance due</span><strong>{{ money(selectedDocument.balance_due) }}</strong></div></template><template v-if="selectedDocument.sale_channel === 'pos'"><div><span>Payment method</span><strong>{{ paymentLabel(selectedDocument.payment_method) }}</strong></div></template></div></div><section v-if="session.user.role === 'admin' && ['purchase', 'sale', 'resale'].includes(selectedDocument.type) && (selectedDocument.type === 'purchase' ? selectedDocument.purchase_payments?.length : selectedDocument.payments?.length)" class="invoice-payment-management"><strong>Related payments</strong><p>Remove payments from newest to oldest before deleting this invoice.</p><div v-for="payment in (selectedDocument.type === 'purchase' ? selectedDocument.purchase_payments : selectedDocument.payments)" :key="payment.id" class="invoice-payment-row"><span>{{ date(payment.payment_date) }} · {{ paymentLabel(payment.payment_method) }}</span><strong>{{ money(payment.amount) }}</strong><button type="button" class="icon-button danger" :title="translate('Delete payment', language)" :aria-label="translate('Delete payment', language)" :disabled="busy" @click="deleteDocumentPayment(payment)"><Trash2 :size="16" /></button></div></section><section v-if="selectedDocument.payment_type === 'installment' && invoiceInstallmentRows.length" class="invoice-installment-plan"><div class="installment-plan-head"><strong>Installment payment schedule</strong><small>{{ invoiceInstallmentRows.length }} payments · {{ frequencyLabel(selectedDocument.installment_frequency) }}</small></div><div class="invoice-plan-summary"><span v-if="selectedDocument.installment_start_date">Start date: <strong>{{ date(selectedDocument.installment_start_date) }}</strong></span><span>First due: <strong>{{ date(invoiceInstallmentRows[0].due_date) }}</strong></span><span>Financed amount: <strong>{{ money(Number(selectedDocument.total) - Number(selectedDocument.down_payment) - Number(selectedDocument.gold_exchange_value || 0) - Number(selectedDocument.interest_amount || 0)) }}</strong></span><span>Interest: <strong>{{ money(selectedDocument.interest_amount) }}</strong></span></div><div class="installment-plan-table-wrap"><table class="installment-plan-table"><thead><tr><th>#</th><th>Due date</th><th>Day</th><th>Principal</th><th>Interest</th><th>Installment</th><th>Paid</th><th>Remaining</th></tr></thead><tbody><tr v-for="row in invoiceInstallmentRows" :key="row.id"><td>{{ row.sequence }}</td><td>{{ date(row.due_date) }}</td><td>{{ weekday(row.due_date) }}</td><td>{{ money(row.principal) }}</td><td>{{ money(row.interest) }}</td><td><strong>{{ money(row.amount) }}</strong></td><td>{{ money(row.amount_paid) }}</td><td>{{ money(row.balance_due) }}</td></tr></tbody></table></div></section><div class="invoice-footer">Thank you for your business.</div><p v-if="selectedDocument.sale_channel === 'pos'" class="receipt-print-hint">POS receipt is formatted for 80 mm paper. Select your receipt printer and 80 mm paper in the print dialog.</p><div class="modal-actions"><button class="btn-secondary-app" @click="closeModal()">Close</button><button v-if="hasAbility('sales', 'resales') && ['sale', 'resale'].includes(selectedDocument.type) && Number(selectedDocument.balance_due) > 0" class="btn-primary-app" @click="openSalePayment(selectedDocument)"><Banknote :size="17" /> Collect {{ money(selectedDocument.balance_due) }}</button><button class="btn-primary-app" @click="printInvoice"><Printer :size="17" /> {{ selectedDocument.sale_channel === 'pos' ? 'Print POS receipt' : 'Print invoice' }}</button></div></div>
      <form v-else-if="modal === 'product'" @submit.prevent="saveProduct"><div class="form-grid"><label class="field"><span>Product name *</span><input v-model="productForm.name" required placeholder="e.g. Wireless Mouse"></label><label class="field"><span>SKU (optional)</span><input v-model="productForm.sku" placeholder="e.g. WM-001"></label><div class="field span-2"><span>EAN-13 barcode (optional)</span><div class="field-action"><input v-model="productForm.barcode" inputmode="numeric" pattern="\d{13}" maxlength="13" placeholder="13-digit product barcode"><button type="button" class="btn-secondary-app small create-inline" @click="generateBarcode"><Barcode :size="16" /> Generate</button></div><small>Use an existing EAN-13 code or generate a unique label for this product.</small></div><div class="field"><span>Category</span><div class="field-action"><select v-model="productForm.category_id"><option value="">Uncategorized</option><option v-for="category in selectableCategories" :key="category.id" :value="category.id">{{ category.name }}{{ category.active ? '' : ' (inactive)' }}</option></select><button v-if="hasAbility('categories')" type="button" class="btn-secondary-app small create-inline" @click="openCategory(null, 'product_category')"><Plus :size="16" /> New</button></div></div><label v-if="editingId" class="field"><span>Unit *</span><select v-model="productForm.unit" required><option v-for="unit in unitOptions" :key="unit.value" :value="unit.value">{{ unit.label }}</option></select></label><label class="field"><span>{{ editingId ? 'Purchase Cost per selected unit *' : 'Purchase Cost per piece *' }}</span><input v-model="productForm.cost_price" type="number" min="0" step="0.01" required placeholder="Cost of one piece"><small v-if="!editingId">Enter the purchase cost of a single piece, not a pack or dozen.</small><small v-else>The average cost updates when purchases are recorded.</small></label><label class="field"><span>{{ editingId ? 'Sale Price per selected unit *' : 'Sale Price per piece *' }}</span><input v-model="productForm.sale_price" type="number" min="0" step="0.01" required placeholder="Sale price of one piece"><small v-if="!editingId">Enter the sale price of a single piece, not a pack or dozen.</small><small v-else>You can enter a different price on every sale.</small></label><label v-if="session.shared_stock_sales && !productForm.is_gold_item" class="field span-2"><span>মূল স্টক পণ্য / Stock source</span><select v-model="productForm.stock_source_product_id"><option value="">নিজস্ব মজুদ / Own stock</option><option v-for="source in products.filter(candidate => candidate.id !== editingId && !candidate.stock_source_product_id && !candidate.is_gold_item)" :key="source.id" :value="source.id">{{ source.name }}</option></select><small>এই বিক্রয় পণ্যটি বিক্রি হলে নির্বাচিত মূল পণ্যের মজুদ কমবে। সিদ্ধ ডিমের জন্য আলাদা প্রস্তুত মজুদ তৈরি হবে না।</small></label><label v-if="session.shared_stock_sales && productForm.stock_source_product_id" class="field"><span>প্রতি বিক্রয় ইউনিটে মূল পণ্যের পরিমাণ *</span><input v-model="productForm.stock_source_quantity" type="number" min="0.001" step="0.001" required><small>১টি বিক্রয় পণ্যের জন্য ১টি ডিম লাগলে 1 লিখুন।</small></label><label class="field"><span>Reorder level *</span><input v-model="productForm.reorder_level" type="number" min="0" step="0.001" required></label><label class="field"><span>Warranty period (months)</span><input v-model="productForm.warranty_months" type="number" min="0" max="120" step="1" placeholder="No warranty"><small>This term is captured on each sale.</small></label><label v-if="session.gold_store" class="field checkbox-field span-2"><input v-model="productForm.is_gold_item" type="checkbox"><span>Track as a gold/jewelry item</span></label><template v-if="session.gold_store && productForm.is_gold_item"><label class="field"><span>Purity / karat *</span><select v-model="productForm.gold_purity" required><option value="24K">24K</option><option value="22K">22K</option><option value="21K">21K</option><option value="18K">18K</option><option value="14K">14K</option><option value="9K">9K</option></select></label><label class="field"><span>Gross weight (g) *</span><input v-model="productForm.gold_gross_weight" type="number" min="0.001" step="0.001" required></label><label class="field"><span>Stone weight (g)</span><input v-model="productForm.gold_stone_weight" type="number" min="0" step="0.001"></label><label class="field"><span>Other non-metal weight (g)</span><input v-model="productForm.gold_other_weight" type="number" min="0" step="0.001"></label><label class="field"><span>Stone value</span><input v-model="productForm.stone_value" type="number" min="0" step="0.01"></label><label class="field"><span>Making-charge method</span><select v-model="productForm.making_charge_mode"><option value="per_gram">Amount per net gram</option><option value="percentage">Percentage of metal value</option><option value="flat">Flat amount per piece</option></select></label><label class="field"><span>Making charge</span><input v-model="productForm.making_charge" type="number" min="0" step="0.01"></label><label class="field"><span>Wastage (%)</span><input v-model="productForm.wastage_percent" type="number" min="0" max="100" step="0.001"></label><label class="field"><span>Item tag / barcode reference</span><input v-model="productForm.tag_number" maxlength="80"></label><label class="field"><span>Hallmark / HUID</span><input v-model="productForm.hallmark_code" maxlength="80"></label><p class="payment-plan-note span-2">Net metal weight {{ Number(productForm.gold_gross_weight || 0) - Number(productForm.gold_stone_weight || 0) - Number(productForm.gold_other_weight || 0) }} g. Gold sales use the current rate for this purity plus wastage, making charge, and stone value.</p></template><label class="field checkbox-field"><input v-model="productForm.active" type="checkbox" :disabled="documentCreateContext === 'product'"><span>Active product</span></label></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Saving...' : 'Save product' }}</button></div></form>
      <form v-else-if="modal === 'category'" @submit.prevent="saveCategory"><div class="form-grid"><label class="field span-2"><span>Category name *</span><input v-model="categoryForm.name" required maxlength="100" placeholder="e.g. Accessories"></label><label class="field span-2"><span>Description</span><textarea v-model="categoryForm.description" rows="3" maxlength="1000" placeholder="Optional notes about products in this category"></textarea></label><label class="field checkbox-field span-2"><input v-model="categoryForm.active" type="checkbox"><span>Active category</span></label></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Saving...' : documentCreateContext === 'product_category' ? 'Save and select category' : 'Save category' }}</button></div></form>
      <form v-else-if="['branch', 'client_branch'].includes(modal)" @submit.prevent="modal === 'client_branch' ? saveClientBranch() : saveBranch()"><div class="form-grid"><label class="field"><span>Branch name *</span><input v-model="branchForm.name" required maxlength="100" placeholder="e.g. Uttara Store"></label><label class="field"><span>Branch code *</span><input v-model="branchForm.code" required maxlength="30" pattern="[A-Za-z0-9_-]+" placeholder="e.g. UTTARA"></label><label class="field span-2"><span>Phone</span><input v-model="branchForm.phone" maxlength="50" placeholder="Branch contact number"></label><label class="field span-2"><span>Address</span><textarea v-model="branchForm.address" rows="3" maxlength="1000" placeholder="Branch street and area"></textarea></label><label class="field checkbox-field"><input v-model="branchForm.active" type="checkbox" :disabled="branchForm.is_default"><span>Active branch</span></label><label class="field checkbox-field"><input v-model="branchForm.is_default" type="checkbox"><span>Default branch</span></label></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Saving...' : modal === 'client_branch' ? 'Add branch' : 'Save branch' }}</button></div></form>
      <form v-else-if="modal === 'contact'" @submit.prevent="saveContact"><div class="form-grid"><label class="field"><span>Contact name *</span><input v-model="contactForm.name" required placeholder="Company or person"></label><label class="field"><span>Contact type *</span><select v-model="contactForm.type"><option v-if="documentCreateContext !== 'contact' || documentForm.type !== 'purchase'" value="customer">Customer</option><option v-if="documentCreateContext !== 'contact' || documentForm.type === 'purchase'" value="supplier">Supplier</option><option value="both">Both customer and supplier</option></select></label><label class="field"><span>Company</span><input v-model="contactForm.company" maxlength="255" placeholder="Business or company name"></label><label class="field"><span>Phone</span><input v-model="contactForm.phone" maxlength="50" placeholder="Mobile number"></label><label class="field"><span>Email</span><input v-model="contactForm.email" type="email" placeholder="name@company.com"></label><label class="field"><span>Area</span><input v-model="contactForm.area" maxlength="100" placeholder="e.g. Uttara"></label><label class="field span-2"><span>Address</span><textarea v-model="contactForm.address" rows="2" maxlength="1000" placeholder="Street, city"></textarea></label><label class="field"><span>Category</span><input v-model="contactForm.category" maxlength="100" placeholder="e.g. Retail, wholesale"></label><label class="field"><span>Credit limit</span><input v-model="contactForm.credit_limit" type="number" min="0" step="0.01" placeholder="0.00"></label><label v-if="!editingId && ['customer', 'both'].includes(contactForm.type)" class="field"><span>Existing due amount (optional)</span><input v-model="contactForm.opening_due" type="number" min="0" step="0.01" placeholder="0.00"><small>Any amount this customer already owes your store.</small></label><label v-if="!editingId && ['supplier', 'both'].includes(contactForm.type)" class="field"><span>Existing amount owed to supplier</span><input v-model="contactForm.opening_payable" type="number" min="0" step="0.01" placeholder="0.00"><small>Any amount your store already owes this supplier.</small></label><label class="field checkbox-field"><input v-model="contactForm.active" type="checkbox"><span>Active contact</span></label></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Saving...' : documentCreateContext === 'contact' ? (relatedContactName === 'supplier' ? 'Save supplier' : 'Save customer') : contactForm.type === 'supplier' ? 'Save supplier' : 'Save customer' }}</button></div></form>
      <form v-else-if="modal === 'adjustment'" @submit.prevent="saveAdjustment"><div class="form-grid"><label class="field span-2"><span>Product *</span><select v-model="adjustmentForm.product_id" required><option value="" disabled>Select a product</option><option v-for="product in stockProducts" :key="product.id" :value="product.id">{{ product.name }} ({{ qty(product.quantity_on_hand) }} {{ product.unit }} available)</option></select></label><label class="field span-2"><span>Quantity change *</span><input v-model="adjustmentForm.quantity_change" type="number" :step="['pc', 'pcs'].includes(products.find(product => product.id === Number(adjustmentForm.product_id))?.unit) ? 1 : 0.001" required placeholder="Use + to add or − to remove"><small>Positive adds stock; negative removes stock.</small></label><label class="field span-2"><span>Reason *</span><textarea v-model="adjustmentForm.notes" rows="3" required placeholder="Explain why stock is being adjusted"></textarea></label></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Saving...' : 'Save adjustment' }}</button></div></form>
      <div v-else-if="modal === 'client_details' && selectedClient" class="company-details"><div class="company-detail-hero"><div><span class="stock-pill" :class="selectedClient.subscription_active ? 'good' : 'low'">{{ clientValidityLabel(selectedClient) }}</span><p>{{ selectedClient.plan_name || 'No plan assigned' }} · valid until {{ date(selectedClient.subscription_ends_at) }}</p></div><div class="renew-control"><strong>Extend subscription</strong><div><button v-for="months in [1, 3, 6, 12]" :key="months" class="btn-secondary-app small" :disabled="busy" @click="renewClient(selectedClient, months)"><CalendarPlus :size="15" /> {{ months }}{{ months === 1 ? ' month' : ' months' }}</button></div></div></div><div class="company-detail-stats"><div><span>Users</span><strong>{{ selectedClient.users_count }}</strong></div><div><span>Products</span><strong>{{ selectedClient.products_count }}</strong></div><div><span>Contacts</span><strong>{{ selectedClient.contacts_count }}</strong></div><div><span>Transactions</span><strong>{{ selectedClient.documents_count }}</strong></div></div><div class="company-detail-grid"><section class="detail-section"><div class="detail-section-head"><div><h3>Owner and team</h3><p>Accounts currently assigned to this company.</p></div></div><div v-if="selectedClient.users?.length" class="team-list"><div v-for="user in selectedClient.users" :key="user.id" class="team-row"><span class="avatar small">{{ initial(user.name) }}</span><div><strong>{{ user.name }}</strong><span>{{ user.email }} · {{ roleLabel(user.role) }}</span></div><span class="stock-pill" :class="user.active ? 'good' : 'low'">{{ user.active ? 'Active' : 'Inactive' }}</span></div></div><div v-else class="detail-empty">No users assigned.</div></section><section class="detail-section"><div class="detail-section-head"><div><h3>Support history</h3><p>Latest superadmin support sessions.</p></div></div><div v-if="selectedClient.support_impersonations?.length" class="support-history"><div v-for="entry in selectedClient.support_impersonations" :key="entry.id" class="support-history-row"><Clock3 :size="16" /><div><strong>{{ entry.impersonator?.name || 'Superadmin' }}</strong><span>Started {{ date(entry.started_at) }}<template v-if="entry.ended_at"> · ended {{ date(entry.ended_at) }}</template><template v-else> · active session</template></span></div></div></div><div v-else class="detail-empty">No support sessions recorded.</div></section></div><section class="detail-section company-branches"><div class="detail-section-head"><div><h3>Company branches</h3><p>Only superadmin can add or remove branches. Company users can edit branch details.</p></div><button type="button" class="btn-primary-app small" :disabled="busy" @click="openClientBranch"><Plus :size="15" /> Add branch</button></div><div v-if="selectedClient.branches?.length" class="company-branch-list"><div v-for="branch in selectedClient.branches" :key="branch.id" class="company-branch-row"><span class="product-avatar category-avatar"><MapPin :size="16" /></span><div><strong>{{ branch.name }}</strong><small>{{ branch.code }} · {{ branch.active ? 'Active' : 'Inactive' }}<template v-if="branch.is_default"> · Default</template></small></div><button type="button" class="icon-button" :title="branch.is_default ? 'Default branch cannot be deleted' : 'Delete empty branch'" :disabled="busy || branch.is_default || branch.documents_count > 0 || Number(branch.stocks_sum_quantity_on_hand) !== 0" @click="deleteClientBranch(branch)"><Trash2 :size="16" /></button></div></div><div v-else class="detail-empty">No branches found.</div></section><section v-if="selectedClient.notes" class="company-notes"><strong>Support notes</strong><p>{{ selectedClient.notes }}</p></section><div class="modal-actions"><button class="btn-secondary-app" @click="openClient(selectedClient)"><Pencil :size="16" /> Edit company</button><button v-if="selectedClient.admins?.some(owner => owner.active)" class="btn-secondary-app" :disabled="busy" @click="startSupportSession(selectedClient)"><Eye :size="16" /> View as owner</button><button v-else class="btn-secondary-app" @click="openClient(selectedClient)"><UserCog :size="16" /> Set up owner</button><button class="btn-primary-app" @click="closeModal()">Close</button></div></div>
      <form v-else-if="modal === 'client_provision' && provisionTarget" @submit.prevent="provisionClient">
        <div class="payment-plan-note">Company #{{ provisionTarget.id }} was saved but is not yet available on its subdomain. In cPanel Database Wizard, create an empty database named <strong>{{ provisionTarget.expected_tenant_database }}</strong> and assign your app's existing MySQL user with all privileges. Do not create or edit tables in phpMyAdmin.</div>
        <div class="form-grid"><label class="field span-2"><span>Type the database name to confirm *</span><input v-model.trim="provisionConfirmation" required :placeholder="provisionTarget.expected_tenant_database" autocomplete="off" spellcheck="false"></label></div>
        <div class="modal-actions"><button type="button" class="btn-secondary-app" @click="openClient(provisionTarget, true)">Edit company</button><button type="button" class="btn-secondary-app" @click="closeModal()">Close</button><button class="btn-primary-app" :disabled="busy || provisionConfirmation !== provisionTarget.expected_tenant_database">{{ busy ? 'Initializing...' : 'Initialize company database' }}</button></div>
      </form>
      <form v-else-if="modal === 'client'" @submit.prevent="saveClient"><div class="form-grid"><label class="field"><span>Company name *</span><input v-model="clientForm.name" required placeholder="Business name"></label><label class="field"><span>Named subdomain (optional)</span><input v-model="clientForm.subdomain" maxlength="63" pattern="[a-zA-Z][a-zA-Z0-9-]*" placeholder="demo" autocapitalize="none" spellcheck="false"><small>Leave blank for the numeric company address. A name also keeps the numeric address available.</small></label><label class="field"><span>Subscription plan</span><select v-model="clientForm.plan_name"><option v-if="!clientForm.plan_name" value="" disabled>Select a plan</option><option v-else-if="!subscriptionPlans.includes(clientForm.plan_name)" :value="clientForm.plan_name" disabled>{{ clientForm.plan_name }} · existing plan</option><option v-for="plan in subscriptionPlans" :key="plan" :value="plan">{{ plan }}</option></select></label><label class="field"><span>Subscription status *</span><select v-model="clientForm.subscription_status" required><option value="trial">Trial</option><option value="active">Active</option><option value="past_due">Past due</option><option value="suspended">Suspended</option><option value="cancelled">Cancelled</option></select></label><label class="field"><span>Valid until</span><input v-model="clientForm.subscription_ends_at" type="date"></label><label class="field checkbox-field span-2"><input v-model="clientForm.gold_store" type="checkbox"><span>Enable gold-store system — weight-based products, metal rates, jewelry sales, old-gold exchange, and goldsmith jobs</span></label><label v-if="editingId" class="field checkbox-field span-2"><input v-model="clientForm.shared_stock_sales" type="checkbox"><span>Enable shared stock sales — sell multiple product names from one stock product</span><small>Only selected companies can link sales products to a shared stock source. Existing links are retained if this is turned off.</small></label><div class="form-section-title span-2">Owner administrator</div><label class="field"><span>Owner name *</span><input v-model="clientForm.owner_name" required placeholder="Full name"></label><label class="field"><span>Owner email{{ clientForm.owner_mobile ? '' : ' *' }}</span><input v-model.trim="clientForm.owner_email" type="email" :required="!clientForm.owner_mobile" maxlength="255" placeholder="owner@company.com" autocomplete="email"></label><label class="field"><span>Owner phone number{{ clientForm.owner_email ? '' : ' *' }}</span><input v-model.trim="clientForm.owner_mobile" type="tel" inputmode="tel" :required="!clientForm.owner_email" maxlength="30" pattern="\+?[0-9]{7,15}" placeholder="01712345678" autocomplete="tel"><small>Enter an email address, a phone number, or both. The owner can sign in with either.</small></label><label class="field" :class="{ 'span-2': !editingId }"><span>{{ clientForm.owner_exists ? 'New owner password (optional)' : 'Temporary password *' }}</span><input v-model="clientForm.owner_password" type="password" :required="!clientForm.owner_exists" minlength="8" autocomplete="new-password" placeholder="At least 8 characters"></label><label v-if="editingId" class="field checkbox-field"><input v-model="clientForm.owner_active" type="checkbox"><span>Owner account active</span></label><label class="field span-2"><span>Support notes</span><textarea v-model="clientForm.notes" rows="3" placeholder="Subscription or company support notes"></textarea></label></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Saving...' : editingId ? 'Save company and owner' : 'Create company and owner' }}</button></div></form>
      <form v-else-if="modal === 'user'" @submit.prevent="saveUser"><div class="form-grid"><label class="field"><span>Full name *</span><input v-model="userForm.name" required autocomplete="name" placeholder="User name"></label><label class="field"><span>Email address{{ userForm.role === 'superadmin' || !userForm.mobile ? ' *' : '' }}</span><input v-model.trim="userForm.email" type="email" :required="userForm.role === 'superadmin' || !userForm.mobile" maxlength="255" autocomplete="email" placeholder="name@company.com"></label><label class="field"><span>Phone number{{ userForm.role !== 'superadmin' && !userForm.email ? ' *' : '' }}</span><input v-model.trim="userForm.mobile" type="tel" inputmode="tel" :required="userForm.role !== 'superadmin' && !userForm.email" maxlength="30" pattern="\+?[0-9]{7,15}" autocomplete="tel" placeholder="01712345678"></label><label class="field"><span>{{ editingId ? 'New password (optional)' : 'Password *' }}</span><input v-model="userForm.password" type="password" :required="!editingId" minlength="8" autocomplete="new-password" placeholder="At least 8 characters"></label><label class="field"><span>Role *</span><select v-model="userForm.role" required><option v-if="session.user.role === 'superadmin'" value="superadmin">Superadmin</option><option v-if="session.user.role === 'superadmin'" value="admin">Owner admin</option><option value="manager">Manager</option><option value="staff">Staff</option></select></label><label v-if="session.user.role === 'superadmin' && userForm.role !== 'superadmin'" class="field"><span>Company *</span><select v-model="userForm.organization_id" required><option value="" disabled>Select a company</option><option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}</option></select></label><label class="field checkbox-field"><input v-model="userForm.active" type="checkbox"><span>Active user</span></label><template v-if="['manager', 'staff'].includes(userForm.role)"><div class="form-section-title span-2">Allowed branches *</div><div class="branch-access-grid span-2"><label v-for="branch in branches.filter(branch => branch.active)" :key="branch.id" class="branch-access-option"><input v-model="userForm.branch_ids" type="checkbox" :value="branch.id"><span class="product-avatar category-avatar"><MapPin :size="16" /></span><span><strong>{{ branch.name }}</strong><small>{{ branch.code }}</small></span></label></div><p class="branch-access-help span-2">This user can view and operate only in the selected branches. Select every branch they should be able to use.</p></template><template v-if="userForm.role !== 'superadmin'"><div class="form-section-title span-2">Workspace permissions</div><div class="permission-grid span-2"><label v-for="permission in permissionOptions" :key="permission.value" class="permission-option"><input v-model="userForm.permissions" type="checkbox" :value="permission.value"><span>{{ permission.label }}</span></label></div><p v-if="userForm.role === 'admin'" class="branch-access-help span-2">Owner admins retain user and system setup management.</p></template><section v-if="userForm.role !== 'superadmin'" class="user-report-preview span-2"><div class="user-report-preview-head"><strong>Report access</strong><span>{{ userAccessibleReports.length }} of {{ reportKinds.length }} selected</span></div><p>Choose which reports this user can open. First select a related workspace permission above.</p><div class="permission-grid report-permission-grid"><label v-for="report in reportKinds" :key="report.id" class="permission-option" :class="{ 'report-permission-locked': !canGrantReport(report) }" :title="canGrantReport(report) ? '' : 'Select a related workspace permission above first.'"><input v-model="userForm.report_permissions" type="checkbox" :value="report.id" :disabled="!canGrantReport(report)"><span>{{ report.label }}</span></label></div></section></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Saving...' : 'Save user and access' }}</button></div></form>
      <form v-else @submit.prevent="saveDocument">
        <section v-if="session.gold_store && ['sale', 'resale'].includes(documentForm.type)" class="payment-terms-card gold-exchange-card"><div><span class="eyebrow">GOLD EXCHANGE</span><h3>Old gold received from customer</h3></div><div class="form-grid"><label class="field"><span>Weight (g)</span><input v-model="documentForm.gold_exchange_weight" type="number" min="0" step="0.001"></label><label class="field"><span>Purity</span><select v-model="documentForm.gold_exchange_purity"><option v-for="rate in goldRates.filter(rate => rate.metal === 'gold')" :key="rate.id" :value="rate.purity">{{ rate.purity }} · {{ money(rate.rate_per_gram) }}/g</option></select></label><div class="payment-plan-note span-2">Estimated exchange credit: {{ money(goldExchangeAmount) }}. The system locks the branch rate and reduces the customer’s balance.</div></div></section>
        <div class="form-grid">
          <label v-if="documentForm.type === 'purchase_return'" class="field span-2"><span>Original purchase *</span><select v-model="documentForm.purchase_id" required @change="choosePurchase"><option value="" disabled>Select a purchase</option><option v-for="purchase in purchaseOptions" :key="purchase.id" :value="purchase.id">{{ purchase.number }} · {{ purchase.contact?.name }} · {{ date(purchase.document_date) }}</option></select></label>
          <label v-if="documentForm.type === 'sale_return'" class="field span-2"><span>Original sale *</span><select v-model="documentForm.sale_id" required @change="chooseSale"><option value="" disabled>Select a sale with returnable items</option><option v-for="sale in saleOptions" :key="sale.id" :value="sale.id">{{ sale.number }} · {{ sale.contact?.name }} · {{ date(sale.document_date) }}</option></select></label>
          <div class="field"><span>{{ relatedContactName === 'supplier' ? 'Supplier' : 'Customer' }} *</span><div class="field-action"><ContactPicker v-if="['purchase', 'sale'].includes(documentForm.type)" v-model="documentForm.contact_id" :contacts="availableContacts" :kind="relatedContactName" :language="language" /><select v-else v-model="documentForm.contact_id" required disabled :aria-label="relatedContactName"><option value="" disabled>Select a {{ relatedContactName }}</option><option v-for="contact in availableContacts" :key="contact.id" :value="contact.id">{{ contact.name }}</option></select><button v-if="!['purchase_return', 'sale_return'].includes(documentForm.type)" type="button" class="btn-secondary-app small create-inline" @click="createRelatedContact"><Plus :size="16" /> Create new {{ relatedContactName }}</button></div></div>
          <label class="field"><span>Date *</span><input v-model="documentForm.document_date" type="date" :min="staffDocumentDatesLocked && ['purchase', 'sale', 'resale'].includes(documentForm.type) ? today() : undefined" :max="staffDocumentDatesLocked && ['purchase', 'sale', 'resale'].includes(documentForm.type) ? today() : undefined" required></label>
        </div>
        <div v-if="documentForm.type === 'purchase' && purchaseStep < 3" class="workflow-banner"><span class="workflow-step">{{ purchaseStep }}</span><div><strong>{{ purchaseStep === 1 ? 'Start with a supplier' : 'Next, choose a product' }}</strong><p>{{ purchaseStep === 1 ? 'Select a supplier or create a new one to continue.' : 'Select a product or create a new one. Your purchase draft will be kept.' }}</p></div></div>
        <div class="items-heading"><div><h3>Line items</h3><p>{{ documentForm.type === 'sale_return' ? 'Choose quantities to refund. Prices come from the original sale.' : 'Choose products and enter quantities.' }}</p></div></div>
        <div class="items-list"><div v-for="(item, index) in documentForm.items" :key="index" class="item-row"><div class="field"><div class="field-action"><ProductPicker v-model="item.product_id" class="document-product-picker" :products="availableProducts" :stock-mode="documentForm.type === 'purchase' ? 'on_hand' : 'available'" :language="language" :disabled="documentForm.type === 'purchase' && !documentForm.contact_id" @select="productId => { item.product_id = productId; chooseProduct(item); }" /><button v-if="!['purchase_return', 'sale_return'].includes(documentForm.type)" type="button" class="btn-secondary-app small create-inline" :disabled="documentForm.type === 'purchase' && !documentForm.contact_id" @click="createRelatedProduct(index)"><Plus :size="16" /> New product</button></div></div><label v-if="!['purchase_return', 'sale_return'].includes(documentForm.type) && item.product_id" class="field"><span>Unit</span><select v-model="item.unit" :aria-label="`Unit for line ${index + 1}`" @change="changeTransactionUnit(item)"><option v-for="unit in transactionUnitOptions(item.product_id)" :key="unit.value" :value="unit.value">{{ unit.label }}</option></select><small v-if="transactionUnitFactor(item) > 1">1 {{ item.unit }} = {{ qty(transactionUnitFactor(item)) }} pcs</small></label><div v-else class="field"><span>Unit</span><strong>{{ item.unit || products.find(product => product.id === Number(item.product_id))?.unit || '—' }}</strong></div><label class="field"><span>Quantity</span><input v-model="item.quantity" type="number" :min="['pc', 'pcs'].includes(products.find(product => product.id === Number(item.product_id))?.unit) && item.unit === products.find(product => product.id === Number(item.product_id))?.unit ? 1 : 0.001" :max="documentForm.type === 'sale_return' ? returnableQuantity(item.product_id) : undefined" :step="['pc', 'pcs'].includes(products.find(product => product.id === Number(item.product_id))?.unit) && item.unit === products.find(product => product.id === Number(item.product_id))?.unit ? 1 : 0.001" required><small v-if="documentForm.type === 'sale_return'">Up to {{ qty(returnableQuantity(item.product_id)) }} returnable</small></label><label class="field"><span>Price per {{ item.unit || 'unit' }}</span><input v-model="item.unit_price" type="number" min="0" step="0.01" required :readonly="documentForm.type === 'sale_return'"></label><div class="line-amount"><span>Amount</span><strong>{{ money(Number(item.quantity) * Number(item.unit_price)) }}</strong></div><button type="button" class="icon-button remove-line" title="Remove line" :disabled="documentForm.items.length === 1" @click="documentForm.items.splice(index, 1)"><Trash2 :size="17" /></button></div></div>
        <div v-if="!['purchase_return', 'sale_return'].includes(documentForm.type)" class="document-lines-footer"><button type="button" class="btn-secondary-app" :disabled="documentForm.type === 'purchase' && !documentForm.contact_id" @click="documentForm.items.push({ product_id: '', quantity: 1, unit_price: '0.00' })"><Plus :size="16" /> Add line</button></div>
        <section v-if="['sale', 'resale', 'purchase'].includes(documentForm.type)" class="payment-terms-card"><div><span class="eyebrow">PAYMENT TERMS</span><h3>{{ documentForm.type === 'purchase' ? 'How will you pay the supplier?' : 'How will the customer pay?' }}</h3></div><div class="form-grid"><label class="field span-2"><span>Payment plan *</span><select v-model="documentForm.payment_type" @change="setDocumentPayment"><option value="full">Immediate / full payment</option><option value="installment">Down payment + installment</option><option value="due">{{ documentForm.type === 'purchase' ? 'Due purchase' : 'Due sale' }}</option></select></label><label v-if="documentForm.payment_type !== 'due'" class="field"><span>Payment method</span><select v-model="documentForm.payment_method"><option value="cash">Cash</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option><option value="bank_transfer">Bank transfer</option></select></label><div v-if="documentForm.payment_type === 'full'" class="field"><span>{{ documentForm.type === 'purchase' ? 'Paid to supplier today' : 'Collected today' }}</span><strong class="payment-total">{{ money(docTotal) }}</strong></div>
          <template v-if="documentForm.payment_type === 'installment'"><label class="field"><span>{{ documentForm.type === 'purchase' ? 'Advance payment' : 'Down payment' }}</span><input v-model="documentForm.down_payment" type="number" min="0" :max="docBaseTotal" step="0.01"></label><label v-if="['sale', 'resale'].includes(documentForm.type)" class="field"><span>Interest rate (%)</span><input v-model="documentForm.interest_rate" type="number" min="0" max="100" step="0.01"><small>Flat interest on the financed balance.</small></label><label class="field"><span>Number of installments</span><input v-model="documentForm.installment_count" type="number" min="1" max="120" step="1"></label><label class="field"><span>Frequency</span><select v-model="documentForm.installment_frequency"><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></label><label class="field"><span>First installment date</span><input v-model="documentForm.first_installment_date" type="date"><small>{{ Number(documentForm.installment_count || 0) ? `${money(saleInstallmentAmount)} each before rounding.` : '' }}</small></label></template>
          <p v-if="documentForm.payment_type === 'due'" class="payment-plan-note span-2">{{ documentForm.type === 'purchase' ? 'No payment is made today. The full total is recorded as supplier due.' : 'No payment is collected today. The full total is recorded as customer due.' }}</p><p v-if="documentForm.payment_type === 'installment'" class="payment-plan-note span-2">{{ money(salePaymentInitial) }} {{ documentForm.type === 'purchase' ? 'paid to supplier today' : 'collected today' }} · <template v-if="docInterestAmount > 0">{{ money(docInterestAmount) }} interest · </template>{{ money(saleInstallmentBalance) }} scheduled for future payments.</p>
        </div></section>
        <section v-if="session.operational_setup?.store?.allow_cash_handover && ['sale_return', 'purchase_return'].includes(documentForm.type)" class="payment-terms-card"><div><span class="eyebrow">{{ language === 'bn' ? 'নগদ সমন্বয়' : 'CASH RECONCILIATION' }}</span><h3>{{ documentForm.type === 'sale_return' ? (language === 'bn' ? 'ক্রেতাকে কীভাবে ফেরত দেওয়া হয়েছে?' : 'How was the customer refunded?') : (language === 'bn' ? 'সরবরাহকারী কীভাবে ফেরত দিয়েছেন?' : 'How did the supplier refund you?') }}</h3></div><label class="field"><span>{{ language === 'bn' ? 'ফেরতের পদ্ধতি' : 'Refund method' }} *</span><select v-model="documentForm.payment_method" required><option value="credit">{{ language === 'bn' ? 'নগদ লেনদেন হয়নি / বকেয়ায় সমন্বয়' : 'No cash exchanged / credit adjustment' }}</option><option value="cash">{{ language === 'bn' ? 'নগদ' : 'Cash' }}</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option><option value="bank_transfer">Bank transfer</option></select><small>{{ language === 'bn' ? 'নগদ বেছে নিলে সম্পূর্ণ ফেরতের টাকা এই ব্যবহারকারীর হাতে নগদে যোগ বা বিয়োগ হবে।' : 'Selecting cash records the full return amount as cash received or paid by you.' }}</small></label></section>
        <div class="document-bottom"><label class="field notes-field"><span>Notes</span><textarea v-model="documentForm.notes" rows="3" placeholder="Optional transaction notes"></textarea></label><div class="totals-box"><div><span>Subtotal</span><strong>{{ money(docSubtotal) }}</strong></div><label><span>Discount</span><input v-model="documentForm.discount" type="number" min="0" step="0.01"></label><label><span>Tax</span><input v-model="documentForm.tax" type="number" min="0" step="0.01"></label><div v-if="docInterestAmount > 0" class="total-interest"><span>Interest charge</span><strong>{{ money(docInterestAmount) }}</strong></div><div class="grand-total"><span>Total</span><strong>{{ money(docTotal) }}</strong></div></div></div>
        <div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy || docTotal < 0 || !documentCanSave">{{ busy ? 'Saving...' : `Save ${label(documentForm.type).toLowerCase()}` }}</button></div>
      </form>
    </div></div>
  </div>
</template>
