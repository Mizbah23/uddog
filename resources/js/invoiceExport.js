import { saveCanvasPagesPdf } from './profitLossExport.js';

const width = 794;
const height = 1123;
const margin = 40;
const bottom = height - 62;
const font = '"Noto Sans Bengali", "Hind Siliguri", "Kohinoor Bangla", "Nirmala UI", Arial, sans-serif';

function wrap(context, value, available) {
  const lines = [];
  const graphemes = typeof Intl.Segmenter === 'function' ? new Intl.Segmenter(undefined, { granularity: 'grapheme' }) : null;
  for (const paragraph of String(value ?? '').split('\n')) {
    let line = '';
    for (const word of paragraph.split(/\s+/)) {
      if (context.measureText(`${line} ${word}`.trim()).width <= available) { line = `${line} ${word}`.trim(); continue; }
      if (line) { lines.push(line); line = ''; }
      const characters = graphemes ? [...graphemes.segment(word)].map(part => part.segment) : Array.from(word);
      for (const character of characters) {
        if (line && context.measureText(line + character).width > available) { lines.push(line); line = ''; }
        line += character;
      }
    }
    lines.push(line);
  }
  return lines;
}

export function renderInvoicePages(invoice) {
  const pages = [];
  let context;
  let y;
  const text = (value, x, top, size = 14, weight = 400, color = '#3b4253', align = 'left') => {
    context.font = `${weight} ${size}px ${font}`;
    context.fillStyle = color;
    context.textAlign = align;
    context.fillText(String(value ?? ''), x, top);
  };
  function newPage() {
    const canvas = document.createElement('canvas');
    canvas.width = width * 1.5;
    canvas.height = Math.round(height * 1.5);
    context = canvas.getContext('2d');
    if (!context) throw new Error('Could not render the invoice.');
    context.scale(1.5, 1.5);
    context.fillStyle = '#fff';
    context.fillRect(0, 0, width, height);
    context.textBaseline = 'top';
    pages.push(canvas);
    y = margin;
    context.font = `700 22px ${font}`;
    for (const line of wrap(context, invoice.company, width - margin * 2)) { text(line, margin, y, 22, 700); y += 32; }
    text(invoice.title, margin, y, 14, 600, '#7367f0'); y += 26;
    text(invoice.number, margin, y, 17, 700); y += 29;
    context.fillStyle = '#7367f0'; context.fillRect(margin, y, width - margin * 2, 2); y += 20;
  }
  function ensure(space, tableHeader) {
    if (y + space <= bottom) return;
    newPage();
    if (tableHeader) tableHeader();
  }
  function paragraph(value, size = 14, weight = 400) {
    context.font = `${weight} ${size}px ${font}`;
    const lines = wrap(context, value, width - margin * 2);
    for (const line of lines) { ensure(24); text(line, margin, y, size, weight); y += 24; }
  }
  function table(columns, rows, sizes) {
    function header() {
      context.font = `600 12px ${font}`;
      const lines = columns.map((label, i) => wrap(context, label, sizes[i] - 16));
      const rowHeight = Math.max(42, ...lines.map(cell => cell.length * 20 + 16));
      context.fillStyle = '#f3f1ff'; context.fillRect(margin, y, width - margin * 2, rowHeight);
      let x = margin;
      lines.forEach((cell, i) => { cell.forEach((line, n) => text(line, i > 1 ? x + sizes[i] - 8 : x + 8, y + 10 + n * 20, 12, 600, '#59516e', i > 1 ? 'right' : 'left')); x += sizes[i]; });
      y += rowHeight;
    }
    ensure(100);
    header();
    for (const row of rows) {
      context.font = `400 14px ${font}`;
      let cells = row.map((value, i) => wrap(context, value, sizes[i] - 16));
      while (cells.some(cell => cell.length)) {
        ensure(44, header);
        const capacity = Math.max(1, Math.floor((bottom - y - 18) / 23));
        const count = Math.min(capacity, Math.max(...cells.map(cell => cell.length)));
        const rowHeight = Math.max(44, count * 23 + 18);
        let x = margin;
        cells.forEach((cell, i) => {
          cell.slice(0, count).forEach((line, n) => text(line, i > 1 ? x + sizes[i] - 8 : x + 8, y + 10 + n * 23, 14, i === 1 ? 600 : 400, '#3b4253', i > 1 ? 'right' : 'left'));
          x += sizes[i];
        });
        context.fillStyle = '#eeedf4'; context.fillRect(margin, y + rowHeight - 1, width - margin * 2, 1);
        y += rowHeight;
        cells = cells.map(cell => cell.slice(count));
      }
    }
    y += 22;
  }
  newPage();
  for (const [label, value] of invoice.meta) { paragraph(`${label}: ${value}`); }
  y += 16;
  table(invoice.columns, invoice.items, [54, 286, 100, 130, 144]);
  for (const [label, value, emphasis] of invoice.totals) {
    context.font = `${emphasis ? 700 : 400} 15px ${font}`;
    const left = wrap(context, label, 225);
    const right = wrap(context, value, 160);
    const rowHeight = Math.max(left.length, right.length) * 24 + 12;
    ensure(rowHeight);
    if (emphasis) { context.fillStyle = '#f3f1ff'; context.fillRect(340, y, 414, rowHeight); }
    left.forEach((line, n) => text(line, 350, y + 6 + n * 24, 15, emphasis ? 700 : 400));
    right.forEach((line, n) => text(line, width - margin - 10, y + 6 + n * 24, 15, 700, emphasis ? '#7367f0' : '#3b4253', 'right'));
    y += rowHeight;
  }
  if (invoice.notes) { y += 18; paragraph(invoice.notesLabel, 14, 700); paragraph(invoice.notes); }
  if (invoice.installments?.length) { y += 18; ensure(100); paragraph(invoice.installmentTitle, 16, 700); table(invoice.installmentColumns, invoice.installments, [40, 220, 150, 150, 154]); }
  pages.forEach((canvas, index) => {
    context = canvas.getContext('2d');
    text(`${invoice.pageLabel} ${index + 1} / ${pages.length}`, width - margin, height - 35, 11, 400, '#858999', 'right');
  });
  return pages;
}

export async function saveInvoicePdf(invoice, filename) {
  await document.fonts.ready;
  await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  const pages = renderInvoicePages(invoice);
  try { saveCanvasPagesPdf(pages, filename); }
  finally { pages.forEach(canvas => { canvas.width = 0; canvas.height = 0; }); }
}
