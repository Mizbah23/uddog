<script setup>
defineProps({
  document: { type: Object, required: true },
  organizationName: { type: String, required: true },
  cashierName: { type: String, required: true },
  installments: { type: Array, required: true },
  money: { type: Function, required: true },
  qty: { type: Function, required: true },
  date: { type: Function, required: true },
  weekday: { type: Function, required: true },
  documentDateTime: { type: Function, required: true },
  paymentLabel: { type: Function, required: true },
  paymentTypeLabel: { type: Function, required: true },
});
</script>

<template>
  <section class="pos-thermal-receipt" aria-label="POS receipt">
    <header class="receipt-header">
      <strong class="receipt-store-name">{{ organizationName }}</strong>
      <span v-if="document.branch?.name">{{ document.branch.name }}</span>
      <span v-if="document.branch?.address">{{ document.branch.address }}</span>
      <span v-if="document.branch?.phone">{{ document.branch.phone }}</span>
      <b>POS SALE RECEIPT</b>
    </header>

    <div class="receipt-section receipt-meta">
      <div><span>Invoice</span><strong>{{ document.number }}</strong></div>
      <div><span>Date</span><strong>{{ documentDateTime(document) }}</strong></div>
      <div><span>Customer</span><strong>{{ document.contact?.name || 'Walk-in customer' }}</strong></div>
      <div v-if="document.contact?.phone"><span>Phone</span><strong>{{ document.contact.phone }}</strong></div>
      <div><span>Cashier</span><strong>{{ cashierName }}</strong></div>
    </div>

    <div class="receipt-section receipt-items">
      <div class="receipt-columns"><strong>Item</strong><strong>Line total</strong></div>
      <article v-for="item in document.items" :key="item.id" class="receipt-item">
        <strong>{{ item.product?.name || 'Product' }}</strong>
        <div><span>{{ qty(item.entered_quantity ?? item.quantity) }} {{ item.entered_unit || item.product?.unit }} × {{ money(item.entered_unit_price ?? item.unit_price) }}</span><strong>{{ money(item.line_total) }}</strong></div>
      </article>
    </div>

    <div class="receipt-section receipt-totals">
      <div><span>Subtotal</span><strong>{{ money(document.subtotal) }}</strong></div>
      <div v-if="Number(document.discount) > 0"><span>Discount</span><strong>-{{ money(document.discount) }}</strong></div>
      <div v-if="Number(document.tax) > 0"><span>Tax</span><strong>{{ money(document.tax) }}</strong></div>
      <div v-if="Number(document.interest_amount) > 0"><span>Interest charge</span><strong>{{ money(document.interest_amount) }}</strong></div>
      <div class="receipt-grand-total"><span>Total</span><strong>{{ money(document.total) }}</strong></div>
      <div><span>Sale payment type</span><strong>{{ paymentTypeLabel(document.payment_type) }}</strong></div>
      <div v-if="Number(document.gold_exchange_value) > 0"><span>Old gold exchange</span><strong>{{ money(document.gold_exchange_value) }}</strong></div>
      <div><span>Paid</span><strong>{{ money(document.amount_paid) }}</strong></div>
      <div class="receipt-balance"><span>Balance due</span><strong>{{ money(document.balance_due) }}</strong></div>
      <div><span>Payment method</span><strong>{{ paymentLabel(document.payment_method) }}</strong></div>
    </div>

    <div v-if="document.payment_type === 'installment' && installments.length" class="receipt-section receipt-installments">
      <strong class="receipt-section-title">Installment payment schedule</strong>
      <div v-if="document.installment_start_date" class="receipt-small-row"><span>Start date</span><strong>{{ date(document.installment_start_date) }}</strong></div>
      <div v-for="installment in installments" :key="installment.id" class="receipt-installment">
        <div><strong>#{{ installment.sequence }} · {{ date(installment.due_date) }}</strong><strong>{{ money(installment.amount) }}</strong></div>
        <small>{{ weekday(installment.due_date) }} · <span>Remaining</span> {{ money(installment.balance_due) }}</small>
      </div>
    </div>

    <div v-if="document.notes" class="receipt-section receipt-notes"><strong>Notes</strong><p>{{ document.notes }}</p></div>
    <footer class="receipt-footer">Thank you for your business.</footer>
  </section>
</template>
