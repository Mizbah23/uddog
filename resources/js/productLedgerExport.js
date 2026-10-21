import { saveCanvasImage, saveCanvasPdf } from './profitLossExport';

const widths = [70, 370, 135, 180, 165, 180, 190, 214];

function fitText(context, value, width) {
  let label = String(value ?? '');
  while (label.length > 1 && context.measureText(label).width > width) label = `${label.slice(0, -2)}…`;
  return label;
}

function drawCell(context, value, x, y, width, align = 'left') {
  context.textAlign = align;
  const anchor = align === 'right' ? x + width - 12 : align === 'center' ? x + width / 2 : x + 12;
  context.fillText(fitText(context, value, width - 24), anchor, y);
}

export function renderProductLedgerCanvas(report) {
  const scale = 1.5;
  const width = 1600;
  const left = 48;
  const tableWidth = 1504;
  const tableTop = 162;
  const headerHeight = 60;
  const rowHeight = 44;
  const totalTop = tableTop + headerHeight + report.rows.length * rowHeight;
  const signatureTop = totalTop + 50 + 116;
  const height = signatureTop + 130;
  const canvas = document.createElement('canvas');
  canvas.width = width * scale;
  canvas.height = height * scale;
  const context = canvas.getContext('2d');
  context.scale(scale, scale);
  context.fillStyle = '#fff';
  context.fillRect(0, 0, width, height);

  const gradient = context.createLinearGradient(left, 0, left + tableWidth, 0);
  gradient.addColorStop(0, '#5548c7');
  gradient.addColorStop(.55, '#7367f0');
  gradient.addColorStop(1, '#9487f5');
  context.fillStyle = gradient;
  context.fillRect(left, 30, tableWidth, 132);
  context.fillStyle = '#fff';
  context.textAlign = 'center';
  context.font = '700 12px Arial, "Noto Sans Bengali", sans-serif';
  context.fillText(report.eyebrow, width / 2, 65);
  context.font = '700 26px Arial, "Noto Sans Bengali", sans-serif';
  context.fillText(fitText(context, report.company, tableWidth - 60), width / 2, 105);
  context.font = '500 15px Arial, "Noto Sans Bengali", sans-serif';
  context.fillText(fitText(context, report.title, tableWidth - 60), width / 2, 137);

  const header = context.createLinearGradient(left, 0, left + tableWidth, 0);
  header.addColorStop(0, '#edeaff');
  header.addColorStop(1, '#f7f5ff');
  context.fillStyle = header;
  context.fillRect(left, tableTop, tableWidth, headerHeight);
  context.fillStyle = '#5a4fc2';
  context.font = '700 15px Arial, "Noto Sans Bengali", sans-serif';
  let x = left;
  report.columns.forEach((column, index) => {
    drawCell(context, column, x, tableTop + 38, widths[index], index === 0 ? 'center' : index === 1 ? 'left' : 'right');
    x += widths[index];
  });

  context.font = '500 15px Arial, "Noto Sans Bengali", sans-serif';
  report.rows.forEach((row, index) => {
    const top = tableTop + headerHeight + index * rowHeight;
    if (index % 2 === 1) {
      context.fillStyle = '#fcfbff';
      context.fillRect(left, top, tableWidth, rowHeight);
    }
    const values = [report.qty(row.serial), row.product, report.qty(row.quantity), report.money(row.total_sales), report.money(row.costing), report.money(row.stock_profit), report.money(row.installment_profit), report.money(row.period_profit)];
    x = left;
    values.forEach((value, column) => {
      context.fillStyle = column === 7 ? row.period_profit < 0 ? '#bd4b61' : '#5147ac' : '#414154';
      drawCell(context, value, x, top + 29, widths[column], column === 0 ? 'center' : column === 1 ? 'left' : 'right');
      x += widths[column];
    });
    context.fillStyle = '#f0eef7';
    context.fillRect(left, top + rowHeight - 1, tableWidth, 1);
  });

  context.fillStyle = '#f2f0ff';
  context.fillRect(left, totalTop, tableWidth, 50);
  context.fillStyle = '#554bb0';
  context.font = '700 15px Arial, "Noto Sans Bengali", sans-serif';
  drawCell(context, report.totalLabel, left, totalTop + 32, widths[0] + widths[1], 'right');
  const totals = ['', '', report.qty(report.summary.quantity), report.money(report.summary.total_sales), report.money(report.summary.costing), report.money(report.summary.stock_profit), report.money(report.summary.installment_profit), report.money(report.summary.period_profit)];
  x = left;
  totals.forEach((value, index) => {
    drawCell(context, value, x, totalTop + 32, widths[index], index === 0 ? 'center' : index === 1 ? 'left' : 'right');
    x += widths[index];
  });

  context.fillStyle = '#77778e';
  context.font = '500 12px Arial, "Noto Sans Bengali", sans-serif';
  drawCell(context, report.note, left, totalTop + 76, tableWidth);
  const gap = 26;
  const signatureWidth = (tableWidth - gap * 2) / 3;
  context.strokeStyle = '#a4a4b4';
  context.lineWidth = 1;
  context.font = '700 15px Arial, "Noto Sans Bengali", sans-serif';
  context.fillStyle = '#4a4b61';
  [report.preparedLabel, report.verifiedLabel, report.approvedLabel].forEach((label, index) => {
    const start = left + index * (signatureWidth + gap);
    context.beginPath();
    context.moveTo(start, signatureTop);
    context.lineTo(start + signatureWidth, signatureTop);
    context.stroke();
    drawCell(context, label, start, signatureTop + 30, signatureWidth, 'center');
  });
  context.fillStyle = '#85859a';
  context.font = '500 12px Arial, "Noto Sans Bengali", sans-serif';
  drawCell(context, report.generatedLabel, left, height - 26, tableWidth);
  return canvas;
}

export async function saveProductLedgerImage(report, filename) {
  await saveCanvasImage(renderProductLedgerCanvas(report), filename);
}

export function saveProductLedgerPdf(report, filename) {
  saveCanvasPdf(renderProductLedgerCanvas(report), filename);
}
