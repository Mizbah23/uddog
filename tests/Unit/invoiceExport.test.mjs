import test from 'node:test';
import assert from 'node:assert/strict';
import { renderInvoicePages } from '../../resources/js/invoiceExport.js';

function canvasDocument() {
  return { createElement() {
    const drawn = [];
    const context = {
      scale() {}, fillRect() {},
      measureText(value) { return { width: Array.from(String(value)).length * 8 }; },
      fillText(value, x, y) { drawn.push({ value: String(value), x, y }); },
    };
    return { drawn, getContext() { return context; } };
  } };
}

function sample(overrides = {}) {
  return {
    company: 'Sample store', title: 'Purchase invoice', number: 'PUR-123', meta: [['Supplier', 'Sample supplier']],
    columns: ['#', 'Product', 'Quantity', 'Unit price', 'Amount'],
    items: [['1', 'Sample product', '4 pc', '10.00', '40.00']],
    totals: [['Total', '40.00', true], ['Paid', '10.00'], ['Balance due', '30.00']], pageLabel: 'Page',
    notesLabel: 'Notes', ...overrides,
  };
}

test('multi-page invoices keep every item, repeat invoice headers, and keep text within page boundaries', () => {
  globalThis.document = canvasDocument();
  const items = Array.from({ length: 100 }, (_, index) => [index + 1, `Item-${String(index).padStart(3, '0')}`, '4 pc', '10.00', '40.00']);
  const pages = renderInvoicePages(sample({ items }));
  assert.ok(pages.length > 1);
  for (const page of pages) {
    assert.ok(page.drawn.some(text => text.value === 'PUR-123'));
    for (const text of page.drawn) {
      assert.ok(text.x >= 40 && text.x <= 754);
      assert.ok(text.y >= 40 && text.y <= 1088);
    }
  }
  const text = pages.flatMap(page => page.drawn.map(line => line.value));
  for (const item of items) assert.equal(text.filter(value => value === item[1]).length, 1);
  assert.ok(text.includes('Balance due'));
  assert.ok(text.includes('30.00'));
  assert.ok(text.includes(`Page ${pages.length} / ${pages.length}`));
});

test('long descriptions and notes continue across pages without losing text or invoice totals', () => {
  globalThis.document = canvasDocument();
  const pages = renderInvoicePages(sample({ items: [['1', 'description '.repeat(600), '4 pc', '10.00', '40.00']], notes: 'note '.repeat(600) }));
  assert.ok(pages.length > 2);
  const text = pages.flatMap(page => page.drawn.map(line => line.value));
  assert.equal(text.join(' ').match(/description/g).length, 600);
  assert.equal(text.join(' ').match(/note/g).length, 600);
  assert.ok(text.includes('Total'));
  assert.ok(text.includes('40.00'));
  for (const page of pages) for (const line of page.drawn) assert.ok(line.y <= 1088);
});
