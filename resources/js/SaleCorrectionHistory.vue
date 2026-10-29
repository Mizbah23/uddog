<script setup>
import { translate } from './localization';
const props = defineProps({ sale: Object, language: String, money: Function, qty: Function, dateTime: Function });
const t = text => translate(text, props.language);
</script>

<template>
  <section v-if="sale.corrections?.length" class="correction-history">
    <h3>{{ t('Correction history') }}</h3>
    <article v-for="correction in sale.corrections" :key="correction.id">
      <strong>{{ dateTime(correction.created_at) }} · {{ correction.creator?.name || '—' }}</strong>
      <p>{{ correction.reason }}</p>
      <div v-for="after in correction.after.items" :key="after.id" class="correction-history-line">
        <span>{{ sale.items.find(item => item.id === after.id)?.product?.name || after.product_id }}</span>
        <template v-for="before in correction.before.items.filter(item => item.id === after.id)" :key="before.id">
          <span>{{ qty(before.entered_quantity ?? before.quantity) }} → {{ qty(after.entered_quantity ?? after.quantity) }} {{ after.entered_unit }}</span>
          <span>{{ money(before.entered_unit_price ?? before.unit_price) }} → {{ money(after.entered_unit_price ?? after.unit_price) }}</span>
        </template>
      </div>
      <small>{{ t('Discount') }}: {{ money(correction.before.discount) }} → {{ money(correction.after.discount) }} · {{ t('Tax') }}: {{ money(correction.before.tax) }} → {{ money(correction.after.tax) }}</small>
      <div><b>{{ t('Total') }}: {{ money(correction.before.total) }} → {{ money(correction.after.total) }}</b></div>
    </article>
  </section>
</template>

<style scoped>
.correction-history{margin:20px 0;border-top:1px solid #e6e6ef;padding-top:18px}.correction-history h3{font-size:15px;margin-bottom:12px}.correction-history article{padding:14px;background:#f8f8fc;border:1px solid #e6e6ef;border-radius:8px;margin-bottom:10px;font-size:12px;line-height:1.7}.correction-history p{white-space:pre-wrap;overflow-wrap:anywhere;margin:6px 0}.correction-history-line{display:flex;flex-wrap:wrap;gap:8px 18px}.correction-history small{color:#73788a}
@media print{.correction-history{display:none}}
</style>
