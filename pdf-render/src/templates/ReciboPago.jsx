import { Document, Page, View, Text, StyleSheet } from '@react-pdf/renderer';
import { formatDate } from '../lib/format.js';

const styles = StyleSheet.create({
  page: { padding: 32, fontSize: 10, fontFamily: 'Helvetica', color: '#1f2937' },
  encabezado: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    borderBottomWidth: 3,
    borderBottomColor: '#2563eb',
    borderBottomStyle: 'solid',
    paddingBottom: 16,
    marginBottom: 24,
  },
  marcaH1: { fontSize: 18, color: '#2563eb', fontFamily: 'Helvetica-Bold' },
  marcaP: { color: '#6b7280', fontSize: 9, marginTop: 2 },
  numTitulo: { fontSize: 14, fontFamily: 'Helvetica-Bold', color: '#111827', textAlign: 'right' },
  numRef: { color: '#6b7280', fontSize: 9, textAlign: 'right', marginTop: 2 },
  bloque: { marginBottom: 18 },
  bloqueH2: { fontSize: 9, color: '#6b7280', marginBottom: 6, textTransform: 'uppercase' },
  th: { backgroundColor: '#2563eb', color: '#fff', padding: 8, fontSize: 9, fontFamily: 'Helvetica-Bold' },
  tableHeaderRow: { flexDirection: 'row' },
  tableRow: {
    flexDirection: 'row',
    borderBottomWidth: 1,
    borderBottomColor: '#e5e7eb',
    borderBottomStyle: 'solid',
  },
  td: { padding: 8, fontSize: 9 },
  colConcepto: { flex: 2 },
  colRef: { flex: 1.5 },
  colMedio: { flex: 1 },
  colValor: { flex: 1.5, textAlign: 'right' },
  total: { textAlign: 'right', marginTop: 14, fontSize: 14, fontFamily: 'Helvetica-Bold', color: '#111827' },
  estado: { color: '#166534', fontSize: 9, fontFamily: 'Helvetica-Bold' },
  pie: {
    marginTop: 40,
    paddingTop: 12,
    borderTopWidth: 1,
    borderTopColor: '#e5e7eb',
    borderTopStyle: 'solid',
    color: '#9ca3af',
    fontSize: 8,
    textAlign: 'center',
  },
});

export default function ReciboPago({ usuario, concepto, monto, transaccion, fecha }) {
  const referencia =
    transaccion?.payload?.reference ?? transaccion?.provider_event_id ?? `TX-${transaccion?.id}`;
  const provider = String(transaccion?.provider ?? '').toUpperCase();
  const montoFmt = Number(monto ?? 0).toLocaleString('es-CO', { maximumFractionDigits: 0 });

  return (
    <Document>
      <Page size="A4" style={styles.page}>
        <View style={styles.encabezado}>
          <View>
            <Text style={styles.marcaH1}>Logix POS</Text>
            <Text style={styles.marcaP}>Plataforma de punto de venta y gestión para talleres y negocios</Text>
          </View>
          <View>
            <Text style={styles.numTitulo}>RECIBO DE PAGO</Text>
            <Text style={styles.numRef}>No. {String(transaccion?.id ?? '').padStart(6, '0')}</Text>
            <Text style={styles.numRef}>{formatDate(fecha, true)}</Text>
          </View>
        </View>

        <View style={styles.bloque}>
          <Text style={styles.bloqueH2}>Cliente</Text>
          <Text style={{ fontFamily: 'Helvetica-Bold' }}>{usuario?.name}</Text>
          <Text>{usuario?.email}</Text>
          {usuario?.numero_documento ? (
            <Text>
              {usuario.tipo_documento} {usuario.numero_documento}
            </Text>
          ) : null}
        </View>

        <View style={styles.bloque}>
          <Text style={styles.bloqueH2}>Detalle del pago</Text>
          <View style={styles.tableHeaderRow}>
            <Text style={[styles.th, styles.colConcepto]}>Concepto</Text>
            <Text style={[styles.th, styles.colRef]}>Referencia</Text>
            <Text style={[styles.th, styles.colMedio]}>Medio</Text>
            <Text style={[styles.th, styles.colValor]}>Valor</Text>
          </View>
          <View style={styles.tableRow}>
            <Text style={[styles.td, styles.colConcepto]}>{concepto}</Text>
            <Text style={[styles.td, styles.colRef]}>{referencia}</Text>
            <Text style={[styles.td, styles.colMedio]}>{provider}</Text>
            <Text style={[styles.td, styles.colValor]}>${montoFmt} COP</Text>
          </View>
          <Text style={styles.total}>
            Total pagado: ${montoFmt} COP <Text style={styles.estado}>  APROBADO</Text>
          </Text>
        </View>

        <Text style={styles.pie}>
          Este recibo confirma el pago recibido por Logix a través de la pasarela {provider}.{'\n'}
          Documento generado automáticamente — no requiere firma.
        </Text>
      </Page>
    </Document>
  );
}
