function drawText(context, value, x, y, width, align = 'left') {
  let text = String(value ?? '');
  while (text.length > 1 && context.measureText(text).width > width) text = `${text.slice(0, -2)}…`;
  context.textAlign = align;
  context.fillText(text, x, y);
}

function download(blob, filename) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function canvasBlob(canvas, type, quality) {
  return new Promise((resolve, reject) => canvas.toBlob(blob => blob ? resolve(blob) : reject(new Error('Could not render the report.')), type, quality));
}

export function renderProfitLossCanvas(report) {
  const scale = 2;
  const width = 1200;
  const left = 48;
  const tableWidth = width - left * 2;
  const half = tableWidth / 2;
  const serialWidth = 56;
  const amountWidth = 158;
  const rowHeight = 44;
  const rowCount = Math.max(report.income.length, report.expenses.length, 1);
  const tableTop = 156;
  const groupHeight = 42;
  const columnHeight = 42;
  const dataTop = tableTop + groupHeight + columnHeight;
  const totalTop = dataTop + rowCount * rowHeight;
  const netTop = totalTop + 50;
  const signatureTop = netTop + 62 + 104;
  const height = signatureTop + 128;
  const canvas = document.createElement('canvas');
  canvas.width = width * scale;
  canvas.height = height * scale;
  const context = canvas.getContext('2d');
  context.scale(scale, scale);
  context.fillStyle = '#fff';
  context.fillRect(0, 0, width, height);
  const header = context.createLinearGradient(left, 0, left + tableWidth, 0);
  header.addColorStop(0, '#5548c7');
  header.addColorStop(.55, '#7367f0');
  header.addColorStop(1, '#9487f5');
  context.fillStyle = header;
  context.fillRect(left, 30, tableWidth, 126);
  context.fillStyle = '#fff';
  context.textAlign = 'center';
  context.font = '700 11px Arial, "Noto Sans Bengali", sans-serif';
  context.fillText(report.eyebrow, width / 2, 64);
  context.font = '700 22px Arial, "Noto Sans Bengali", sans-serif';
  drawText(context, report.company, width / 2, 101, tableWidth - 50, 'center');
  context.font = '500 13px Arial, "Noto Sans Bengali", sans-serif';
  drawText(context, report.title, width / 2, 130, tableWidth - 50, 'center');

  context.fillStyle = '#f0edff';
  context.fillRect(left, tableTop, tableWidth, groupHeight);
  context.fillStyle = '#594ec1';
  context.font = '700 15px Arial, "Noto Sans Bengali", sans-serif';
  drawText(context, report.incomeLabel, left + half / 2, tableTop + 28, half - 30, 'center');
  drawText(context, report.expensesLabel, left + half + half / 2, tableTop + 28, half - 30, 'center');

  context.fillStyle = '#faf9ff';
  context.fillRect(left, tableTop + groupHeight, tableWidth, columnHeight);
  context.fillStyle = '#6359bb';
  context.font = '700 12px Arial, "Noto Sans Bengali", sans-serif';
  const headerY = tableTop + groupHeight + 27;
  drawText(context, report.serialLabel, left + serialWidth / 2, headerY, serialWidth - 8, 'center');
  drawText(context, report.incomeHead, left + serialWidth + 12, headerY, half - serialWidth - amountWidth - 24);
  drawText(context, report.amountLabel, left + half - 12, headerY, amountWidth - 20, 'right');
  drawText(context, report.serialLabel, left + half + serialWidth / 2, headerY, serialWidth - 8, 'center');
  drawText(context, report.expenseHead, left + half + serialWidth + 12, headerY, half - serialWidth - amountWidth - 24);
  drawText(context, report.amountLabel, left + tableWidth - 12, headerY, amountWidth - 20, 'right');

  context.font = '500 13px Arial, "Noto Sans Bengali", sans-serif';
  for (let index = 0; index < rowCount; index++) {
    const y = dataTop + index * rowHeight;
    if (index % 2 === 1) {
      context.fillStyle = '#fcfbff';
      context.fillRect(left, y, tableWidth, rowHeight);
    }
    const income = report.income[index];
    const expense = report.expenses[index];
    context.fillStyle = '#3e4053';
    if (income) {
      drawText(context, index + 1, left + serialWidth / 2, y + 28, serialWidth - 8, 'center');
      drawText(context, income.account, left + serialWidth + 12, y + 28, half - serialWidth - amountWidth - 24);
      drawText(context, report.money(income.amount), left + half - 12, y + 28, amountWidth - 20, 'right');
    }
    if (expense) {
      drawText(context, index + 1, left + half + serialWidth / 2, y + 28, serialWidth - 8, 'center');
      drawText(context, expense.account, left + half + serialWidth + 12, y + 28, half - serialWidth - amountWidth - 24);
      drawText(context, report.money(expense.amount), left + tableWidth - 12, y + 28, amountWidth - 20, 'right');
    }
    context.fillStyle = '#eeecf5';
    context.fillRect(left, y + rowHeight - 1, tableWidth, 1);
  }
  context.fillStyle = '#f2f0ff';
  context.fillRect(left, totalTop, tableWidth, 50);
  context.fillStyle = '#554bb0';
  context.font = '700 13px Arial, "Noto Sans Bengali", sans-serif';
  drawText(context, report.totalIncomeLabel, left + half - amountWidth - 14, totalTop + 32, half - amountWidth - 24, 'right');
  drawText(context, report.money(report.netSales), left + half - 12, totalTop + 32, amountWidth - 20, 'right');
  drawText(context, report.totalExpensesLabel, left + tableWidth - amountWidth - 14, totalTop + 32, half - amountWidth - 24, 'right');
  drawText(context, report.money(report.totalExpenses), left + tableWidth - 12, totalTop + 32, amountWidth - 20, 'right');
  context.fillStyle = header;
  context.fillRect(left, netTop, tableWidth, 54);
  context.fillStyle = '#fff';
  drawText(context, report.netLabel, left + tableWidth - amountWidth - 14, netTop + 35, tableWidth - amountWidth - 30, 'right');
  drawText(context, report.money(report.netProfit), left + tableWidth - 12, netTop + 35, amountWidth - 20, 'right');

  context.fillStyle = '#77778e';
  context.font = '500 11px Arial, "Noto Sans Bengali", sans-serif';
  drawText(context, report.note, left + 12, netTop + 80, tableWidth - 24);
  const gap = 26;
  const signatureWidth = (tableWidth - gap * 2) / 3;
  context.strokeStyle = '#a4a4b4';
  context.lineWidth = 1;
  context.font = '700 13px Arial, "Noto Sans Bengali", sans-serif';
  context.fillStyle = '#4a4b61';
  [report.preparedLabel, report.verifiedLabel, report.approvedLabel].forEach((label, index) => {
    const x = left + index * (signatureWidth + gap);
    context.beginPath();
    context.moveTo(x, signatureTop);
    context.lineTo(x + signatureWidth, signatureTop);
    context.stroke();
    drawText(context, label, x + signatureWidth / 2, signatureTop + 28, signatureWidth - 12, 'center');
  });
  context.font = '500 11px Arial, "Noto Sans Bengali", sans-serif';
  context.fillStyle = '#85859a';
  drawText(context, report.generatedLabel, left, height - 26, tableWidth);
  return canvas;
}

export async function saveProfitLossImage(report, filename) {
  await saveCanvasImage(renderProfitLossCanvas(report), filename);
}

export async function saveCanvasImage(canvas, filename) {
  download(await canvasBlob(canvas, 'image/png'), `${filename}.png`);
}

function ascii(value) {
  return new TextEncoder().encode(value);
}

function jpegBytes(canvas) {
  const encoded = canvas.toDataURL('image/jpeg', .94).split(',')[1];
  const binary = atob(encoded);
  const bytes = new Uint8Array(binary.length);
  for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
  return bytes;
}

function pdfFromCanvas(canvas) {
  const pageWidth = 842;
  const pageHeight = 595;
  const margin = 24;
  const imageWidth = pageWidth - margin * 2;
  const maxSliceHeight = Math.floor((pageHeight - margin * 2) * canvas.width / imageWidth);
  const slices = [];
  for (let top = 0; top < canvas.height; top += maxSliceHeight) {
    const height = Math.min(maxSliceHeight, canvas.height - top);
    const pageCanvas = document.createElement('canvas');
    pageCanvas.width = canvas.width;
    pageCanvas.height = height;
    pageCanvas.getContext('2d').drawImage(canvas, 0, top, canvas.width, height, 0, 0, canvas.width, height);
    slices.push({ height, bytes: jpegBytes(pageCanvas) });
  }
  const chunks = [];
  const offsets = [0];
  let length = 0;
  const append = value => {
    const bytes = typeof value === 'string' ? ascii(value) : value;
    chunks.push(bytes);
    length += bytes.length;
  };
  const object = (id, content, stream) => {
    offsets[id] = length;
    append(`${id} 0 obj\n${content}`);
    if (stream) {
      append('stream\n');
      append(stream);
      append('\nendstream\n');
    }
    append('endobj\n');
  };
  append('%PDF-1.4\n');
  object(1, '<< /Type /Catalog /Pages 2 0 R >>\n');
  object(2, `<< /Type /Pages /Kids [${slices.map((_, index) => `${index * 3 + 3} 0 R`).join(' ')}] /Count ${slices.length} >>\n`);
  slices.forEach((slice, index) => {
    const pageId = index * 3 + 3;
    const contentId = pageId + 1;
    const imageId = pageId + 2;
    const imageHeight = slice.height * imageWidth / canvas.width;
    const drawing = ascii(`q\n${imageWidth} 0 0 ${imageHeight.toFixed(3)} ${margin} ${(pageHeight - margin - imageHeight).toFixed(3)} cm\n/Im0 Do\nQ\n`);
    object(pageId, `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pageWidth} ${pageHeight}] /Resources << /XObject << /Im0 ${imageId} 0 R >> >> /Contents ${contentId} 0 R >>\n`);
    object(contentId, `<< /Length ${drawing.length} >>\n`, drawing);
    object(imageId, `<< /Type /XObject /Subtype /Image /Width ${canvas.width} /Height ${slice.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${slice.bytes.length} >>\n`, slice.bytes);
  });
  const xref = length;
  append(`xref\n0 ${offsets.length}\n0000000000 65535 f \n`);
  for (let id = 1; id < offsets.length; id++) append(`${String(offsets[id]).padStart(10, '0')} 00000 n \n`);
  append(`trailer\n<< /Size ${offsets.length} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`);
  return new Blob(chunks, { type: 'application/pdf' });
}

export function saveProfitLossPdf(report, filename) {
  saveCanvasPdf(renderProfitLossCanvas(report), filename);
}

export function saveCanvasPdf(canvas, filename) {
  download(pdfFromCanvas(canvas), `${filename}.pdf`);
}
