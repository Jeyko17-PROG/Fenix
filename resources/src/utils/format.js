export function money(value, currency) {
  const sym = currency === 'USD' ? 'USD ' : '$';
  const n = Number(value ?? 0).toLocaleString('es-CO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
  return `${sym}${n}`;
}

export function moneyCOP(value) {
  const n = Number(value ?? 0).toLocaleString('es-CO', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  });
  return `$${n} COP`;
}

export function formatDate(iso, withTime = false) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const datePart = d.toLocaleDateString('es-CO');
  if (!withTime) return datePart;
  const timePart = d.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' });
  return `${datePart} ${timePart}`;
}
