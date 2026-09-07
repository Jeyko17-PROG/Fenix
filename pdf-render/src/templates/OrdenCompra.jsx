import { Document, Page, View, Text, StyleSheet } from '@react-pdf/renderer';
import { formatDate } from '../lib/format.js';

const styles = StyleSheet.create({
  page: { padding: 32, fontSize: 10, fontFamily: 'Helvetica', color: '#1f2937' },
  header: {
    borderBottomWidth: 2,
    borderBottomColor: '#111827',
    borderBottomStyle: 'solid',
    paddingBottom: 8,
  },
  h1: { fontSize: 16, fontFamily: 'Helvetica-Bold' },
  muted: { color: '#6b7280', fontSize: 9, marginTop: 2 },
  box: {
    borderWidth: 1,
    borderColor: '#e5e7eb',
    borderStyle: 'solid',
    borderRadius: 4,
    padding: 10,
    marginTop: 12,
  },
  boxLabel: { fontFamily: 'Helvetica-Bold' },
  table: { marginTop: 14 },
  tableHeaderRow: { flexDirection: 'row', backgroundColor: '#f3f4f6' },
  tableRow: {
    flexDirection: 'row',
    borderBottomWidth: 1,
    borderBottomColor: '#e5e7eb',
    borderBottomStyle: 'solid',
  },
  th: { padding: 6, fontFamily: 'Helvetica-Bold', fontSize: 9 },
  td: { padding: 6, fontSize: 9 },
  colSku: { flex: 1.5 },
  colProducto: { flex: 3 },
  colNum: { flex: 1.5, textAlign: 'right' },
  totalRow: { flexDirection: 'row', marginTop: 4 },
  totalLabel: { flex: 6, textAlign: 'right', paddingRight: 6, fontFamily: 'Helvetica-Bold', fontSize: 12 },
  totalValue: { flex: 1.5, textAlign: 'right', fontFamily: 'Helvetica-Bold', fontSize: 12 },
  pie: { marginTop: 30, color: '#6b7280', fontSize: 9 },
});

export default function OrdenCompra({ orden, firma }) {
  const proveedor = orden?.proveedor ?? {};
  const bodega = orden?.bodega ?? {};
  const detalles = orden?.detalles ?? [];

  return (
    <Document>
      <Page size="A4" style={styles.page}>
        <View style={styles.header}>
          <Text style={styles.h1}>Orden de Compra #{orden?.id}</Text>
          <Text style={styles.muted}>
            Logix ERP · {formatDate(orden?.fecha)} · Estado: {orden?.estado}
          </Text>
        </View>

        <View style={styles.box}>
          <Text>
            <Text style={styles.boxLabel}>Proveedor: </Text>
            {proveedor.razon_social}
          </Text>
          <Text style={styles.muted}>
            {proveedor.tipo_documento} {proveedor.numero_documento}
            {proveedor.digito_verificacion ? `-${proveedor.digito_verificacion}` : ''}
          </Text>
          <Text>
            <Text style={styles.boxLabel}>Bodega destino: </Text>
            {bodega.nombre ?? '—'}
          </Text>
        </View>

        <View style={styles.table}>
          <View style={styles.tableHeaderRow}>
            <Text style={[styles.th, styles.colSku]}>SKU</Text>
            <Text style={[styles.th, styles.colProducto]}>Producto</Text>
            <Text style={[styles.th, styles.colNum]}>Cantidad</Text>
            <Text style={[styles.th, styles.colNum]}>Precio unit.</Text>
            <Text style={[styles.th, styles.colNum]}>Subtotal</Text>
          </View>
          {detalles.map((d, i) => (
            <View style={styles.tableRow} key={i}>
              <Text style={[styles.td, styles.colSku]}>{d.producto?.sku}</Text>
              <Text style={[styles.td, styles.colProducto]}>{d.producto?.nombre}</Text>
              <Text style={[styles.td, styles.colNum]}>{Number(d.cantidad).toFixed(2)}</Text>
              <Text style={[styles.td, styles.colNum]}>
                ${Number(d.precio_unitario).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
              </Text>
              <Text style={[styles.td, styles.colNum]}>
                ${(Number(d.cantidad) * Number(d.precio_unitario)).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
              </Text>
            </View>
          ))}
        </View>

        <View style={styles.totalRow}>
          <Text style={styles.totalLabel}>TOTAL</Text>
          <Text style={styles.totalValue}>
            ${Number(orden?.total).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
          </Text>
        </View>

        <Text style={styles.pie}>
          Documento generado automáticamente por Logix ERP.
          {firma ? ` Firma electrónica: ${firma.estado}` : ''}
          {firma?.hash_documento ? ` · Hash: ${String(firma.hash_documento).slice(0, 24)}…` : ''}
        </Text>
      </Page>
    </Document>
  );
}
