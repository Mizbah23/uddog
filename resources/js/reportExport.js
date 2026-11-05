import { saveCanvasImage, saveCanvasPagesPdf } from './profitLossExport';

function wrap(context, text, width) {
  const lines = [''];
  for (const character of String(text ?? '')) {
    const last = lines.length - 1;
    if (character === '\n' || context.measureText(lines[last] + character).width > width) lines.push(character === '\n' ? '' : character);
    else lines[last] += character;
  }
  return lines;
}

export async function saveStandardReport({ root, company, title, subtitle, summary, generated, filename, format }) {
  await document.fonts.ready;
  const tables = [...root.querySelectorAll('table')];
  const columnCount = Math.max(1, ...tables.map(table => Math.max(1, ...[...table.rows].map(row => row.cells.length))));
  const width = Math.max(1500, columnCount * 180 + 80);
  const height = 1200;
  const pages = [];
  let context;
  let y;
  function page() {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    context = canvas.getContext('2d');
    context.fillStyle = '#fff';
    context.fillRect(0, 0, width, height);
    context.fillStyle = '#6556d9';
    context.fillRect(40, 30, width - 80, 116);
    context.fillStyle = '#fff';
    context.textAlign = 'center';
    context.font = '700 25px Arial, sans-serif';
    context.fillText(company, width / 2, 69, width - 120);
    context.font = '600 20px Arial, sans-serif';
    context.fillText(title, width / 2, 103, width - 120);
    context.font = '15px Arial, sans-serif';
    context.fillText(subtitle, width / 2, 131, width - 120);
    context.fillStyle = '#77778e';
    context.textAlign = 'left';
    context.fillText(`${generated} · ${pages.length + 1}`, 40, height - 25, width - 80);
    pages.push(canvas);
    y = 170;
  }
  function row(values, header = false) {
    const cellWidth = (width - 80) / values.length;
    context.font = `${header ? '700' : '400'} 16px Arial, sans-serif`;
    const lines = values.map(value => wrap(context, value, cellWidth - 24));
    const rowHeight = Math.max(40, Math.max(...lines.map(value => value.length)) * 23 + 18);
    if (y + rowHeight > height - 65) page();
    context.font = `${header ? '700' : '400'} 16px Arial, sans-serif`;
    context.fillStyle = header ? '#edeaff' : '#faf9fe';
    context.fillRect(40, y, width - 80, rowHeight);
    context.fillStyle = '#414154';
    lines.forEach((cell, index) => cell.forEach((line, lineIndex) => context.fillText(line, 52 + index * cellWidth, y + 26 + lineIndex * 23)));
    context.strokeStyle = '#e7e4f2';
    context.strokeRect(40, y, width - 80, rowHeight);
    y += rowHeight;
  }
  page();
  for (const line of summary) row([line]);
  y += 15;
  for (const table of tables) {
    for (const element of table.rows) {
      if (element.closest('table') !== table) continue;
      const values = [...element.cells].map(cell => cell.textContent.trim().replace(/\s+/g, ' '));
      if (values.length) row(values, element.parentElement.tagName === 'THEAD');
    }
    y += 20;
  }
  if (format === 'pdf') saveCanvasPagesPdf(pages, filename);
  else for (let index = 0; index < pages.length; index++) await saveCanvasImage(pages[index], pages.length > 1 ? `${filename}-${index + 1}` : filename);
}
