import { Document, Page, View, Text, Image, StyleSheet } from '@react-pdf/renderer';
import { money, formatDate } from '../lib/format.js';

const styles = StyleSheet.create({
  page: { padding: 32, fontSize: 10, fontFamily: 'Helvetica', color: '#1f2937' },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    borderBottomWidth: 2,
    borderBottomColor: '#111827',
    borderBottomStyle: 'solid',
    paddingBottom: 8,
    marginBottom: 12,
  },
  logo: { height: 40, maxWidth: 110, objectFit: 'contain' },
  h1: { fontSize: 18, fontFamily: 'Helvetica-Bold' },
  muted: { color: '#6b7280', fontSize: 9, marginTop: 2 },
  box: {
    borderWidth: 1,
    borderColor: '#e5e7eb',
    borderStyle: 'solid',
    borderRadius: 4,
    padding: 10,
    marginBottom: 12,
  },
  boxLabel: { fontFamily: 'Helvetica-Bold' },
  table: { marginTop: 8 },
  tableHeaderRow: { flexDirection: 'row', backgroundColor: '#f3f4f6' },
  tableRow: {
    flexDirection: 'row',
    borderBottomWidth: 1,
    borderBottomColor: '#e5e7eb',
    borderBottomStyle: 'solid',
  },
  th: { padding: 6, fontFamily: 'Helvetica-Bold', fontSize: 9 },
  td: { padding: 6, fontSize: 9 },
  colDesc: { flex: 3 },
  colNum: { flex: 1, textAlign: 'right' },
  totals: { marginTop: 12, alignSelf: 'flex-end', width: '40%' },
  totalsRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 2 },
  totalsFinal: {
    fontFamily: 'Helvetica-Bold',
    fontSize: 12,
    borderTopWidth: 1,
    borderTopColor: '#111827',
    borderTopStyle: 'solid',
    marginTop: 4,
    paddingTop: 4,
  },
  notas: { marginTop: 12 },
  firmaBox: { marginTop: 48, width: 240 },
  firmaImg: { maxHeight: 90, maxWidth: 240 },
  firmaLine: {
    borderTopWidth: 1,
    borderTopColor: '#111827',
    borderTopStyle: 'solid',
    marginTop: 4,
    paddingTop: 4,
    color: '#6b7280',
    fontSize: 9,
  },
});

export default function Factura({ factura, firma, logo }) {
  const currency = factura?.currency ?? 'COP';
  const cliente = factura?.cliente ?? {};
  const detalles = factura?.detalles ?? [];
  // BORRADOR = cotización (aún no contabilizada, ver ReciboService::enviarPorCorreo).
  // El PDF debe dejarlo igual de claro que el correo y el WhatsApp.
  const esCotizacion = factura?.estado === 'BORRADOR';
  const etiqueta = esCotizacion ? 'Cotización' : 'Factura';

  return (
    <Document>
      <Page size="A4" style={styles.page}>
        <View style={styles.header}>
          <View>
            <Text style={styles.h1}>{etiqueta} {factura?.numero}</Text>
            <Text style={styles.muted}>
              Logix · {formatDate(factura?.fecha)} · {esCotizacion ? 'COTIZACIÓN' : factura?.estado}
            </Text>
          </View>
          {logo ? <Image src={logo} style={styles.logo} /> : null}
        </View>

        <View style={styles.box}>
          <Text>
            <Text style={styles.boxLabel}>Cliente: </Text>
            {cliente.nombre_completo}
          </Text>
          {cliente.numero_documento ? (
            <Text style={styles.muted}>
              {cliente.tipo_documento} {cliente.numero_documento}
            </Text>
          ) : null}
          {cliente.email ? <Text style={styles.muted}>{cliente.email}</Text> : null}
        </View>

        <View style={styles.table}>
          <View style={styles.tableHeaderRow}>
            <Text style={[styles.th, styles.colDesc]}>Descripción</Text>
            <Text style={[styles.th, styles.colNum]}>Cant.</Text>
            <Text style={[styles.th, styles.colNum]}>Precio</Text>
            <Text style={[styles.th, styles.colNum]}>IVA</Text>
            <Text style={[styles.th, styles.colNum]}>Subtotal</Text>
          </View>
          {detalles.map((d, i) => (
            <View style={styles.tableRow} key={i}>
              <Text style={[styles.td, styles.colDesc]}>{d.descripcion}</Text>
              <Text style={[styles.td, styles.colNum]}>{Number(d.cantidad).toFixed(2)}</Text>
              <Text style={[styles.td, styles.colNum]}>{money(d.precio_unitario, currency)}</Text>
              <Text style={[styles.td, styles.colNum]}>{Number(d.impuesto_porcentaje).toFixed(0)}%</Text>
              <Text style={[styles.td, styles.colNum]}>{money(d.subtotal, currency)}</Text>
            </View>
          ))}
        </View>

        <View style={styles.totals}>
          <View style={styles.totalsRow}>
            <Text>Subtotal</Text>
            <Text>{money(factura?.subtotal, currency)}</Text>
          </View>
          <View style={styles.totalsRow}>
            <Text>Impuestos</Text>
            <Text>{money(factura?.impuestos, currency)}</Text>
          </View>
          <View style={[styles.totalsRow, styles.totalsFinal]}>
            <Text>TOTAL</Text>
            <Text>{money(factura?.total, currency)}</Text>
          </View>
        </View>

        {factura?.notas ? (
          <View style={styles.notas}>
            <Text style={styles.boxLabel}>Observaciones:</Text>
            <Text>{factura.notas}</Text>
          </View>
        ) : null}

        {firma ? (
          <View style={styles.firmaBox}>
            <Image src={firma} style={styles.firmaImg} />
            <Text style={styles.firmaLine}>Firma autorizada</Text>
          </View>
        ) : null}
      </Page>
    </Document>
  );
}
