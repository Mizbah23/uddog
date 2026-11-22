import test from 'node:test';
import assert from 'node:assert/strict';
import { createSaleFormLoader } from '../../resources/js/saleFormData.js';

function deferred() {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return { promise, resolve };
}

test('sale data starts only products and contacts in parallel and deduplicates repeated opens', async () => {
  const calls = [];
  const products = deferred();
  const contacts = deferred();
  const loader = createSaleFormLoader(path => {
    calls.push(path);
    return path === 'contacts' ? contacts.promise : products.promise;
  });
  const first = loader.load(1);
  const second = loader.load(1);
  assert.equal(first, second);
  assert.deepEqual(calls, ['products?branch_id=1', 'contacts']);
  products.resolve([{ id: 10, available_quantity: 7 }]);
  contacts.resolve([{ id: 20, is_default: true }]);
  assert.deepEqual(await first, { products: [{ id: 10, available_quantity: 7 }], contacts: [{ id: 20, is_default: true }], goldRates: [] });
  await loader.load(1);
  assert.equal(calls.length, 2);
});

test('branch changes and refresh invalidate stock data; gold stores load their branch rates', async () => {
  const calls = [];
  const loader = createSaleFormLoader(async path => { calls.push(path); return [path]; });
  await loader.load(1);
  await loader.load(2, { goldStore: true });
  assert.deepEqual(calls.slice(2), ['products?branch_id=2', 'contacts', 'gold/rates?branch_id=2']);
  loader.invalidate();
  await loader.load(2, { goldStore: true });
  assert.equal(calls.length, 8);
});

test('a failed request can be retried without caching the error', async () => {
  let fail = true;
  let count = 0;
  const loader = createSaleFormLoader(async path => {
    count++;
    if (path === 'contacts' && fail) throw new Error('Network unavailable');
    return [];
  });
  await assert.rejects(loader.load(1), /Network unavailable/);
  fail = false;
  await loader.load(1);
  assert.equal(count, 4);
});

test('an older failed branch request cannot clear a newer successful request', async () => {
  const old = deferred();
  let count = 0;
  const loader = createSaleFormLoader(path => {
    count++;
    if (path === 'products?branch_id=1') return old.promise.then(() => { throw new Error('Old branch failed'); });
    return Promise.resolve([]);
  });
  const first = loader.load(1);
  await loader.load(2);
  old.resolve();
  await assert.rejects(first, /Old branch failed/);
  await loader.load(2);
  assert.equal(count, 4);
});
