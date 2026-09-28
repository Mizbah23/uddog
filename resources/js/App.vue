<script setup>
import { computed, onMounted, ref } from 'vue';
import { LayoutDashboard, Package, Users, ShoppingCart, ShoppingBag, Repeat2, Undo2, Boxes, Plus, Search, Menu, X, LogOut, ArrowUpRight, ArrowDownRight, AlertTriangle, ChevronDown, Trash2, Pencil, Check, Clock3, Building2, UserCog, ShieldCheck, Eye, CalendarPlus, Info, ClipboardList, ScanLine, Minus, Banknote, ReceiptText, Barcode, Printer, Tags, MapPin, ClipboardCheck, ArrowLeftRight } from '@lucide/vue';

const workspaceNav = [
  { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, abilities: ['dashboard'] },
  { id: 'products', label: 'Products', icon: Package, abilities: ['products'] },
  { id: 'categories', label: 'Categories', icon: Tags, abilities: ['categories'] },
  { id: 'branches', label: 'Branches', icon: MapPin, abilities: ['branches'] },
  { id: 'contacts', label: 'Contacts', icon: Users, abilities: ['contacts'] },
  { id: 'purchase', label: 'Purchases', icon: ShoppingBag, abilities: ['purchases'] },
  { id: 'pos', label: 'POS Sale', icon: ScanLine, abilities: ['sales'] },
  { id: 'sale', label: 'Sales', icon: ShoppingCart, abilities: ['sales'] },
  { id: 'sale_return', label: 'Sales returns', icon: Undo2, abilities: ['sales_returns'] },
  { id: 'resale', label: 'Resales', icon: Repeat2, abilities: ['resales'] },
  { id: 'purchase_return', label: 'Purchase returns', icon: Undo2, abilities: ['purchase_returns'] },
  { id: 'inventory', label: 'Inventory', icon: Boxes, abilities: ['inventory', 'stock_adjustments'] },
  { id: 'stock_checks', label: 'Stock Check', icon: ClipboardCheck, abilities: ['stock_checks'] },
  { id: 'stock_transfers', label: 'Stock Transfer', icon: ArrowLeftRight, abilities: ['stock_transfers'] },
];
const managementNav = [
  { id: 'admin_dashboard', label: 'Overview', icon: LayoutDashboard, permission: 'manage_clients' },
  { id: 'clients', label: 'Companies', icon: Building2, permission: 'manage_clients' },
  { id: 'users', label: 'Users', icon: UserCog, permission: 'manage_users' },
  { id: 'roadmap', label: 'Work progress', icon: ClipboardList, permission: 'manage_clients' },
];
const titles = Object.fromEntries([...workspaceNav, ...managementNav].map(item => [item.id, item.label]));
const session = ref(null);
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const page = ref(location.hash.replace('#', '') || 'dashboard');
const mobileOpen = ref(false);
const modal = ref('');
const documentCreateContext = ref('');
const documentLineToFill = ref(0);
const editingId = ref(null);
const detailId = ref(null);
const search = ref('');
const overview = ref({ products: 0, stock_value: 0, low_stock: 0, sales_total: 0, purchase_total: 0, recent_documents: [], low_stock_products: [] });
const products = ref([]);
const categories = ref([]);
const branches = ref([]);
const activeBranchId = ref('');
const contacts = ref([]);
const documents = ref([]);
const movements = ref([]);
const stockChecks = ref([]);
const stockTransfers = ref([]);
const clients = ref([]);
const selectedClient = ref(null);
const selectedDocument = ref(null);
const clientStatusFilter = ref('all');
const users = ref([]);
const authForm = ref({ name: '', email: '', password: '' });
const unitOptions = [
  { value: 'pc', label: 'Pieces (pc)' },
  { value: 'items', label: 'Items' },
  { value: 'units', label: 'Units' },
  { value: 'packs', label: 'Packs' },
  { value: 'boxes', label: 'Boxes' },
  { value: 'cartons', label: 'Cartons' },
  { value: 'sets', label: 'Sets' },
  { value: 'kg', label: 'Kilograms (kg)' },
  { value: 'g', label: 'Grams (g)' },
  { value: 'L', label: 'Liters (L)' },
];
const productForm = ref({ sku: '', barcode: '', name: '', category_id: '', unit: 'pc', cost_price: '', sale_price: '', reorder_level: '0', active: true });
const categoryForm = ref({ name: '', description: '', active: true });
const branchForm = ref({ name: '', code: '', phone: '', address: '', is_default: false, active: true });
const contactForm = ref({ name: '', type: 'customer', phone: '', email: '', address: '' });
const adjustmentForm = ref({ product_id: '', quantity_change: '', notes: '' });
const clientForm = ref({ name: '', plan_name: '', subscription_status: 'trial', subscription_ends_at: '', notes: '', owner_name: '', owner_email: '', owner_password: '', owner_active: true, owner_exists: false });
const userForm = ref({ name: '', email: '', password: '', role: 'manager', organization_id: '', active: true, permissions: [] });
const permissionOptions = [
  { value: 'dashboard', label: 'Dashboard overview' },
  { value: 'products', label: 'Products' },
  { value: 'categories', label: 'Product categories' },
  { value: 'branches', label: 'Branches' },
  { value: 'contacts', label: 'Customers and suppliers' },
  { value: 'purchases', label: 'Purchases' },
  { value: 'sales', label: 'Sales' },
  { value: 'sales_returns', label: 'Sales returns' },
  { value: 'resales', label: 'Resales' },
  { value: 'purchase_returns', label: 'Purchase returns' },
  { value: 'inventory', label: 'Inventory and stock history' },
  { value: 'stock_adjustments', label: 'Manual stock adjustments' },
  { value: 'stock_checks', label: 'Stock checks' },
  { value: 'stock_transfers', label: 'Stock transfers' },
];
const roadmapItems = [
  { title: 'Sales Returns', phase: 'Phase 1', priority: 'High', status: 'Completed', description: 'Return sold items, restore inventory and record refund values against the original sale.' },
  { title: 'POS Sale', phase: 'Phase 1', priority: 'High', status: 'Completed', description: 'Fast checkout screen with product search, cart controls, payment and receipt printing.' },
  { title: 'Categories', phase: 'Phase 1', priority: 'High', status: 'Completed', description: 'Reusable product categories with centralized management and filtering.' },
  { title: 'Stock Check', phase: 'Phase 2', priority: 'Medium', status: 'Completed', description: 'Physical stock-count sessions with expected, counted and variance quantities.' },
  { title: 'Stock Transfer', phase: 'Phase 2', priority: 'Medium', status: 'Completed', description: 'Move stock between branches or locations with a complete movement history.' },
  { title: 'Warranty Search', phase: 'Phase 3', priority: 'Medium', status: 'Planned', description: 'Find sold products and warranty eligibility using invoice, serial or customer.' },
  { title: 'Sales Targets', phase: 'Phase 3', priority: 'Low', status: 'Planned', description: 'Set sales goals by user or period and compare actual performance with targets.' },
];
const today = () => new Date().toLocaleDateString('en-CA');
const documentForm = ref({ type: 'purchase', contact_id: '', purchase_id: '', sale_id: '', document_date: today(), discount: '0', tax: '0', notes: '', items: [{ product_id: '', quantity: 1, unit_price: '0.00' }] });
const posSearch = ref('');
const barcodeScan = ref('');
const posForm = ref({ contact_id: '', document_date: today(), discount: '0', tax: '0', payment_method: 'cash', amount_paid: '0', notes: '', items: [] });
const barcodeProduct = ref(null);
const selectedStockCheck = ref(null);
const stockCheckForm = ref({ check_date: today(), notes: '', items: [] });
const stockTransferForm = ref({ to_branch_id: '', transfer_date: today(), notes: '', items: [{ product_id: '', quantity: 1 }] });

function money(value) { return (import.meta.env.VITE_CURRENCY_SYMBOL || '৳') + Number(value || 0).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function qty(value) { return Number(value || 0).toLocaleString('en-BD', { maximumFractionDigits: 3 }); }
function date(value) { return value ? new Date(value.includes('T') ? value : `${value}T00:00:00`).toLocaleDateString('en-BD', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
function time(value) { return value ? new Date(value).toLocaleTimeString('en-BD', { hour: '2-digit', minute: '2-digit', hour12: true }) : '—'; }
function documentDateTime(document) { return `${date(document.document_date)} · ${time(document.created_at)}`; }
function label(type) { return ({ purchase: 'Purchase', sale: 'Sale', resale: 'Resale', purchase_return: 'Purchase return', sale_return: 'Sales return', adjustment: 'Adjustment', stock_check: 'Stock check', transfer_out: 'Transfer out', transfer_in: 'Transfer in' })[type] || type; }
function initial(name) { return (name || 'U').trim().charAt(0).toUpperCase(); }
function productOption(product) { return product.sku ? `${product.name} · ${product.sku}` : product.name; }
function roleLabel(role) { return ({ superadmin: 'Superadmin', admin: 'Owner admin', manager: 'Manager', staff: 'Staff' })[role] || role; }
function statusLabel(status) { return ({ trial: 'Trial', active: 'Active', past_due: 'Past due', suspended: 'Suspended', cancelled: 'Cancelled' })[status] || status; }
function paymentLabel(method) { return ({ cash: 'Cash', card: 'Card', mobile_banking: 'Mobile banking', bank_transfer: 'Bank transfer', credit: 'Credit' })[method] || '—'; }
function checkVariance(stockCheck) { return stockCheck.items.reduce((total, item) => total + Number(item.variance || 0), 0); }
function clientValidityLabel(client) {
  if (!client.subscription_active && ['trial', 'active'].includes(client.subscription_status) && client.subscription_ends_at) return 'Expired';
  return statusLabel(client.subscription_status);
}

function hasAbility(...abilities) { return abilities.some(ability => session.value?.permissions?.abilities?.includes(ability)); }
const nav = computed(() => [
  ...(session.value?.permissions?.use_workspace ? workspaceNav.filter(item => item.abilities.some(ability => hasAbility(ability))) : []),
  ...managementNav.filter(item => session.value?.permissions?.[item.permission]),
]);

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
    throw new Error(data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'Something went wrong.');
  }
  return data;
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
    if (hasAbility('products', 'purchases', 'sales', 'sales_returns', 'resales', 'purchase_returns', 'inventory', 'stock_adjustments', 'stock_transfers')) requests.push(api(`products${branchQuery}`).then(data => { products.value = data; }));
    if (hasAbility('categories', 'products', 'purchases')) requests.push(api('categories').then(data => { categories.value = data; }));
    if (hasAbility('contacts', 'purchases', 'sales', 'sales_returns', 'resales', 'purchase_returns')) requests.push(api('contacts').then(data => { contacts.value = data; }));
    if (hasAbility('purchases', 'sales', 'sales_returns', 'resales', 'purchase_returns')) requests.push(api(`documents${branchQuery}`).then(data => { documents.value = data; }));
    if (hasAbility('inventory', 'stock_adjustments')) requests.push(api(`movements${branchQuery}`).then(data => { movements.value = data; }));
    if (hasAbility('stock_checks')) requests.push(api(`stock-checks${branchQuery}`).then(data => { stockChecks.value = data; }));
    if (hasAbility('stock_transfers')) requests.push(api(`stock-transfers${branchQuery}`).then(data => { stockTransfers.value = data; }));
  }
  if (session.value?.permissions?.manage_clients) requests.push(api('clients').then(data => { clients.value = data; }));
  if (session.value?.permissions?.manage_users) requests.push(api('users').then(data => { users.value = data; }));
  await Promise.all(requests);
}
async function initialize() {
  try {
    session.value = await api('session');
    if (session.value.user) {
      if (!nav.value.some(item => item.id === page.value)) {
        page.value = nav.value[0]?.id || 'dashboard';
        history.replaceState(null, '', `#${page.value}`);
      }
      await refresh();
    }
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}
onMounted(initialize);
window.addEventListener('hashchange', () => { const requested = location.hash.replace('#', '') || 'dashboard'; page.value = nav.value.some(item => item.id === requested) ? requested : nav.value[0]?.id || 'dashboard'; mobileOpen.value = false; });
function navigate(id) { page.value = id; location.hash = id; mobileOpen.value = false; search.value = ''; }
async function switchBranch() {
  localStorage.setItem(`uddog-branch-${session.value.user.organization_id}`, String(activeBranchId.value));
  error.value = ''; busy.value = true;
  try { await refresh(); } catch (e) { error.value = e.message; } finally { busy.value = false; }
}

async function authenticate() {
  error.value = ''; busy.value = true;
  try {
    const endpoint = session.value.setup_required ? 'setup' : 'login';
    await api(endpoint, { method: 'POST', body: authForm.value });
    session.value = await api('session');
    page.value = nav.value[0]?.id || 'dashboard';
    history.replaceState(null, '', `#${page.value}`);
    await refresh();
  } catch (e) { error.value = e.message; }
  finally { busy.value = false; }
}
async function logout() {
  await api('logout', { method: 'POST' });
  location.reload();
}
function clearMessages() { error.value = ''; notice.value = ''; }
function toast(message) { notice.value = message; setTimeout(() => { notice.value = ''; }, 4000); }

const filteredProducts = computed(() => products.value.filter(p => `${p.name} ${p.sku || ''} ${p.barcode || ''} ${p.category?.name || ''}`.toLowerCase().includes(search.value.toLowerCase())));
const filteredCategories = computed(() => categories.value.filter(category => `${category.name} ${category.description || ''}`.toLowerCase().includes(search.value.toLowerCase())));
const filteredContacts = computed(() => contacts.value.filter(c => `${c.name} ${c.phone || ''} ${c.email || ''}`.toLowerCase().includes(search.value.toLowerCase())));
const filteredDocuments = computed(() => documents.value.filter(d => d.type === page.value && `${d.number} ${d.contact?.name || ''}`.toLowerCase().includes(search.value.toLowerCase())));
const filteredClients = computed(() => clients.value.filter(client => {
  const matchesSearch = `${client.name} ${client.plan_name || ''} ${client.subscription_status} ${client.admins?.map(admin => `${admin.name} ${admin.email}`).join(' ') || ''}`.toLowerCase().includes(search.value.toLowerCase());
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
const filteredUsers = computed(() => users.value.filter(user => `${user.name} ${user.email} ${user.role} ${user.organization?.name || ''}`.toLowerCase().includes(search.value.toLowerCase())));
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
  return products.value.filter(product => product.active);
});
const documentCanSave = computed(() => {
  const contactIsValid = availableContacts.value.some(contact => contact.id === Number(documentForm.value.contact_id));
  const itemsAreValid = documentForm.value.items.length > 0 && documentForm.value.items.every(item => availableProducts.value.some(product => product.id === Number(item.product_id)));
  const sourceIsValid = (documentForm.value.type !== 'purchase_return' || !!documentForm.value.purchase_id)
    && (documentForm.value.type !== 'sale_return' || !!documentForm.value.sale_id);
  return contactIsValid && itemsAreValid && sourceIsValid;
});
const roadmapCompleted = computed(() => roadmapItems.filter(item => item.status === 'Completed').length);
const purchaseStep = computed(() => !documentForm.value.contact_id ? 1 : !documentForm.value.items.every(item => item.product_id) ? 2 : 3);
const docSubtotal = computed(() => documentForm.value.items.reduce((sum, item) => sum + Number(item.quantity || 0) * Number(item.unit_price || 0), 0));
const docTotal = computed(() => docSubtotal.value - Number(documentForm.value.discount || 0) + Number(documentForm.value.tax || 0));
const posProducts = computed(() => products.value.filter(product => product.active
  && Number(product.quantity_on_hand) > 0
  && `${product.name} ${product.sku || ''} ${product.category?.name || ''}`.toLowerCase().includes(posSearch.value.toLowerCase())));
const posCustomers = computed(() => contacts.value.filter(contact => ['customer', 'both'].includes(contact.type)));
const posSubtotal = computed(() => posForm.value.items.reduce((sum, item) => sum + Number(item.quantity || 0) * Number(item.unit_price || 0), 0));
const posTotal = computed(() => Math.max(0, posSubtotal.value - Number(posForm.value.discount || 0) + Number(posForm.value.tax || 0)));
const posBalance = computed(() => Math.max(0, posTotal.value - Number(posForm.value.amount_paid || 0)));
const barcodeMarkup = computed(() => ean13Svg(barcodeProduct.value?.barcode));
const posCanCheckout = computed(() => posCustomers.value.some(contact => contact.id === Number(posForm.value.contact_id))
  && posForm.value.items.length > 0
  && posForm.value.items.every(item => Number(item.quantity) > 0 && Number(item.quantity) <= Number(item.stock))
  && Number(posForm.value.discount || 0) <= posSubtotal.value
  && Number(posForm.value.amount_paid || 0) <= posTotal.value);
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
const lowStock = p => Number(p.quantity_on_hand) <= Number(p.reorder_level);

function resetPos() {
  posForm.value = { contact_id: '', document_date: today(), discount: '0', tax: '0', payment_method: 'cash', amount_paid: '0', notes: '', items: [] };
  posSearch.value = '';
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
  if (Number(product.quantity_on_hand) <= 0) {
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
    existing.quantity = Math.min(Number(existing.stock), Number(existing.quantity) + 1);
    return;
  }
  posForm.value.items.push({ product_id: product.id, name: product.name, sku: product.sku, unit: product.unit, stock: product.quantity_on_hand, quantity: 1, unit_price: product.sale_price || '0.00' });
}
function changePosQuantity(item, change) {
  item.quantity = Math.max(0.001, Math.min(Number(item.stock), Number(item.quantity || 0) + change));
}
function setPosPayment() {
  posForm.value.amount_paid = posForm.value.payment_method === 'credit' ? '0' : posTotal.value.toFixed(2);
}

function openProduct(product = null, context = '') {
  clearMessages(); editingId.value = product?.id || null;
  documentCreateContext.value = context;
  productForm.value = product ? { sku: product.sku || '', barcode: product.barcode || '', name: product.name, category_id: product.category_id || '', unit: product.unit === 'pcs' ? 'pc' : product.unit, cost_price: product.cost_price, sale_price: product.sale_price, reorder_level: product.reorder_level, active: product.active } : { sku: '', barcode: '', name: '', category_id: '', unit: 'pc', cost_price: '', sale_price: '', reorder_level: '0', active: true };
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
  clearMessages();
  editingId.value = branch?.id || null;
  branchForm.value = branch ? { name: branch.name, code: branch.code, phone: branch.phone || '', address: branch.address || '', is_default: branch.is_default, active: branch.active } : { name: '', code: '', phone: '', address: '', is_default: false, active: true };
  modal.value = 'branch';
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
  modal.value = 'barcode';
}
function openContact(contact = null, context = '') {
  clearMessages(); editingId.value = contact?.id || null;
  documentCreateContext.value = context;
  const defaultType = context === 'contact' && documentForm.value.type === 'purchase' ? 'supplier' : 'customer';
  contactForm.value = contact ? { name: contact.name, type: contact.type, phone: contact.phone || '', email: contact.email || '', address: contact.address || '' } : { name: '', type: defaultType, phone: '', email: '', address: '' };
  modal.value = 'contact';
}
function openDocument(type = page.value) {
  clearMessages();
  documentCreateContext.value = '';
  documentForm.value = { type, contact_id: '', purchase_id: '', sale_id: '', document_date: today(), discount: '0', tax: '0', notes: '', items: [{ product_id: '', quantity: 1, unit_price: '0.00' }] };
  modal.value = 'document';
}
function openInvoice(document) {
  clearMessages();
  selectedDocument.value = document;
  modal.value = 'invoice';
}
function printInvoice() { window.print(); }
function printBarcode() { window.print(); }
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
  clearMessages(); adjustmentForm.value = { product_id: product?.id || '', quantity_change: '', notes: '' }; modal.value = 'adjustment';
}
function openStockTransfer() {
  clearMessages();
  stockTransferForm.value = { to_branch_id: transferDestinations.value[0]?.id || '', transfer_date: today(), notes: '', items: [{ product_id: '', quantity: 1 }] };
  modal.value = 'stock_transfer';
}
function openClient(client = null) {
  clearMessages(); editingId.value = client?.id || null;
  const owner = client?.admins?.[0];
  clientForm.value = client ? { name: client.name, plan_name: client.plan_name || '', subscription_status: client.subscription_status, subscription_ends_at: client.subscription_ends_at?.slice(0, 10) || '', notes: client.notes || '', owner_name: owner?.name || '', owner_email: owner?.email || '', owner_password: '', owner_active: owner?.active ?? true, owner_exists: !!owner } : { name: '', plan_name: '', subscription_status: 'trial', subscription_ends_at: '', notes: '', owner_name: '', owner_email: '', owner_password: '', owner_active: true, owner_exists: false };
  modal.value = 'client';
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
  userForm.value = user ? { name: user.name, email: user.email, password: '', role: user.role, organization_id: user.organization_id || '', active: user.active, permissions: [...(user.permissions || [])] } : { name: '', email: '', password: '', role: session.value.user.role === 'superadmin' ? 'admin' : 'manager', organization_id: session.value.user.role === 'superadmin' ? '' : session.value.user.organization_id, active: true, permissions: [] };
  modal.value = 'user';
}
function chooseProduct(item) {
  const product = products.value.find(p => p.id === Number(item.product_id));
  if (!product) return;
  if (documentForm.value.type === 'sale_return') {
    item.unit_price = selectedSale.value?.items.find(soldItem => soldItem.product_id === product.id)?.unit_price || '0.00';
    return;
  }
  item.unit_price = documentForm.value.type === 'purchase' || documentForm.value.type === 'purchase_return' ? product.cost_price : product.sale_price;
}
function choosePurchase() {
  const purchase = selectedPurchase.value;
  if (!purchase) return;
  documentForm.value.contact_id = purchase.contact_id;
  documentForm.value.items = purchase.items.map(item => ({ product_id: item.product_id, quantity: 1, unit_price: item.unit_price }));
}
function chooseSale() {
  const sale = selectedSale.value;
  if (!sale) return;
  documentForm.value.contact_id = sale.contact_id;
  documentForm.value.items = sale.items
    .filter(item => returnableQuantity(item.product_id) > 0)
    .map(item => ({ product_id: item.product_id, quantity: Math.min(1, returnableQuantity(item.product_id)), unit_price: item.unit_price }));
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
    const result = await api(editingId.value ? `branches/${editingId.value}` : 'branches', { method: editingId.value ? 'PUT' : 'POST', body: branchForm.value });
    modal.value = '';
    await refresh();
    if (result.is_default) activeBranchId.value = result.id;
    toast('Branch saved successfully.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function deleteBranch(branch) {
  error.value = ''; busy.value = true;
  try {
    await api(`branches/${branch.id}`, { method: 'DELETE' });
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
async function completePosSale() {
  error.value = ''; busy.value = true;
  try {
    const payload = {
      type: 'sale', sale_channel: 'pos', branch_id: activeBranchId.value, contact_id: posForm.value.contact_id,
      document_date: posForm.value.document_date, discount: posForm.value.discount,
      tax: posForm.value.tax, payment_method: posForm.value.payment_method,
      amount_paid: posForm.value.amount_paid, notes: posForm.value.notes,
      items: posForm.value.items.map(item => ({ product_id: item.product_id, quantity: item.quantity, unit_price: item.unit_price })),
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
async function saveAdjustment() {
  error.value = ''; busy.value = true;
  try {
    await api(`products/${adjustmentForm.value.product_id}/adjust`, { method: 'POST', body: { ...adjustmentForm.value, branch_id: activeBranchId.value } });
    modal.value = ''; await refresh(); toast('Stock adjustment saved.');
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
    await api(editingId.value ? `clients/${editingId.value}` : 'clients', { method: editingId.value ? 'PUT' : 'POST', body: clientForm.value });
    modal.value = ''; await refresh(); toast('Client subscription saved.');
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
async function saveUser() {
  error.value = ''; busy.value = true;
  try {
    await api(editingId.value ? `users/${editingId.value}` : 'users', { method: editingId.value ? 'PUT' : 'POST', body: userForm.value });
    modal.value = ''; await refresh(); toast('User account saved.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}

async function startSupportSession(client) {
  error.value = ''; busy.value = true;
  try {
    const result = await api(`clients/${client.id}/impersonate`, { method: 'POST' });
    session.value = await api('session');
    page.value = 'dashboard';
    location.hash = 'dashboard';
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
    page.value = 'clients';
    location.hash = 'clients';
    search.value = '';
    await refresh();
    toast(result.message);
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
</script>

<template>
  <div v-if="loading" class="loading-screen"><div class="brand-mark">U</div><span>Loading your workspace...</span></div>
  <div v-else-if="!session?.user" class="auth-page">
    <div class="auth-art"><div class="auth-art-content"><div class="brand brand-light"><span class="brand-mark">U</span><span>uddog<span class="brand-dot">.</span></span></div><h1>Keep every product<br>and transaction<br>in focus.</h1><p>A simple workspace for buying, selling, returning, and tracking stock.</p><div class="auth-art-footer">INVENTORY MANAGEMENT, MADE CLEAR</div></div></div>
    <div class="auth-panel"><form class="auth-card" @submit.prevent="authenticate"><div class="auth-mobile-brand brand"><span class="brand-mark">U</span><span>uddog<span class="brand-dot">.</span></span></div><span class="eyebrow">{{ session?.setup_required ? 'GET STARTED' : 'WELCOME BACK' }}</span><h2>{{ session?.setup_required ? 'Create your workspace' : 'Sign in to your workspace' }}</h2><p class="muted">{{ session?.setup_required ? 'Set up the first administrator account.' : 'Enter your account details to continue.' }}</p><div v-if="error" class="alert-box error">{{ error }}</div><label v-if="session?.setup_required" class="field"><span>Full name</span><input v-model="authForm.name" required autocomplete="name" placeholder="Your name"></label><label class="field"><span>Email address</span><input v-model="authForm.email" type="email" required autocomplete="email" placeholder="name@company.com"></label><label class="field"><span>Password</span><input v-model="authForm.password" type="password" required :minlength="session?.setup_required ? 8 : undefined" :autocomplete="session?.setup_required ? 'new-password' : 'current-password'" :placeholder="session?.setup_required ? 'At least 8 characters' : 'Your password'"></label><button class="btn-primary-app full" :disabled="busy">{{ busy ? 'Please wait...' : session?.setup_required ? 'Create workspace' : 'Sign in' }} <ArrowUpRight :size="18" /></button></form></div>
  </div>
  <div v-else-if="!session?.permissions?.use_workspace && !session?.permissions?.manage_clients" class="loading-screen subscription-lock"><ShieldCheck :size="42" /><h2>Subscription access paused</h2><p>{{ session.user.organization?.name || 'Your client account' }} does not have an active subscription. Please contact the superadmin for support.</p><button class="btn-secondary-app" @click="logout"><LogOut :size="17" /> Sign out</button></div>
  <div v-else-if="!session?.permissions?.abilities?.length && !session?.permissions?.manage_clients && !session?.permissions?.manage_users" class="loading-screen subscription-lock"><ShieldCheck :size="42" /><h2>No access assigned yet</h2><p>Your company owner has not assigned any workspace permissions to this account.</p><button class="btn-secondary-app" @click="logout"><LogOut :size="17" /> Sign out</button></div>
  <div v-else class="app-shell">
    <div v-if="mobileOpen" class="mobile-scrim" @click="mobileOpen = false"></div>
    <aside class="sidebar" :class="{ 'sidebar-open': mobileOpen }">
      <div class="brand"><span class="brand-mark">U</span><span>uddog<span class="brand-dot">.</span></span><button class="icon-button sidebar-close" @click="mobileOpen = false"><X :size="20" /></button></div>
      <div class="sidebar-caption">{{ session.user.role === 'superadmin' && !session.impersonation ? 'SAAS CONTROL' : 'WORKSPACE' }}</div>
      <nav class="nav-list"><button v-for="item in nav" :key="item.id" class="nav-item-app" :class="{ active: page === item.id }" @click="navigate(item.id)"><component :is="item.icon" :size="18" :stroke-width="1.9" /><span>{{ item.label }}</span><span v-if="item.id === 'purchase_return'" class="nav-spacer"></span></button></nav>
      <div class="sidebar-bottom"><div class="sidebar-help"><div class="help-icon"><component :is="session.user.role === 'superadmin' && !session.impersonation ? Building2 : Boxes" :size="20" /></div><strong>{{ session.user.role === 'superadmin' && !session.impersonation ? 'Subscription control' : 'Inventory at a glance' }}</strong><p>{{ session.user.role === 'superadmin' && !session.impersonation ? 'Manage companies, validity and owner support.' : 'Track every movement from purchase to sale.' }}</p></div><div class="sidebar-user"><div class="avatar">{{ initial(session.user.name) }}</div><div class="user-copy"><strong>{{ session.user.name }}</strong><span>{{ roleLabel(session.user.role) }}</span></div><button class="icon-button" title="Sign out" @click="logout"><LogOut :size="18" /></button></div></div>
    </aside>
    <div class="main-wrap">
      <div v-if="session.impersonation" class="support-mode-banner"><div><Eye :size="18" /><span><strong>Support mode</strong> — viewing {{ session.impersonation.organization.name }} as {{ session.impersonation.owner.name }}</span></div><button class="support-return-button" :disabled="busy" @click="stopSupportSession"><Undo2 :size="16" /> Return to superadmin</button></div>
      <header class="topbar"><div class="topbar-left"><button class="icon-button mobile-menu" @click="mobileOpen = true"><Menu :size="22" /></button><span class="breadcrumb-home">{{ session.user.role === 'superadmin' && !session.impersonation ? 'Platform' : 'Workspace' }}</span><span class="breadcrumb-slash">/</span><strong>{{ titles[page] || 'Dashboard' }}</strong></div><div class="topbar-right"><label v-if="session.permissions.use_workspace && branches.length" class="branch-switcher"><MapPin :size="15" /><select v-model="activeBranchId" :disabled="busy" aria-label="Active branch" @change="switchBranch"><option v-for="branch in branches.filter(item => item.active)" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select></label><span class="topbar-date">{{ date(today()) }}</span><span class="avatar small">{{ initial(session.user.name) }}</span></div></header>
      <main class="content">
        <div v-if="notice" class="toast-notice"><Check :size="17" />{{ notice }}</div>
        <template v-if="page === 'admin_dashboard'"><div class="page-heading"><div><span class="eyebrow">SAAS OVERVIEW</span><h1>Company subscriptions</h1><p>Monitor every company, subscription validity and owner account from one place.</p></div><button class="btn-primary-app" @click="openClient()"><Plus :size="18" /> Add company</button></div><div class="stats-grid"><div class="stat-card"><div class="stat-top"><span>Total companies</span><span class="stat-icon violet"><Building2 :size="19" /></span></div><strong>{{ clientStats.total }}</strong><small>Companies using your software</small></div><div class="stat-card"><div class="stat-top"><span>Active subscriptions</span><span class="stat-icon green"><ShieldCheck :size="19" /></span></div><strong>{{ clientStats.active }}</strong><small>Active and trial companies</small></div><div class="stat-card"><div class="stat-top"><span>Expiring in 30 days</span><span class="stat-icon orange"><Clock3 :size="19" /></span></div><strong>{{ clientStats.expiring }}</strong><small>Renewal follow-up required</small></div><div class="stat-card"><div class="stat-top"><span>Needs attention</span><span class="stat-icon orange"><AlertTriangle :size="19" /></span></div><strong>{{ clientStats.attention }}</strong><small>Expired, overdue or suspended</small></div></div><section class="panel table-panel admin-subscription-panel"><div class="table-toolbar"><div><h2>Validity and support queue</h2><p>Expired subscriptions and upcoming renewals appear first.</p></div><button class="text-button" @click="navigate('clients')">Manage all companies <ArrowUpRight :size="16" /></button></div><div class="table-scroll"><table><thead><tr><th>COMPANY</th><th>OWNER</th><th>PLAN</th><th>STATUS</th><th>VALID UNTIL</th><th>USERS</th><th>SUPPORT</th></tr></thead><tbody><tr v-for="client in upcomingClients" :key="client.id"><td><div class="name-cell"><span class="product-avatar contact-avatar">{{ initial(client.name) }}</span><strong>{{ client.name }}</strong></div></td><td><div v-if="client.admins?.length" class="name-cell"><div><strong>{{ client.admins[0].name }}</strong><small>{{ client.admins[0].email }}</small></div></div><span v-else>Owner not assigned</span></td><td>{{ client.plan_name || '—' }}</td><td><span class="stock-pill" :class="client.subscription_active ? 'good' : 'low'">{{ clientValidityLabel(client) }}</span></td><td>{{ date(client.subscription_ends_at) }}</td><td>{{ client.users_count }}</td><td><button v-if="client.admins?.some(owner => owner.active)" class="btn-secondary-app small" :disabled="busy" @click="startSupportSession(client)"><Eye :size="15" /> View as owner</button><button v-else class="btn-secondary-app small" @click="openClient(client)"><UserCog :size="15" /> Set up owner</button></td></tr></tbody></table></div><div v-if="!upcomingClients.length" class="empty-state"><ShieldCheck :size="32" /><strong>No renewals need attention</strong><span>Add company validity dates to monitor renewals here.</span></div></section></template>

        <template v-else-if="page === 'roadmap'"><div class="page-heading"><div><span class="eyebrow">PRODUCT ROADMAP</span><h1>Work progress</h1><p>Track the operational modules planned for the inventory platform.</p></div><span class="roadmap-count">{{ roadmapItems.length - roadmapCompleted }} planned modules</span></div><div class="roadmap-summary"><div class="roadmap-summary-copy"><ClipboardList :size="22" /><div><strong>Operational expansion</strong><p>These modules move through planned, in progress, testing and completed stages.</p></div></div><div class="roadmap-progress"><span><strong>{{ roadmapCompleted }}</strong> of {{ roadmapItems.length }} completed</span><div><i :style="{ width: `${(roadmapCompleted / roadmapItems.length) * 100}%` }"></i></div></div></div><section class="roadmap-grid"><article v-for="(item, index) in roadmapItems" :key="item.title" class="roadmap-card"><div class="roadmap-card-top"><span class="roadmap-number">{{ String(index + 1).padStart(2, '0') }}</span><span class="roadmap-status" :class="{ completed: item.status === 'Completed' }">{{ item.status }}</span></div><h2>{{ item.title }}</h2><p>{{ item.description }}</p><div class="roadmap-meta"><span>{{ item.phase }}</span><span :class="`priority-${item.priority.toLowerCase()}`">{{ item.priority }} priority</span></div></article></section></template>

        <template v-else-if="page === 'dashboard'"><div class="page-heading"><div><span class="eyebrow">OVERVIEW</span><h1>Good to see you, {{ session.user.name.split(' ')[0] }} <span class="wave">✦</span></h1><p>Here is what is happening across your inventory today.</p></div><button v-if="hasAbility('sales')" class="btn-primary-app" @click="openDocument('sale')"><Plus :size="18" /> New sale</button></div>
          <div class="stats-grid"><div class="stat-card"><div class="stat-top"><span>Total sales</span><span class="stat-icon violet"><ShoppingCart :size="19" /></span></div><strong>{{ money(overview.sales_total) }}</strong><small>Sales and resales</small></div><div class="stat-card"><div class="stat-top"><span>Purchases</span><span class="stat-icon blue"><ShoppingBag :size="19" /></span></div><strong>{{ money(overview.purchase_total) }}</strong><small>All recorded purchases</small></div><div class="stat-card"><div class="stat-top"><span>Stock value</span><span class="stat-icon green"><Boxes :size="19" /></span></div><strong>{{ money(overview.stock_value) }}</strong><small>At product cost price</small></div><div class="stat-card"><div class="stat-top"><span>Low stock</span><span class="stat-icon orange"><AlertTriangle :size="19" /></span></div><strong>{{ overview.low_stock }}</strong><small>Products at reorder level</small></div></div>
          <div class="dashboard-grid"><section class="panel"><div class="panel-heading"><div><h2>Recent transactions</h2><p>Your latest purchases, sales and returns</p></div><button v-if="hasAbility('sales')" class="text-button" @click="navigate('sale')">View sales <ArrowUpRight :size="16" /></button></div><div v-if="overview.recent_documents.length" class="transaction-list"><div v-for="doc in overview.recent_documents" :key="doc.id" class="transaction-row"><span class="transaction-icon" :class="doc.type"><ArrowDownRight v-if="doc.type === 'purchase'" :size="18" /><ArrowUpRight v-else :size="18" /></span><div class="transaction-title"><strong>{{ doc.number }}</strong><span>{{ doc.contact?.name }} · {{ label(doc.type) }}</span></div><div class="transaction-right"><strong>{{ money(doc.total) }}</strong><span>{{ date(doc.document_date) }}</span></div></div></div><div v-else class="empty-state compact"><Clock3 :size="29" /><strong>No transactions yet</strong><span>Create your first purchase to bring stock in.</span></div></section><section class="panel"><div class="panel-heading"><div><h2>Stock alerts</h2><p>Items that may need attention</p></div><button v-if="hasAbility('inventory', 'stock_adjustments')" class="text-button" @click="navigate('inventory')">Inventory <ArrowUpRight :size="16" /></button></div><div v-if="overview.low_stock_products.length" class="alert-list"><div v-for="product in overview.low_stock_products" :key="product.id" class="alert-row"><span class="product-avatar">{{ initial(product.name) }}</span><div><strong>{{ product.name }}</strong><span>{{ product.sku || 'No SKU' }}</span></div><span class="stock-pill low">{{ qty(product.quantity_on_hand) }} {{ product.unit }}</span></div></div><div v-else class="empty-state compact"><Check :size="29" /><strong>All stocked up</strong><span>No products are at their reorder level.</span></div></section></div>
        </template>

        <template v-else-if="page === 'products'"><div class="page-heading"><div><span class="eyebrow">CATALOG</span><h1>Products</h1><p>Manage products, categories, barcodes and reorder levels.</p></div><button class="btn-primary-app" @click="openProduct()"><Plus :size="18" /> Add product</button></div><section class="panel table-panel"><div class="table-toolbar"><div><h2>Product catalog</h2><p>{{ products.length }} products</p></div><div class="search-box"><Search :size="17" /><input v-model="search" placeholder="Search products, category, SKU or barcode..."></div></div><div class="table-scroll"><table><thead><tr><th>PRODUCT</th><th>SKU</th><th>BARCODE</th><th>CATEGORY</th><th>ON HAND</th><th>AVG. COST</th><th>DEFAULT SALE PRICE</th><th></th></tr></thead><tbody><tr v-for="product in filteredProducts" :key="product.id"><td><div class="name-cell"><span class="product-avatar">{{ initial(product.name) }}</span><div><strong>{{ product.name }}</strong><small>{{ product.unit }} <span v-if="!product.active">· Inactive</span></small></div></div></td><td class="mono">{{ product.sku || '—' }}</td><td class="mono">{{ product.barcode || 'Not generated' }}</td><td>{{ product.category?.name || 'Uncategorized' }}</td><td><span class="stock-pill" :class="lowStock(product) ? 'low' : 'good'">{{ qty(product.quantity_on_hand) }} {{ product.unit }}</span></td><td>{{ money(product.cost_price) }}</td><td>{{ money(product.sale_price) }}</td><td><div class="row-actions"><button class="icon-button" :title="product.barcode ? 'View and print barcode' : 'Generate barcode'" @click="openBarcode(product)"><Barcode :size="18" /></button><button class="icon-button" title="Edit product" @click="openProduct(product)"><Pencil :size="17" /></button></div></td></tr></tbody></table></div><div v-if="!filteredProducts.length" class="empty-state"><Package :size="32" /><strong>No products found</strong><span>Add a product or change your search.</span></div></section></template>

        <template v-else-if="page === 'categories'"><div class="page-heading"><div><span class="eyebrow">CATALOG SETUP</span><h1>Categories</h1><p>Organize products into reusable groups for faster searching and reporting.</p></div><button class="btn-primary-app" @click="openCategory()"><Plus :size="18" /> Add category</button></div><div class="category-stats"><div class="stat-card"><div class="stat-top"><span>Total categories</span><span class="stat-icon violet"><Tags :size="19" /></span></div><strong>{{ categories.length }}</strong><small>Available product groups</small></div><div class="stat-card"><div class="stat-top"><span>Active categories</span><span class="stat-icon green"><Check :size="19" /></span></div><strong>{{ categories.filter(category => category.active).length }}</strong><small>Visible when assigning products</small></div><div class="stat-card"><div class="stat-top"><span>Categorized products</span><span class="stat-icon blue"><Package :size="19" /></span></div><strong>{{ categories.reduce((total, category) => total + Number(category.products_count || 0), 0) }}</strong><small>Products assigned to a category</small></div></div><section class="panel table-panel category-table"><div class="table-toolbar"><div><h2>Category list</h2><p>{{ filteredCategories.length }} of {{ categories.length }} categories</p></div><div class="search-box"><Search :size="17" /><input v-model="search" placeholder="Search categories..."></div></div><div class="table-scroll"><table><thead><tr><th>CATEGORY</th><th>DESCRIPTION</th><th>PRODUCTS</th><th>STATUS</th><th></th></tr></thead><tbody><tr v-for="category in filteredCategories" :key="category.id"><td><div class="name-cell"><span class="product-avatar category-avatar"><Tags :size="16" /></span><strong>{{ category.name }}</strong></div></td><td class="category-description">{{ category.description || '—' }}</td><td><strong>{{ category.products_count }}</strong> products</td><td><span class="stock-pill" :class="category.active ? 'good' : 'low'">{{ category.active ? 'Active' : 'Inactive' }}</span></td><td><div class="row-actions"><button class="icon-button" title="Edit category" @click="openCategory(category)"><Pencil :size="17" /></button><button class="icon-button" title="Delete unused category" :disabled="category.products_count > 0 || busy" @click="deleteCategory(category)"><Trash2 :size="17" /></button></div></td></tr></tbody></table></div><div v-if="!filteredCategories.length" class="empty-state"><Tags :size="32" /><strong>No categories found</strong><span>Create a category to start organizing products.</span></div></section></template>

        <template v-else-if="page === 'branches'"><div class="page-heading"><div><span class="eyebrow">COMPANY SETUP</span><h1>Branches</h1><p>Maintain every store or location and keep its stock and transactions separate.</p></div><button class="btn-primary-app" @click="openBranch()"><Plus :size="18" /> Add branch</button></div><div class="category-stats"><div class="stat-card"><div class="stat-top"><span>Total branches</span><span class="stat-icon violet"><MapPin :size="19" /></span></div><strong>{{ branches.length }}</strong><small>Company locations</small></div><div class="stat-card"><div class="stat-top"><span>Active branches</span><span class="stat-icon green"><Check :size="19" /></span></div><strong>{{ branches.filter(branch => branch.active).length }}</strong><small>Available for transactions</small></div><div class="stat-card"><div class="stat-top"><span>Total branch stock</span><span class="stat-icon blue"><Boxes :size="19" /></span></div><strong>{{ qty(branches.reduce((total, branch) => total + Number(branch.stocks_sum_quantity_on_hand || 0), 0)) }}</strong><small>Units across all locations</small></div></div><section class="panel table-panel"><div class="table-toolbar"><div><h2>Company branches</h2><p>Select the working branch from the top navigation.</p></div></div><div class="table-scroll"><table><thead><tr><th>BRANCH</th><th>CODE</th><th>CONTACT</th><th>STOCK</th><th>TRANSACTIONS</th><th>STATUS</th><th></th></tr></thead><tbody><tr v-for="branch in branches" :key="branch.id"><td><div class="name-cell"><span class="product-avatar category-avatar"><MapPin :size="16" /></span><div><strong>{{ branch.name }}</strong><small>{{ branch.address || 'No address' }}</small></div></div></td><td class="mono">{{ branch.code }}</td><td>{{ branch.phone || '—' }}</td><td>{{ qty(branch.stocks_sum_quantity_on_hand) }}</td><td>{{ branch.documents_count }}</td><td><div class="branch-status"><span class="stock-pill" :class="branch.active ? 'good' : 'low'">{{ branch.active ? 'Active' : 'Inactive' }}</span><span v-if="branch.is_default" class="type-pill">Default</span><span v-if="branch.id === Number(activeBranchId)" class="type-pill current">Current</span></div></td><td><div class="row-actions"><button class="icon-button" title="Edit branch" @click="openBranch(branch)"><Pencil :size="17" /></button><button class="icon-button" title="Delete empty branch" :disabled="branch.is_default || branch.documents_count > 0 || Number(branch.stocks_sum_quantity_on_hand) !== 0 || busy" @click="deleteBranch(branch)"><Trash2 :size="17" /></button></div></td></tr></tbody></table></div></section></template>

        <template v-else-if="page === 'contacts'"><div class="page-heading"><div><span class="eyebrow">DIRECTORY</span><h1>Contacts</h1><p>Keep your customers and suppliers organized.</p></div><button class="btn-primary-app" @click="openContact()"><Plus :size="18" /> Add contact</button></div><section class="panel table-panel"><div class="table-toolbar"><div><h2>All contacts</h2><p>{{ contacts.length }} contacts</p></div><div class="search-box"><Search :size="17" /><input v-model="search" placeholder="Search contacts..."></div></div><div class="table-scroll"><table><thead><tr><th>CONTACT</th><th>TYPE</th><th>PHONE</th><th>EMAIL</th><th>ADDRESS</th><th></th></tr></thead><tbody><tr v-for="contact in filteredContacts" :key="contact.id"><td><div class="name-cell"><span class="product-avatar contact-avatar">{{ initial(contact.name) }}</span><strong>{{ contact.name }}</strong></div></td><td><span class="type-pill">{{ contact.type }}</span></td><td>{{ contact.phone || '—' }}</td><td>{{ contact.email || '—' }}</td><td>{{ contact.address || '—' }}</td><td><button class="icon-button" title="Edit contact" @click="openContact(contact)"><Pencil :size="17" /></button></td></tr></tbody></table></div><div v-if="!filteredContacts.length" class="empty-state"><Users :size="32" /><strong>No contacts found</strong><span>Add a contact or change your search.</span></div></section></template>

        <template v-else-if="page === 'pos'">
          <div class="page-heading pos-heading"><div><span class="eyebrow">FAST CHECKOUT</span><h1>POS Sale</h1><p>Select products, collect payment and issue an invoice from one screen.</p></div><button class="btn-secondary-app" :disabled="!posForm.items.length" @click="resetPos"><Trash2 :size="16" /> Clear cart</button></div>
          <div v-if="error" class="alert-box error">{{ error }}</div>
          <div class="pos-layout">
            <section class="panel pos-catalog">
              <form class="pos-scanner" @submit.prevent="scanPosBarcode"><ScanLine :size="22" /><div><strong>Scan product barcode</strong><span>Keep this field focused and scan; the product is added when Enter is received.</span></div><input v-model="barcodeScan" inputmode="numeric" autocomplete="off" autofocus placeholder="Scan EAN-13 or enter SKU" aria-label="Scan product barcode"><button class="btn-primary-app" type="submit">Add</button></form>
              <div class="pos-catalog-head"><div class="search-box pos-search"><Search :size="18" /><input v-model="posSearch" placeholder="Search by product, SKU, barcode or category..."></div><span>{{ posProducts.length }} available products</span></div>
              <div v-if="posProducts.length" class="pos-product-grid"><button v-for="product in posProducts" :key="product.id" class="pos-product-card" @click="addPosProduct(product)"><span class="product-avatar">{{ initial(product.name) }}</span><div><strong>{{ product.name }}</strong><small>{{ product.sku || product.category?.name || 'No SKU' }}</small></div><span class="pos-product-price">{{ money(product.sale_price) }}</span><small class="pos-product-stock">{{ qty(product.quantity_on_hand) }} {{ product.unit }} in stock</small><span class="pos-add"><Plus :size="15" /> Add</span></button></div>
              <div v-else class="empty-state"><Package :size="32" /><strong>No stocked products found</strong><span>Purchase or adjust stock before creating a POS sale.</span></div>
            </section>
            <aside class="panel pos-checkout">
              <div class="pos-checkout-head"><div><ReceiptText :size="19" /><div><h2>Current sale</h2><p>{{ posForm.items.length }} cart {{ posForm.items.length === 1 ? 'item' : 'items' }}</p></div></div><input v-model="posForm.document_date" type="date" aria-label="Sale date"></div>
              <div class="pos-customer"><label class="field"><span>Customer *</span><div class="field-action"><select v-model="posForm.contact_id" required><option value="" disabled>Select a customer</option><option v-for="customer in posCustomers" :key="customer.id" :value="customer.id">{{ customer.name }}{{ customer.phone ? ` · ${customer.phone}` : '' }}</option></select><button class="btn-secondary-app small create-inline" type="button" @click="createPosCustomer"><Plus :size="16" /> New</button></div><small v-if="!posCustomers.length">Create a customer before checkout.</small></label></div>
              <div v-if="posForm.items.length" class="pos-cart"><div v-for="(item, index) in posForm.items" :key="item.product_id" class="pos-cart-item"><div class="pos-cart-title"><div><strong>{{ item.name }}</strong><small>{{ item.sku || `${qty(item.stock)} ${item.unit} available` }}</small></div><button class="icon-button" title="Remove" @click="posForm.items.splice(index, 1)"><Trash2 :size="15" /></button></div><div class="pos-cart-controls"><div class="quantity-control"><button @click="changePosQuantity(item, -1)"><Minus :size="14" /></button><input v-model="item.quantity" type="number" min="0.001" :max="item.stock" step="0.001" aria-label="Quantity"><button @click="changePosQuantity(item, 1)"><Plus :size="14" /></button></div><label><span>Price</span><input v-model="item.unit_price" type="number" min="0" step="0.01"></label><strong>{{ money(Number(item.quantity) * Number(item.unit_price)) }}</strong></div><small v-if="Number(item.quantity) > Number(item.stock)" class="pos-line-error">Only {{ qty(item.stock) }} {{ item.unit }} available.</small></div></div>
              <div v-else class="empty-state pos-empty"><ShoppingCart :size="30" /><strong>Your cart is empty</strong><span>Choose a product to begin the sale.</span></div>
              <div class="pos-summary"><div><span>Subtotal</span><strong>{{ money(posSubtotal) }}</strong></div><label><span>Discount</span><input v-model="posForm.discount" type="number" min="0" :max="posSubtotal" step="0.01"></label><label><span>Tax</span><input v-model="posForm.tax" type="number" min="0" step="0.01"></label><div class="pos-grand-total"><span>Total</span><strong>{{ money(posTotal) }}</strong></div></div>
              <div class="pos-payment"><div class="pos-section-title"><Banknote :size="17" /><strong>Payment</strong></div><div class="pos-payment-grid"><label class="field"><span>Method *</span><select v-model="posForm.payment_method" @change="setPosPayment"><option value="cash">Cash</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option><option value="bank_transfer">Bank transfer</option><option value="credit">Credit / due</option></select></label><label class="field"><span>Amount paid *</span><input v-model="posForm.amount_paid" type="number" min="0" :max="posTotal" step="0.01"><button type="button" class="text-button pos-exact" @click="setPosPayment">Use exact total</button></label></div><div class="pos-balance" :class="{ due: posBalance > 0 }"><span>Balance due</span><strong>{{ money(posBalance) }}</strong></div><label class="field"><span>Notes</span><textarea v-model="posForm.notes" rows="2" placeholder="Optional sale notes"></textarea></label></div>
              <button class="btn-primary-app pos-complete" :disabled="busy || !posCanCheckout" @click="completePosSale"><ReceiptText :size="18" /> {{ busy ? 'Completing sale...' : `Complete sale · ${money(posTotal)}` }}</button>
            </aside>
          </div>
        </template>

        <template v-else-if="['purchase', 'sale', 'resale', 'purchase_return', 'sale_return'].includes(page)"><div class="page-heading"><div><span class="eyebrow">TRANSACTIONS</span><h1>{{ titles[page] }}</h1><p>{{ page === 'purchase_return' ? 'Return items to suppliers with a linked purchase.' : page === 'sale_return' ? 'Refund sold items and restore them to inventory.' : `Track every ${label(page).toLowerCase()} and its stock movement.` }}</p></div><button class="btn-primary-app" @click="openDocument(page)"><Plus :size="18" /> New {{ label(page).toLowerCase() }}</button></div><section class="panel table-panel"><div class="table-toolbar"><div><h2>{{ titles[page] }} history</h2><p>{{ filteredDocuments.length }} records</p></div><div class="search-box"><Search :size="17" /><input v-model="search" placeholder="Search invoice or contact..."></div></div><div class="table-scroll"><table><thead><tr><th>SL</th><th>DATE &amp; TIME</th><th>{{ ['purchase', 'purchase_return'].includes(page) ? 'SUPPLIER' : 'CUSTOMER' }}</th><th>ITEMS</th><th>TOTAL</th><th v-if="['sale', 'resale'].includes(page)">STOCK PROFIT</th><th v-if="['sale', 'resale'].includes(page)">TOTAL PROFIT</th><th v-if="['sale', 'resale'].includes(page)">PROCESS BY</th><th>ACTIONS</th></tr></thead><tbody v-for="(doc, index) in filteredDocuments" :key="doc.id"><tr><td class="mono strong">{{ index + 1 }}</td><td class="date-time-cell">{{ documentDateTime(doc) }}</td><td>{{ doc.contact?.name }}</td><td>{{ doc.items.length }} {{ doc.items.length === 1 ? 'item' : 'items' }}</td><td class="strong">{{ money(doc.total) }}</td><td v-if="['sale', 'resale'].includes(page)" class="profit-cell">{{ money(doc.stock_profit) }}</td><td v-if="['sale', 'resale'].includes(page)" class="profit-cell">{{ money(doc.total_profit) }}</td><td v-if="['sale', 'resale'].includes(page)">{{ doc.creator?.name || '—' }}</td><td><div class="row-actions"><button class="btn-secondary-app small" title="View invoice" @click="openInvoice(doc)"><Eye :size="15" /> Invoice</button><button class="icon-button" title="Show details" @click="detailId = detailId === doc.id ? null : doc.id"><ChevronDown :size="18" :class="{ rotated: detailId === doc.id }" /></button></div></td></tr><tr v-if="detailId === doc.id" class="detail-row"><td :colspan="['sale', 'resale'].includes(page) ? 9 : 6"><div class="detail-card"><div class="detail-meta"><span>Invoice: <strong>{{ doc.number }}</strong></span><span v-if="doc.purchase">Original purchase: <strong>{{ doc.purchase.number }}</strong></span><span v-if="doc.sale">Original sale: <strong>{{ doc.sale.number }}</strong></span><span v-if="doc.notes">{{ doc.notes }}</span></div><div v-for="item in doc.items" :key="item.id" class="detail-line"><span>{{ item.product?.name }} <small v-if="item.product?.sku">({{ item.product.sku }})</small></span><span>{{ qty(item.quantity) }} × {{ money(item.unit_price) }}</span><strong>{{ money(item.line_total) }}</strong></div><div class="detail-totals"><span>Subtotal {{ money(doc.subtotal) }} · Discount {{ money(doc.discount) }} · Tax {{ money(doc.tax) }}</span><strong>Total {{ money(doc.total) }}</strong></div></div></td></tr></tbody></table></div><div v-if="!filteredDocuments.length" class="empty-state"><ShoppingCart :size="32" /><strong>No {{ titles[page].toLowerCase() }} yet</strong><span>Create a record to see it here.</span></div></section></template>

        <template v-else-if="page === 'inventory'"><div class="page-heading"><div><span class="eyebrow">STOCK CONTROL</span><h1>Inventory</h1><p>See current balances and the history behind every change.</p></div><button v-if="hasAbility('stock_adjustments')" class="btn-primary-app" @click="openAdjustment()"><Plus :size="18" /> Adjust stock</button></div><div class="inventory-grid"><section class="panel table-panel"><div class="table-toolbar"><div><h2>Stock on hand</h2><p>{{ products.length }} products</p></div></div><div class="table-scroll"><table><thead><tr><th>PRODUCT</th><th>AVAILABLE</th><th>REORDER AT</th><th v-if="hasAbility('stock_adjustments')"></th></tr></thead><tbody><tr v-for="product in products" :key="product.id"><td><div class="name-cell"><span class="product-avatar">{{ initial(product.name) }}</span><div><strong>{{ product.name }}</strong><small>{{ product.sku || 'No SKU' }}</small></div></div></td><td><span class="stock-pill" :class="lowStock(product) ? 'low' : 'good'">{{ qty(product.quantity_on_hand) }} {{ product.unit }}</span></td><td>{{ qty(product.reorder_level) }}</td><td v-if="hasAbility('stock_adjustments')"><button class="icon-button" title="Adjust stock" @click="openAdjustment(product)"><Pencil :size="17" /></button></td></tr></tbody></table></div><div v-if="!products.length" class="empty-state"><Package :size="32" /><strong>No products yet</strong><span>Add products to start tracking stock.</span></div></section><section class="panel movements-panel"><div class="panel-heading"><div><h2>Recent movements</h2><p>Latest 200 entries</p></div></div><div v-if="movements.length" class="movement-list"><div v-for="movement in movements" :key="movement.id" class="movement-row"><span class="movement-symbol" :class="Number(movement.quantity_change) > 0 ? 'in' : 'out'"><ArrowDownRight v-if="Number(movement.quantity_change) > 0" :size="18" /><ArrowUpRight v-else :size="18" /></span><div class="movement-copy"><strong>{{ movement.product?.name }}</strong><span>{{ movement.document?.number || label(movement.type) }} · {{ date(movement.created_at) }}</span><small v-if="movement.notes">{{ movement.notes }}</small></div><div class="movement-qty" :class="Number(movement.quantity_change) > 0 ? 'positive' : 'negative'"><strong>{{ Number(movement.quantity_change) > 0 ? '+' : '' }}{{ qty(movement.quantity_change) }}</strong><span>Balance {{ qty(movement.balance_after) }}</span></div></div></div><div v-else class="empty-state compact"><Boxes :size="32" /><strong>No stock movements</strong><span>Purchases and adjustments will appear here.</span></div></section></div></template>

        <template v-else-if="page === 'stock_checks'">
          <div class="page-heading"><div><span class="eyebrow">PHYSICAL INVENTORY</span><h1>Stock Check</h1><p>Count the products in the current branch and reconcile any variance.</p></div><button class="btn-primary-app" :disabled="busy || stockChecks.some(check => check.status === 'draft')" :title="stockChecks.some(check => check.status === 'draft') ? 'Complete the current draft first' : 'Start a new physical count'" @click="startStockCheck"><Plus :size="18" /> Start stock check</button></div>
          <div class="category-stats"><div class="stat-card"><div class="stat-top"><span>Total checks</span><span class="stat-icon violet"><ClipboardCheck :size="19" /></span></div><strong>{{ stockChecks.length }}</strong><small>Count sessions for this branch</small></div><div class="stat-card"><div class="stat-top"><span>Draft</span><span class="stat-icon blue"><Clock3 :size="19" /></span></div><strong>{{ stockChecks.filter(check => check.status === 'draft').length }}</strong><small>Count waiting to be completed</small></div><div class="stat-card"><div class="stat-top"><span>Completed</span><span class="stat-icon green"><Check :size="19" /></span></div><strong>{{ stockChecks.filter(check => check.status === 'completed').length }}</strong><small>Reconciled count sessions</small></div></div>
          <section class="panel table-panel"><div class="table-toolbar"><div><h2>Stock check history</h2><p>{{ stockChecks.length }} records in {{ branches.find(branch => branch.id === Number(activeBranchId))?.name || 'current branch' }}</p></div></div><div class="table-scroll"><table><thead><tr><th>SL</th><th>NUMBER</th><th>DATE</th><th>PRODUCTS</th><th>COUNTED</th><th>VARIANCE</th><th>STATUS</th><th>CREATED BY</th><th></th></tr></thead><tbody><tr v-for="(stockCheck, index) in stockChecks" :key="stockCheck.id"><td class="mono strong">{{ index + 1 }}</td><td class="mono strong">{{ stockCheck.number }}</td><td>{{ date(stockCheck.check_date) }}</td><td>{{ stockCheck.items.length }}</td><td>{{ stockCheck.items.filter(item => item.counted_quantity !== null).length }} / {{ stockCheck.items.length }}</td><td><span class="stock-check-variance" :class="{ positive: checkVariance(stockCheck) > 0, negative: checkVariance(stockCheck) < 0 }">{{ checkVariance(stockCheck) > 0 ? '+' : '' }}{{ qty(checkVariance(stockCheck)) }}</span></td><td><span class="stock-pill" :class="stockCheck.status === 'completed' ? 'good' : 'pending'">{{ stockCheck.status === 'completed' ? 'Completed' : 'Draft' }}</span></td><td>{{ stockCheck.creator?.name || '—' }}</td><td><button class="btn-secondary-app small" @click="openStockCheck(stockCheck)"><Eye :size="15" /> {{ stockCheck.status === 'draft' ? 'Continue count' : 'View' }}</button></td></tr></tbody></table></div><div v-if="!stockChecks.length" class="empty-state"><ClipboardCheck :size="32" /><strong>No stock checks yet</strong><span>Start a physical count for the selected branch.</span></div></section>
        </template>

        <template v-else-if="page === 'stock_transfers'">
          <div class="page-heading"><div><span class="eyebrow">BRANCH INVENTORY</span><h1>Stock Transfer</h1><p>Move available products from the current branch to another company branch.</p></div><button class="btn-primary-app" :disabled="busy || !transferDestinations.length" :title="!transferDestinations.length ? 'Create another active branch before transferring stock' : 'Transfer stock to another branch'" @click="openStockTransfer"><Plus :size="18" /> New transfer</button></div>
          <div class="transfer-route-banner"><div class="transfer-branch"><span class="stat-icon violet"><MapPin :size="19" /></span><div><small>TRANSFER FROM</small><strong>{{ branches.find(branch => branch.id === Number(activeBranchId))?.name }}</strong></div></div><ArrowLeftRight :size="22" /><div><strong>{{ transferDestinations.length }}</strong><span>available destination {{ transferDestinations.length === 1 ? 'branch' : 'branches' }}</span></div></div>
          <section class="panel table-panel"><div class="table-toolbar"><div><h2>Transfer history</h2><p>{{ stockTransfers.length }} records involving the current branch</p></div></div><div class="table-scroll"><table><thead><tr><th>SL</th><th>NUMBER</th><th>DATE</th><th>DIRECTION</th><th>ROUTE</th><th>ITEMS</th><th>TOTAL QTY</th><th>PROCESS BY</th></tr></thead><tbody><tr v-for="(transfer, index) in stockTransfers" :key="transfer.id"><td class="mono strong">{{ index + 1 }}</td><td class="mono strong">{{ transfer.number }}</td><td>{{ date(transfer.transfer_date) }}</td><td><span class="transfer-direction" :class="transfer.from_branch_id === Number(activeBranchId) ? 'out' : 'in'">{{ transfer.from_branch_id === Number(activeBranchId) ? 'Sent' : 'Received' }}</span></td><td><div class="transfer-route"><strong>{{ transfer.from_branch?.name }}</strong><ArrowLeftRight :size="14" /><strong>{{ transfer.to_branch?.name }}</strong></div></td><td>{{ transfer.items.length }}</td><td>{{ qty(transfer.items.reduce((total, item) => total + Number(item.quantity), 0)) }}</td><td>{{ transfer.creator?.name || '—' }}</td></tr></tbody></table></div><div v-if="!stockTransfers.length" class="empty-state"><ArrowLeftRight :size="32" /><strong>No stock transfers yet</strong><span>Move stock between branches to see the transfer history here.</span></div></section>
        </template>

        <template v-else-if="page === 'clients'"><div class="page-heading"><div><span class="eyebrow">SUPERADMIN</span><h1>Companies</h1><p>Manage store owners, subscription validity and support access.</p></div><button class="btn-primary-app" @click="openClient()"><Plus :size="18" /> Add company</button></div><section class="panel table-panel"><div class="table-toolbar"><div><h2>All companies</h2><p>{{ filteredClients.length }} of {{ clients.length }} companies</p></div><div class="company-toolbar-actions"><select v-model="clientStatusFilter" class="filter-select" aria-label="Filter companies by subscription"><option value="all">All subscriptions</option><option value="active">Currently valid</option><option value="expiring">Expiring in 30 days</option><option value="expired">Expired</option><option value="past_due">Past due</option><option value="suspended">Suspended</option><option value="cancelled">Cancelled</option></select><div class="search-box"><Search :size="17" /><input v-model="search" placeholder="Search companies..."></div></div></div><div class="table-scroll"><table><thead><tr><th>COMPANY</th><th>OWNER ADMIN</th><th>PLAN</th><th>SUBSCRIPTION</th><th>VALID UNTIL</th><th>ACTIVITY</th><th></th></tr></thead><tbody><tr v-for="client in filteredClients" :key="client.id"><td><button class="company-name-button" @click="openClientDetails(client)"><span class="product-avatar contact-avatar">{{ initial(client.name) }}</span><strong>{{ client.name }}</strong></button></td><td><div v-if="client.admins?.length" class="name-cell"><div><strong>{{ client.admins[0].name }}</strong><small>{{ client.admins[0].email }}</small></div></div><span v-else>Not assigned</span></td><td>{{ client.plan_name || '—' }}</td><td><span class="stock-pill" :class="client.subscription_active ? 'good' : 'low'">{{ clientValidityLabel(client) }}</span></td><td>{{ date(client.subscription_ends_at) }}</td><td><span class="activity-summary">{{ client.users_count }} users · {{ client.products_count }} products · {{ client.documents_count }} transactions</span></td><td><div class="row-actions"><button class="icon-button" title="View company details" @click="openClientDetails(client)"><Info :size="17" /></button><button v-if="client.admins?.some(owner => owner.active)" class="btn-secondary-app small" :disabled="busy" title="Open this company as its owner administrator" @click="startSupportSession(client)"><Eye :size="15" /> View as owner</button><button v-else class="btn-secondary-app small" title="Create or activate this company's owner administrator" @click="openClient(client)"><UserCog :size="15" /> Set up owner</button><button class="icon-button" title="Edit company" @click="openClient(client)"><Pencil :size="17" /></button></div></td></tr></tbody></table></div><div v-if="!filteredClients.length" class="empty-state"><Building2 :size="32" /><strong>No companies found</strong><span>Add a subscribed company or change your filters.</span></div></section></template>

        <template v-else-if="page === 'users'"><div class="page-heading"><div><span class="eyebrow">ACCESS CONTROL</span><h1>Users</h1><p>{{ session.user.role === 'superadmin' ? 'Manage owner admins and users across every company.' : 'Create manager and staff accounts and choose exactly what they can use.' }}</p></div><button class="btn-primary-app" @click="openUser()"><Plus :size="18" /> Add user</button></div><section class="panel table-panel"><div class="table-toolbar"><div><h2>User accounts</h2><p>{{ users.length }} users</p></div><div class="search-box"><Search :size="17" /><input v-model="search" placeholder="Search users..."></div></div><div class="table-scroll"><table><thead><tr><th>USER</th><th>ROLE</th><th v-if="session.user.role === 'superadmin'">COMPANY</th><th>ACCESS</th><th>STATUS</th><th></th></tr></thead><tbody><tr v-for="user in filteredUsers" :key="user.id"><td><div class="name-cell"><span class="product-avatar">{{ initial(user.name) }}</span><div><strong>{{ user.name }}</strong><small>{{ user.email }}</small></div></div></td><td><span class="type-pill">{{ roleLabel(user.role) }}</span></td><td v-if="session.user.role === 'superadmin'">{{ user.organization?.name || 'System' }}</td><td><span v-if="['superadmin', 'admin'].includes(user.role)">Full access</span><span v-else>{{ user.permissions?.length || 0 }} permissions</span></td><td><span class="stock-pill" :class="user.active ? 'good' : 'low'">{{ user.active ? 'Active' : 'Inactive' }}</span></td><td><button class="icon-button" title="Edit user" @click="openUser(user)"><Pencil :size="17" /></button></td></tr></tbody></table></div><div v-if="!filteredUsers.length" class="empty-state"><UserCog :size="32" /><strong>No staff found</strong><span>Add a manager or staff account to begin delegating access.</span></div></section></template>
      </main>
    </div>

    <div v-if="modal" class="modal-backdrop-app" @click.self="closeModal()"><div class="modal-card" :class="{ wide: ['document', 'client_details', 'invoice', 'stock_check', 'stock_transfer'].includes(modal) }"><div class="modal-head"><div><span class="eyebrow">{{ modal === 'document' ? 'NEW TRANSACTION' : modal === 'invoice' ? 'INVOICE' : modal === 'stock_check' ? 'PHYSICAL COUNT' : modal === 'stock_transfer' ? 'BRANCH INVENTORY' : modal === 'barcode' ? 'PRODUCT LABEL' : modal === 'category' ? 'CATALOG SETUP' : modal === 'branch' ? 'COMPANY LOCATION' : modal === 'adjustment' ? 'STOCK CONTROL' : modal === 'client_details' ? 'COMPANY DETAILS' : ['client', 'user'].includes(modal) ? 'ACCESS MANAGEMENT' : 'DETAILS' }}</span><h2>{{ modal === 'product' ? (editingId ? 'Edit product' : 'Add product') : modal === 'category' ? (editingId ? 'Edit category' : 'Add category') : modal === 'branch' ? (editingId ? 'Edit branch' : 'Add branch') : modal === 'stock_check' ? selectedStockCheck?.number : modal === 'stock_transfer' ? 'New stock transfer' : modal === 'barcode' ? 'Print barcode' : modal === 'contact' ? (editingId ? 'Edit contact' : documentCreateContext === 'contact' ? (relatedContactName === 'supplier' ? 'Add supplier' : 'Add customer') : 'Add contact') : modal === 'adjustment' ? 'Adjust stock' : modal === 'client' ? (editingId ? 'Edit company' : 'Add company') : modal === 'user' ? (editingId ? 'Edit user' : 'Add user') : modal === 'client_details' ? selectedClient?.name : modal === 'invoice' ? selectedDocument?.number : `New ${label(documentForm.type).toLowerCase()}` }}</h2></div><button class="icon-button" @click="closeModal()"><X :size="20" /></button></div><div v-if="error" class="alert-box error">{{ error }}</div>
      <form v-if="modal === 'stock_transfer'" class="stock-transfer-sheet" @submit.prevent="saveStockTransfer">
        <div class="stock-transfer-route"><div><span>FROM BRANCH</span><strong>{{ branches.find(branch => branch.id === Number(activeBranchId))?.name }}</strong><small>{{ products.filter(product => product.active && Number(product.quantity_on_hand) > 0).length }} products available</small></div><ArrowLeftRight :size="24" /><label><span>TO BRANCH *</span><select v-model="stockTransferForm.to_branch_id" required><option value="" disabled>Select destination</option><option v-for="branch in transferDestinations" :key="branch.id" :value="branch.id">{{ branch.name }} · {{ branch.code }}</option></select></label></div>
        <div class="form-grid transfer-details"><label class="field"><span>Transfer date *</span><input v-model="stockTransferForm.transfer_date" type="date" required></label><label class="field"><span>Notes</span><input v-model="stockTransferForm.notes" maxlength="2000" placeholder="Optional transfer notes"></label></div>
        <div class="items-heading"><div><h3>Products to transfer</h3><p>Quantities are taken from the currently selected source branch.</p></div><button type="button" class="btn-secondary-app small" @click="stockTransferForm.items.push({ product_id: '', quantity: 1 })"><Plus :size="16" /> Add line</button></div>
        <div class="items-list"><div v-for="(item, index) in stockTransferForm.items" :key="index" class="transfer-item-row"><label class="field"><span>Product *</span><select v-model="item.product_id" required><option value="" disabled>Select stocked product</option><option v-for="product in products.filter(product => product.active && Number(product.quantity_on_hand) > 0)" :key="product.id" :value="product.id">{{ productOption(product) }} · {{ qty(product.quantity_on_hand) }} {{ product.unit }}</option></select></label><label class="field"><span>Quantity *</span><input v-model="item.quantity" type="number" min="0.001" :max="products.find(product => product.id === Number(item.product_id))?.quantity_on_hand" step="0.001" required><small v-if="item.product_id">Available: {{ qty(products.find(product => product.id === Number(item.product_id))?.quantity_on_hand) }} {{ products.find(product => product.id === Number(item.product_id))?.unit }}</small></label><button type="button" class="icon-button remove-line" title="Remove line" :disabled="stockTransferForm.items.length === 1" @click="stockTransferForm.items.splice(index, 1)"><Trash2 :size="17" /></button></div></div>
        <div v-if="!products.some(product => product.active && Number(product.quantity_on_hand) > 0)" class="alert-box error">The current branch has no available stock to transfer.</div>
        <div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy || !stockTransferCanSave"><ArrowLeftRight :size="17" /> {{ busy ? 'Transferring...' : 'Complete transfer' }}</button></div>
      </form>
      <div v-else-if="modal === 'stock_check' && selectedStockCheck" class="stock-check-sheet">
        <div class="stock-check-meta"><div><span>Branch</span><strong>{{ selectedStockCheck.branch?.name }}</strong></div><label><span>Count date</span><input v-model="stockCheckForm.check_date" type="date" :disabled="selectedStockCheck.status === 'completed'"></label><div><span>Status</span><strong><span class="stock-pill" :class="selectedStockCheck.status === 'completed' ? 'good' : 'pending'">{{ selectedStockCheck.status === 'completed' ? 'Completed' : 'Draft' }}</span></strong></div><div><span>Total variance</span><strong class="stock-check-variance" :class="{ positive: stockCheckVariance > 0, negative: stockCheckVariance < 0 }">{{ stockCheckVariance > 0 ? '+' : '' }}{{ qty(stockCheckVariance) }}</strong></div></div>
        <div class="stock-check-table-wrap"><table class="stock-check-table"><thead><tr><th>SL</th><th>PRODUCT</th><th>EXPECTED</th><th>PHYSICAL COUNT</th><th>VARIANCE</th></tr></thead><tbody><tr v-for="(item, index) in stockCheckForm.items" :key="item.id"><td>{{ index + 1 }}</td><td><div class="name-cell"><span class="product-avatar">{{ initial(item.product?.name) }}</span><div><strong>{{ item.product?.name }}</strong><small>{{ item.product?.sku || 'No SKU' }}</small></div></div></td><td>{{ qty(item.expected_quantity) }} {{ item.product?.unit }}</td><td><input v-model="item.counted_quantity" class="stock-count-input" type="number" min="0" step="0.001" placeholder="Enter count" :disabled="selectedStockCheck.status === 'completed'"></td><td><span v-if="item.counted_quantity !== ''" class="stock-check-variance" :class="{ positive: Number(item.counted_quantity) - Number(item.expected_quantity) > 0, negative: Number(item.counted_quantity) - Number(item.expected_quantity) < 0 }">{{ Number(item.counted_quantity) - Number(item.expected_quantity) > 0 ? '+' : '' }}{{ qty(Number(item.counted_quantity) - Number(item.expected_quantity)) }}</span><span v-else>—</span></td></tr></tbody></table></div>
        <label class="field stock-check-notes"><span>Count notes</span><textarea v-model="stockCheckForm.notes" rows="3" maxlength="2000" placeholder="Optional notes about this physical count" :disabled="selectedStockCheck.status === 'completed'"></textarea></label>
        <div v-if="selectedStockCheck.status === 'draft'" class="stock-check-completion"><div><strong>{{ uncountedStockItems ? `${uncountedStockItems} products still need a count` : 'Ready to complete' }}</strong><span>{{ uncountedStockItems ? 'Enter a physical quantity for every product.' : 'Completing will reconcile branch stock and create an audit movement for each variance.' }}</span></div></div>
        <div class="modal-actions"><button class="btn-secondary-app" @click="closeModal()">Close</button><template v-if="selectedStockCheck.status === 'draft'"><button class="btn-secondary-app" :disabled="busy" @click="saveStockCheck()">{{ busy ? 'Saving...' : 'Save draft' }}</button><button class="btn-primary-app" :disabled="busy || uncountedStockItems > 0" @click="completeStockCheck"><Check :size="17" /> {{ busy ? 'Completing...' : 'Complete and reconcile' }}</button></template></div>
      </div>
      <div v-else-if="modal === 'barcode' && barcodeProduct" class="barcode-sheet"><div class="barcode-label"><strong>{{ barcodeProduct.name }}</strong><small v-if="barcodeProduct.sku">SKU: {{ barcodeProduct.sku }}</small><div class="barcode-art" v-html="barcodeMarkup"></div><span>{{ money(barcodeProduct.sale_price) }}</span></div><p>EAN-13 barcode labels can be printed and scanned from the POS Sale page.</p><div class="modal-actions"><button class="btn-secondary-app" @click="closeModal()">Close</button><button class="btn-primary-app" @click="printBarcode"><Printer :size="17" /> Print barcode</button></div></div>
      <div v-else-if="modal === 'invoice' && selectedDocument" class="invoice-sheet"><div class="invoice-company"><div><span class="brand-mark">U</span><div><h3>{{ session.user.organization?.name || 'Uddog Inventory' }}</h3><p>Inventory management invoice</p></div></div><span class="invoice-type">{{ selectedDocument.sale_channel === 'pos' ? 'POS SALE' : label(selectedDocument.type) }}</span></div><div class="invoice-meta"><div><span>Invoice number</span><strong>{{ selectedDocument.number }}</strong></div><div><span>Date &amp; time</span><strong>{{ documentDateTime(selectedDocument) }}</strong></div><div><span>{{ ['purchase', 'purchase_return'].includes(selectedDocument.type) ? 'Supplier' : 'Customer' }}</span><strong>{{ selectedDocument.contact?.name }}</strong><small v-if="selectedDocument.contact?.phone">{{ selectedDocument.contact.phone }}</small></div><div><span>Processed by</span><strong>{{ selectedDocument.creator?.name || session.user.name }}</strong></div></div><div class="invoice-table-wrap"><table class="invoice-table"><thead><tr><th>SL</th><th>PRODUCT</th><th>QTY</th><th>UNIT PRICE</th><th>AMOUNT</th></tr></thead><tbody><tr v-for="(item, index) in selectedDocument.items" :key="item.id"><td>{{ index + 1 }}</td><td><strong>{{ item.product?.name }}</strong><small v-if="item.product?.sku">{{ item.product.sku }}</small></td><td>{{ qty(item.quantity) }} {{ item.product?.unit }}</td><td>{{ money(item.unit_price) }}</td><td>{{ money(item.line_total) }}</td></tr></tbody></table></div><div class="invoice-summary"><div v-if="selectedDocument.notes" class="invoice-notes"><strong>Notes</strong><p>{{ selectedDocument.notes }}</p></div><div class="invoice-totals"><div><span>Subtotal</span><strong>{{ money(selectedDocument.subtotal) }}</strong></div><div><span>Discount</span><strong>{{ money(selectedDocument.discount) }}</strong></div><div><span>Tax</span><strong>{{ money(selectedDocument.tax) }}</strong></div><div class="invoice-grand-total"><span>Total</span><strong>{{ money(selectedDocument.total) }}</strong></div><template v-if="selectedDocument.sale_channel === 'pos'"><div><span>Payment method</span><strong>{{ paymentLabel(selectedDocument.payment_method) }}</strong></div><div><span>Paid</span><strong>{{ money(selectedDocument.amount_paid) }}</strong></div><div><span>Balance due</span><strong>{{ money(selectedDocument.balance_due) }}</strong></div></template></div></div><div class="invoice-footer">Thank you for your business.</div><div class="modal-actions"><button class="btn-secondary-app" @click="closeModal()">Close</button><button class="btn-primary-app" @click="printInvoice">Print invoice</button></div></div>
      <form v-else-if="modal === 'product'" @submit.prevent="saveProduct"><div class="form-grid"><label class="field"><span>Product name *</span><input v-model="productForm.name" required placeholder="e.g. Wireless Mouse"></label><label class="field"><span>SKU (optional)</span><input v-model="productForm.sku" placeholder="e.g. WM-001"></label><div class="field span-2"><span>EAN-13 barcode (optional)</span><div class="field-action"><input v-model="productForm.barcode" inputmode="numeric" pattern="\d{13}" maxlength="13" placeholder="13-digit product barcode"><button type="button" class="btn-secondary-app small create-inline" @click="generateBarcode"><Barcode :size="16" /> Generate</button></div><small>Use an existing EAN-13 code or generate a unique label for this product.</small></div><div class="field"><span>Category</span><div class="field-action"><select v-model="productForm.category_id"><option value="">Uncategorized</option><option v-for="category in selectableCategories" :key="category.id" :value="category.id">{{ category.name }}{{ category.active ? '' : ' (inactive)' }}</option></select><button v-if="hasAbility('categories')" type="button" class="btn-secondary-app small create-inline" @click="openCategory(null, 'product_category')"><Plus :size="16" /> New</button></div></div><label class="field"><span>Unit *</span><select v-model="productForm.unit" required><option v-for="unit in unitOptions" :key="unit.value" :value="unit.value">{{ unit.label }}</option></select></label><label class="field"><span>Opening/default cost (optional)</span><input v-model="productForm.cost_price" type="number" min="0" step="0.01" placeholder="Calculated from purchases"><small>The average cost updates when purchases are recorded.</small></label><label class="field"><span>Default sale price (optional)</span><input v-model="productForm.sale_price" type="number" min="0" step="0.01" placeholder="Enter during each sale"><small>You can enter a different price on every sale.</small></label><label class="field"><span>Reorder level *</span><input v-model="productForm.reorder_level" type="number" min="0" step="0.001" required></label><label class="field checkbox-field"><input v-model="productForm.active" type="checkbox" :disabled="documentCreateContext === 'product'"><span>Active product</span></label></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Saving...' : 'Save product' }}</button></div></form>
      <form v-else-if="modal === 'category'" @submit.prevent="saveCategory"><div class="form-grid"><label class="field span-2"><span>Category name *</span><input v-model="categoryForm.name" required maxlength="100" placeholder="e.g. Accessories"></label><label class="field span-2"><span>Description</span><textarea v-model="categoryForm.description" rows="3" maxlength="1000" placeholder="Optional notes about products in this category"></textarea></label><label class="field checkbox-field span-2"><input v-model="categoryForm.active" type="checkbox"><span>Active category</span></label></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Saving...' : documentCreateContext === 'product_category' ? 'Save and select category' : 'Save category' }}</button></div></form>
      <form v-else-if="modal === 'branch'" @submit.prevent="saveBranch"><div class="form-grid"><label class="field"><span>Branch name *</span><input v-model="branchForm.name" required maxlength="100" placeholder="e.g. Uttara Store"></label><label class="field"><span>Branch code *</span><input v-model="branchForm.code" required maxlength="30" pattern="[A-Za-z0-9_-]+" placeholder="e.g. UTTARA"></label><label class="field span-2"><span>Phone</span><input v-model="branchForm.phone" maxlength="50" placeholder="Branch contact number"></label><label class="field span-2"><span>Address</span><textarea v-model="branchForm.address" rows="3" maxlength="1000" placeholder="Branch street and area"></textarea></label><label class="field checkbox-field"><input v-model="branchForm.active" type="checkbox" :disabled="branchForm.is_default"><span>Active branch</span></label><label class="field checkbox-field"><input v-model="branchForm.is_default" type="checkbox"><span>Default branch</span></label></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Saving...' : 'Save branch' }}</button></div></form>
      <form v-else-if="modal === 'contact'" @submit.prevent="saveContact"><div class="form-grid"><label class="field"><span>Contact name *</span><input v-model="contactForm.name" required placeholder="Company or person"></label><label class="field"><span>Contact type *</span><select v-model="contactForm.type"><option v-if="documentCreateContext !== 'contact' || documentForm.type !== 'purchase'" value="customer">Customer</option><option v-if="documentCreateContext !== 'contact' || documentForm.type === 'purchase'" value="supplier">Supplier</option><option value="both">Both</option></select></label><label class="field"><span>Phone</span><input v-model="contactForm.phone" placeholder="Phone number"></label><label class="field"><span>Email</span><input v-model="contactForm.email" type="email" placeholder="name@company.com"></label><label class="field span-2"><span>Address</span><textarea v-model="contactForm.address" rows="2" placeholder="Street, city"></textarea></label></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Saving...' : documentCreateContext === 'contact' ? (relatedContactName === 'supplier' ? 'Save supplier' : 'Save customer') : 'Save contact' }}</button></div></form>
      <form v-else-if="modal === 'adjustment'" @submit.prevent="saveAdjustment"><div class="form-grid"><label class="field span-2"><span>Product *</span><select v-model="adjustmentForm.product_id" required><option value="" disabled>Select a product</option><option v-for="product in products" :key="product.id" :value="product.id">{{ product.name }} ({{ qty(product.quantity_on_hand) }} {{ product.unit }} available)</option></select></label><label class="field span-2"><span>Quantity change *</span><input v-model="adjustmentForm.quantity_change" type="number" step="0.001" required placeholder="Use + to add or − to remove"><small>Positive adds stock; negative removes stock.</small></label><label class="field span-2"><span>Reason *</span><textarea v-model="adjustmentForm.notes" rows="3" required placeholder="Explain why stock is being adjusted"></textarea></label></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Saving...' : 'Save adjustment' }}</button></div></form>
      <div v-else-if="modal === 'client_details' && selectedClient" class="company-details"><div class="company-detail-hero"><div><span class="stock-pill" :class="selectedClient.subscription_active ? 'good' : 'low'">{{ clientValidityLabel(selectedClient) }}</span><p>{{ selectedClient.plan_name || 'No plan assigned' }} · valid until {{ date(selectedClient.subscription_ends_at) }}</p></div><div class="renew-control"><strong>Extend subscription</strong><div><button v-for="months in [1, 3, 6, 12]" :key="months" class="btn-secondary-app small" :disabled="busy" @click="renewClient(selectedClient, months)"><CalendarPlus :size="15" /> {{ months }}{{ months === 1 ? ' month' : ' months' }}</button></div></div></div><div class="company-detail-stats"><div><span>Users</span><strong>{{ selectedClient.users_count }}</strong></div><div><span>Products</span><strong>{{ selectedClient.products_count }}</strong></div><div><span>Contacts</span><strong>{{ selectedClient.contacts_count }}</strong></div><div><span>Transactions</span><strong>{{ selectedClient.documents_count }}</strong></div></div><div class="company-detail-grid"><section class="detail-section"><div class="detail-section-head"><div><h3>Owner and team</h3><p>Accounts currently assigned to this company.</p></div></div><div v-if="selectedClient.users?.length" class="team-list"><div v-for="user in selectedClient.users" :key="user.id" class="team-row"><span class="avatar small">{{ initial(user.name) }}</span><div><strong>{{ user.name }}</strong><span>{{ user.email }} · {{ roleLabel(user.role) }}</span></div><span class="stock-pill" :class="user.active ? 'good' : 'low'">{{ user.active ? 'Active' : 'Inactive' }}</span></div></div><div v-else class="detail-empty">No users assigned.</div></section><section class="detail-section"><div class="detail-section-head"><div><h3>Support history</h3><p>Latest superadmin support sessions.</p></div></div><div v-if="selectedClient.support_impersonations?.length" class="support-history"><div v-for="entry in selectedClient.support_impersonations" :key="entry.id" class="support-history-row"><Clock3 :size="16" /><div><strong>{{ entry.impersonator?.name || 'Superadmin' }}</strong><span>Started {{ date(entry.started_at) }}<template v-if="entry.ended_at"> · ended {{ date(entry.ended_at) }}</template><template v-else> · active session</template></span></div></div></div><div v-else class="detail-empty">No support sessions recorded.</div></section></div><section v-if="selectedClient.notes" class="company-notes"><strong>Support notes</strong><p>{{ selectedClient.notes }}</p></section><div class="modal-actions"><button class="btn-secondary-app" @click="openClient(selectedClient)"><Pencil :size="16" /> Edit company</button><button v-if="selectedClient.admins?.some(owner => owner.active)" class="btn-secondary-app" :disabled="busy" @click="startSupportSession(selectedClient)"><Eye :size="16" /> View as owner</button><button v-else class="btn-secondary-app" @click="openClient(selectedClient)"><UserCog :size="16" /> Set up owner</button><button class="btn-primary-app" @click="closeModal()">Close</button></div></div>
      <form v-else-if="modal === 'client'" @submit.prevent="saveClient"><div class="form-grid"><label class="field"><span>Company name *</span><input v-model="clientForm.name" required placeholder="Business name"></label><label class="field"><span>Subscription plan</span><input v-model="clientForm.plan_name" placeholder="e.g. Standard"></label><label class="field"><span>Subscription status *</span><select v-model="clientForm.subscription_status" required><option value="trial">Trial</option><option value="active">Active</option><option value="past_due">Past due</option><option value="suspended">Suspended</option><option value="cancelled">Cancelled</option></select></label><label class="field"><span>Valid until</span><input v-model="clientForm.subscription_ends_at" type="date"></label><div class="form-section-title span-2">Owner administrator</div><label class="field"><span>Owner name *</span><input v-model="clientForm.owner_name" required placeholder="Full name"></label><label class="field"><span>Owner email *</span><input v-model="clientForm.owner_email" type="email" required placeholder="owner@company.com"></label><label class="field" :class="{ 'span-2': !editingId }"><span>{{ clientForm.owner_exists ? 'New owner password (optional)' : 'Temporary password *' }}</span><input v-model="clientForm.owner_password" type="password" :required="!clientForm.owner_exists" minlength="8" autocomplete="new-password" placeholder="At least 8 characters"></label><label v-if="editingId" class="field checkbox-field"><input v-model="clientForm.owner_active" type="checkbox"><span>Owner account active</span></label><label class="field span-2"><span>Support notes</span><textarea v-model="clientForm.notes" rows="3" placeholder="Subscription or company support notes"></textarea></label></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Saving...' : editingId ? 'Save company and owner' : 'Create company and owner' }}</button></div></form>
      <form v-else-if="modal === 'user'" @submit.prevent="saveUser"><div class="form-grid"><label class="field"><span>Full name *</span><input v-model="userForm.name" required autocomplete="name" placeholder="User name"></label><label class="field"><span>Email address *</span><input v-model="userForm.email" type="email" required autocomplete="email" placeholder="name@company.com"></label><label class="field"><span>{{ editingId ? 'New password (optional)' : 'Password *' }}</span><input v-model="userForm.password" type="password" :required="!editingId" minlength="8" autocomplete="new-password" placeholder="At least 8 characters"></label><label class="field"><span>Role *</span><select v-model="userForm.role" required><option v-if="session.user.role === 'superadmin'" value="superadmin">Superadmin</option><option v-if="session.user.role === 'superadmin'" value="admin">Owner admin</option><option value="manager">Manager</option><option value="staff">Staff</option></select></label><label v-if="session.user.role === 'superadmin' && userForm.role !== 'superadmin'" class="field"><span>Company *</span><select v-model="userForm.organization_id" required><option value="" disabled>Select a company</option><option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}</option></select></label><label class="field checkbox-field"><input v-model="userForm.active" type="checkbox"><span>Active user</span></label><template v-if="['manager', 'staff'].includes(userForm.role)"><div class="form-section-title span-2">Workspace permissions</div><div class="permission-grid span-2"><label v-for="permission in permissionOptions" :key="permission.value" class="permission-option"><input v-model="userForm.permissions" type="checkbox" :value="permission.value"><span>{{ permission.label }}</span></label></div></template></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy">{{ busy ? 'Saving...' : 'Save user and permissions' }}</button></div></form>
      <form v-else @submit.prevent="saveDocument"><div class="form-grid"><label v-if="documentForm.type === 'purchase_return'" class="field span-2"><span>Original purchase *</span><select v-model="documentForm.purchase_id" required @change="choosePurchase"><option value="" disabled>Select a purchase</option><option v-for="purchase in purchaseOptions" :key="purchase.id" :value="purchase.id">{{ purchase.number }} · {{ purchase.contact?.name }} · {{ date(purchase.document_date) }}</option></select></label><label v-if="documentForm.type === 'sale_return'" class="field span-2"><span>Original sale *</span><select v-model="documentForm.sale_id" required @change="chooseSale"><option value="" disabled>Select a sale with returnable items</option><option v-for="sale in saleOptions" :key="sale.id" :value="sale.id">{{ sale.number }} · {{ sale.contact?.name }} · {{ date(sale.document_date) }}</option></select></label><div class="field"><span>{{ relatedContactName === 'supplier' ? 'Supplier' : 'Customer' }} *</span><div class="field-action"><select v-model="documentForm.contact_id" required :disabled="['purchase_return', 'sale_return'].includes(documentForm.type)" :aria-label="relatedContactName"><option value="" disabled>Select a {{ relatedContactName }}</option><option v-for="contact in availableContacts" :key="contact.id" :value="contact.id">{{ contact.name }}</option></select><button v-if="!['purchase_return', 'sale_return'].includes(documentForm.type)" type="button" class="btn-secondary-app small create-inline" @click="createRelatedContact"><Plus :size="16" /> Create new {{ relatedContactName }}</button></div></div><label class="field"><span>Date *</span><input v-model="documentForm.document_date" type="date" required></label></div><div v-if="documentForm.type === 'purchase' && purchaseStep < 3" class="workflow-banner"><span class="workflow-step">{{ purchaseStep }}</span><div><strong>{{ purchaseStep === 1 ? 'Start with a supplier' : 'Next, choose a product' }}</strong><p>{{ purchaseStep === 1 ? 'Select a supplier or create a new one to continue.' : 'Select a product or create a new one. Your purchase draft will be kept.' }}</p></div></div><div class="items-heading"><div><h3>Line items</h3><p>{{ documentForm.type === 'sale_return' ? 'Choose quantities to refund. Prices come from the original sale.' : 'Choose products and enter quantities.' }}</p></div><button v-if="!['purchase_return', 'sale_return'].includes(documentForm.type)" type="button" class="btn-secondary-app small" :disabled="documentForm.type === 'purchase' && !documentForm.contact_id" @click="documentForm.items.push({ product_id: '', quantity: 1, unit_price: '0.00' })"><Plus :size="16" /> Add line</button></div><div class="items-list"><div v-for="(item, index) in documentForm.items" :key="index" class="item-row"><div class="field"><span>Product</span><div class="field-action"><select v-model="item.product_id" required :disabled="documentForm.type === 'purchase' && !documentForm.contact_id" aria-label="Product" @change="chooseProduct(item)"><option value="" disabled>Select product</option><option v-for="product in availableProducts" :key="product.id" :value="product.id">{{ productOption(product) }}</option></select><button v-if="!['purchase_return', 'sale_return'].includes(documentForm.type)" type="button" class="btn-secondary-app small create-inline" :disabled="documentForm.type === 'purchase' && !documentForm.contact_id" @click="createRelatedProduct(index)"><Plus :size="16" /> New product</button></div></div><label class="field"><span>Quantity</span><input v-model="item.quantity" type="number" min="0.001" :max="documentForm.type === 'sale_return' ? returnableQuantity(item.product_id) : undefined" step="0.001" required><small v-if="documentForm.type === 'sale_return'">Up to {{ qty(returnableQuantity(item.product_id)) }} returnable</small></label><label class="field"><span>Unit price</span><input v-model="item.unit_price" type="number" min="0" step="0.01" required :readonly="documentForm.type === 'sale_return'"></label><div class="line-amount"><span>Amount</span><strong>{{ money(Number(item.quantity) * Number(item.unit_price)) }}</strong></div><button type="button" class="icon-button remove-line" title="Remove line" :disabled="documentForm.items.length === 1" @click="documentForm.items.splice(index, 1)"><Trash2 :size="17" /></button></div></div><div class="document-bottom"><label class="field notes-field"><span>Notes</span><textarea v-model="documentForm.notes" rows="3" placeholder="Optional transaction notes"></textarea></label><div class="totals-box"><div><span>Subtotal</span><strong>{{ money(docSubtotal) }}</strong></div><label><span>Discount</span><input v-model="documentForm.discount" type="number" min="0" step="0.01"></label><label><span>Tax</span><input v-model="documentForm.tax" type="number" min="0" step="0.01"></label><div class="grand-total"><span>Total</span><strong>{{ money(docTotal) }}</strong></div></div></div><div class="modal-actions"><button type="button" class="btn-secondary-app" @click="closeModal()">Cancel</button><button class="btn-primary-app" :disabled="busy || docTotal < 0 || !documentCanSave">{{ busy ? 'Saving...' : `Save ${label(documentForm.type).toLowerCase()}` }}</button></div></form>
    </div></div>
  </div>
</template>
