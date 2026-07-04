import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  SafeAreaView,
  ScrollView,
  Share,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from 'react-native';
import { StatusBar } from 'expo-status-bar';
import { api, setToken, getToken } from './src/api';
import { appendLocalLog, enqueueSale, getPendingSales, removeSale } from './src/offline-queue';
import { FISCAL_STATUS_COLORS, FISCAL_STATUS_LABELS } from './src/fiscal-status';
import type { CartItem, EmissionOutcome, PendingSale, SalePayment } from './src/types';

type Screen = 'login' | 'sale' | 'confirmation';

const PAYMENT_METHODS: Array<[string, string]> = [
  ['01', 'Dinheiro'],
  ['03', 'Crédito'],
  ['04', 'Débito'],
  ['17', 'PIX'],
];

const DEMO_PRODUCTS: Array<Omit<CartItem, 'quantity' | 'totalPrice'>> = [
  { productId: 'P001', code: 'P001', description: 'Café Torrado 500g', ncm: '09012100', cfop: '5102', unit: 'UN', unitPrice: 24.9, discount: 0, icmsCsosn: '102' },
  { productId: 'P002', code: 'P002', description: 'Açúcar Cristal 1kg', ncm: '17019900', cfop: '5102', unit: 'UN', unitPrice: 5.49, discount: 0, icmsCsosn: '102' },
  { productId: 'P003', code: 'P003', description: 'Água Mineral 500ml', ncm: '22011000', cfop: '5405', unit: 'UN', unitPrice: 3.0, discount: 0, icmsCsosn: '500' },
];

interface NfceApiResult {
  sale: { id: string; localSaleId: string | null };
  invoice: {
    id: string;
    status: string;
    accessKey: string | null;
    protocol: string | null;
    rejectionCode: string | null;
    rejectionMessage: string | null;
    series: number;
    number: number;
  } | null;
  result: { success: boolean; status: string };
}

function money(value: number): string {
  return `R$ ${value.toFixed(2).replace('.', ',')}`;
}

export default function App() {
  const [screen, setScreen] = useState<Screen>('login');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // login
  const [email, setEmail] = useState('caixa@demo.local');
  const [password, setPassword] = useState('');

  // venda
  const [cart, setCart] = useState<CartItem[]>([]);
  const [paymentMethod, setPaymentMethod] = useState('01');
  const [customerDocument, setCustomerDocument] = useState('');
  const [allowOffline] = useState(true); // venda offline habilitada por configuração
  const [pendingCount, setPendingCount] = useState(0);

  // confirmação
  const [outcome, setOutcome] = useState<EmissionOutcome | null>(null);

  const cartTotal = cart.reduce((sum, item) => sum + item.totalPrice - item.discount, 0);

  const refreshPending = useCallback(async () => {
    const pending = await getPendingSales();
    setPendingCount(pending.length);
  }, []);

  useEffect(() => {
    getToken().then((token) => {
      if (token) setScreen('sale');
    });
    refreshPending();
  }, [refreshPending]);

  async function handleLogin() {
    setLoading(true);
    setError(null);
    try {
      const result = await api<{ accessToken: string }>('/auth/login', {
        method: 'POST',
        body: { email, password },
      });
      await setToken(result.accessToken);
      setScreen('sale');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Falha no login');
    } finally {
      setLoading(false);
    }
  }

  function addProduct(product: (typeof DEMO_PRODUCTS)[number]) {
    setCart((current) => {
      const existing = current.find((item) => item.code === product.code);
      if (existing) {
        return current.map((item) =>
          item.code === product.code
            ? { ...item, quantity: item.quantity + 1, totalPrice: Number(((item.quantity + 1) * item.unitPrice).toFixed(2)) }
            : item,
        );
      }
      return [...current, { ...product, quantity: 1, totalPrice: product.unitPrice }];
    });
  }

  async function emitSale(sale: PendingSale): Promise<EmissionOutcome> {
    const response = await api<NfceApiResult>('/pdv/invoices/nfce', { method: 'POST', body: sale });
    const invoice = response.invoice;

    if (invoice && invoice.status === 'AUTHORIZED') {
      return {
        invoiceId: invoice.id,
        saleNumber: `${invoice.series}/${invoice.number}`,
        status: 'AUTHORIZED',
        accessKey: invoice.accessKey ?? undefined,
        protocol: invoice.protocol ?? undefined,
      };
    }

    return {
      invoiceId: invoice?.id,
      saleNumber: invoice ? `${invoice.series}/${invoice.number}` : undefined,
      status: 'REJECTED',
      rejectionCode: invoice?.rejectionCode ?? undefined,
      rejectionMessage: invoice?.rejectionMessage ?? undefined,
    };
  }

  async function finishSale() {
    if (cart.length === 0) {
      Alert.alert('Venda vazia', 'Adicione ao menos um item.');
      return;
    }

    const payments: SalePayment[] = [{ method: paymentMethod, amount: Number(cartTotal.toFixed(2)) }];
    const sale: PendingSale = {
      localSaleId: `pdv-${Date.now()}-${Math.floor(Math.random() * 10000)}`,
      soldAt: new Date().toISOString(),
      customerDocument: customerDocument.trim() || undefined,
      items: cart,
      payments,
      discount: 0,
    };

    setLoading(true);
    setError(null);
    setOutcome({ status: 'EMITTING' });
    setScreen('confirmation');

    try {
      // 1. App salva venda local antes de enviar
      await enqueueSale(sale);

      // 2-7. API cria a venda e chama o fiscal-service (NFePHP) para emitir a NFC-e
      const result = await emitSale(sale);

      await removeSale(sale.localSaleId);
      await refreshPending();
      setOutcome(result);

      if (result.status === 'REJECTED') {
        await appendLocalLog({
          at: new Date().toISOString(),
          message: `NFC-e rejeitada: ${result.rejectionCode} - ${result.rejectionMessage}`,
        });
      }

      setCart([]);
      setCustomerDocument('');
    } catch (err) {
      // Offline ou API indisponível: mantém venda na fila local
      if (allowOffline) {
        await appendLocalLog({
          at: new Date().toISOString(),
          message: `Venda ${sale.localSaleId} pendente de emissão fiscal (offline): ${err instanceof Error ? err.message : ''}`,
        });
        await refreshPending();
        setOutcome({ status: 'PENDING_EMISSION' });
        setCart([]);
        setCustomerDocument('');
        Alert.alert('Venda pendente de emissão fiscal', 'A venda foi salva localmente e será emitida quando a conexão voltar.');
      } else {
        setError(err instanceof Error ? err.message : 'Falha ao emitir NFC-e');
        setScreen('sale');
      }
    } finally {
      setLoading(false);
    }
  }

  async function syncPending() {
    setLoading(true);
    try {
      const pending = await getPendingSales();
      let emitted = 0;
      for (const sale of pending) {
        try {
          const result = await emitSale(sale);
          await removeSale(sale.localSaleId);
          emitted += 1;
          if (result.status === 'REJECTED') {
            await appendLocalLog({
              at: new Date().toISOString(),
              message: `Sync: NFC-e rejeitada (${sale.localSaleId}): ${result.rejectionCode}`,
            });
          }
        } catch {
          break; // ainda offline
        }
      }
      await refreshPending();
      Alert.alert('Sincronização', emitted > 0 ? `${emitted} venda(s) sincronizada(s) e emitida(s).` : 'Nenhuma venda sincronizada (sem conexão?).');
    } finally {
      setLoading(false);
    }
  }

  async function retryEmission() {
    if (!outcome?.invoiceId) return;
    setLoading(true);
    setOutcome((current) => (current ? { ...current, status: 'EMITTING' } : current));
    try {
      const response = await api<{ invoice: NfceApiResult['invoice'] }>(`/pdv/invoices/${outcome.invoiceId}/retry`, { method: 'POST' });
      const invoice = response.invoice;
      if (invoice && invoice.status === 'AUTHORIZED') {
        setOutcome({
          invoiceId: invoice.id,
          saleNumber: `${invoice.series}/${invoice.number}`,
          status: 'AUTHORIZED',
          accessKey: invoice.accessKey ?? undefined,
          protocol: invoice.protocol ?? undefined,
        });
      } else {
        setOutcome({
          invoiceId: invoice?.id ?? outcome.invoiceId,
          saleNumber: outcome.saleNumber,
          status: 'REJECTED',
          rejectionCode: invoice?.rejectionCode ?? undefined,
          rejectionMessage: invoice?.rejectionMessage ?? undefined,
        });
      }
    } catch (err) {
      Alert.alert('Falha no reenvio', err instanceof Error ? err.message : 'Erro desconhecido');
      setOutcome((current) => (current ? { ...current, status: 'REJECTED' } : current));
    } finally {
      setLoading(false);
    }
  }

  async function printDanfce() {
    if (!outcome?.invoiceId) return;
    setLoading(true);
    try {
      await api<{ pdfBase64: string }>(`/pdv/invoices/${outcome.invoiceId}/danfce`);
      Alert.alert('DANFCE', 'DANFCE gerado. Envie para a impressora térmica configurada.');
    } catch (err) {
      Alert.alert('Falha ao gerar DANFCE', err instanceof Error ? err.message : 'Erro');
    } finally {
      setLoading(false);
    }
  }

  async function shareReceipt() {
    if (!outcome) return;
    const lines = [
      'Integra PDV — Recibo',
      outcome.saleNumber ? `Venda: ${outcome.saleNumber}` : null,
      `Status NFC-e: ${FISCAL_STATUS_LABELS[outcome.status]}`,
      outcome.accessKey ? `Chave de acesso: ${outcome.accessKey}` : null,
      outcome.protocol ? `Protocolo: ${outcome.protocol}` : null,
    ].filter(Boolean);
    await Share.share({ message: lines.join('\n') });
  }

  // ------------------------------------------------------------------
  // Telas
  // ------------------------------------------------------------------

  if (screen === 'login') {
    return (
      <SafeAreaView style={styles.container}>
        <StatusBar style="dark" />
        <View style={styles.centered}>
          <Text style={styles.title}>Integra PDV</Text>
          <Text style={styles.subtitle}>Ponto de venda com NFC-e</Text>
          {error && <Text style={styles.error}>{error}</Text>}
          <TextInput style={styles.input} placeholder="E-mail" autoCapitalize="none" value={email} onChangeText={setEmail} />
          <TextInput style={styles.input} placeholder="Senha" secureTextEntry value={password} onChangeText={setPassword} />
          <TouchableOpacity style={styles.button} onPress={handleLogin} disabled={loading}>
            {loading ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>Entrar</Text>}
          </TouchableOpacity>
        </View>
      </SafeAreaView>
    );
  }

  if (screen === 'confirmation' && outcome) {
    const color = FISCAL_STATUS_COLORS[outcome.status];
    return (
      <SafeAreaView style={styles.container}>
        <StatusBar style="dark" />
        <ScrollView contentContainerStyle={{ padding: 20 }}>
          <Text style={styles.title}>Venda finalizada</Text>

          <View style={[styles.card, { borderLeftWidth: 5, borderLeftColor: color }]}>
            {outcome.saleNumber && <Text style={styles.line}>Número da venda: <Text style={styles.bold}>{outcome.saleNumber}</Text></Text>}
            <Text style={styles.line}>
              Status da NFC-e: <Text style={[styles.bold, { color }]}>{FISCAL_STATUS_LABELS[outcome.status]}</Text>
            </Text>
            {outcome.status === 'EMITTING' && <ActivityIndicator style={{ marginVertical: 10 }} />}
            {outcome.accessKey && (
              <Text style={styles.line}>Chave de acesso: <Text style={styles.mono}>{outcome.accessKey}</Text></Text>
            )}
            {outcome.protocol && (
              <Text style={styles.line}>Protocolo: <Text style={styles.mono}>{outcome.protocol}</Text></Text>
            )}
            {outcome.status === 'REJECTED' && (
              <View style={styles.rejectionBox}>
                <Text style={styles.rejectionTitle}>Rejeição {outcome.rejectionCode ?? ''}</Text>
                <Text style={styles.rejectionText}>{outcome.rejectionMessage ?? 'Sem mensagem'}</Text>
                <Text style={styles.hint}>Corrija os dados no Web SaaS e reenvie.</Text>
              </View>
            )}
            {outcome.status === 'PENDING_EMISSION' && (
              <Text style={[styles.hint, { color: '#92400e' }]}>Venda pendente de emissão fiscal — será emitida quando a conexão voltar.</Text>
            )}
          </View>

          {outcome.status === 'AUTHORIZED' && (
            <TouchableOpacity style={styles.button} onPress={printDanfce} disabled={loading}>
              <Text style={styles.buttonText}>Imprimir DANFCE</Text>
            </TouchableOpacity>
          )}
          <TouchableOpacity style={[styles.button, styles.buttonSecondary]} onPress={shareReceipt}>
            <Text style={[styles.buttonText, { color: '#111827' }]}>Compartilhar recibo</Text>
          </TouchableOpacity>
          {outcome.status === 'REJECTED' && outcome.invoiceId && (
            <TouchableOpacity style={[styles.button, { backgroundColor: '#d97706' }]} onPress={retryEmission} disabled={loading}>
              <Text style={styles.buttonText}>Tentar emitir novamente</Text>
            </TouchableOpacity>
          )}
          <TouchableOpacity style={[styles.button, styles.buttonSecondary]} onPress={() => setScreen('sale')}>
            <Text style={[styles.buttonText, { color: '#111827' }]}>Nova venda</Text>
          </TouchableOpacity>
        </ScrollView>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.container}>
      <StatusBar style="dark" />
      <View style={{ padding: 20, flex: 1 }}>
        <Text style={styles.title}>Nova venda</Text>

        {pendingCount > 0 && (
          <TouchableOpacity style={styles.pendingBanner} onPress={syncPending} disabled={loading}>
            <Text style={styles.pendingText}>
              ⚠ {pendingCount} venda(s) pendente(s) de emissão fiscal — toque para sincronizar
            </Text>
          </TouchableOpacity>
        )}

        {error && <Text style={styles.error}>{error}</Text>}

        <Text style={styles.sectionTitle}>Produtos</Text>
        <View style={styles.productRow}>
          {DEMO_PRODUCTS.map((product) => (
            <TouchableOpacity key={product.code} style={styles.productButton} onPress={() => addProduct(product)}>
              <Text style={styles.productName}>{product.description}</Text>
              <Text style={styles.productPrice}>{money(product.unitPrice)}</Text>
            </TouchableOpacity>
          ))}
        </View>

        <Text style={styles.sectionTitle}>Carrinho</Text>
        <FlatList
          style={{ flexGrow: 0, maxHeight: 180 }}
          data={cart}
          keyExtractor={(item) => item.code}
          ListEmptyComponent={<Text style={styles.hint}>Nenhum item.</Text>}
          renderItem={({ item }) => (
            <View style={styles.cartRow}>
              <Text style={{ flex: 1 }}>{item.quantity}x {item.description}</Text>
              <Text style={styles.bold}>{money(item.totalPrice)}</Text>
            </View>
          )}
        />

        <Text style={styles.sectionTitle}>Pagamento</Text>
        <View style={styles.productRow}>
          {PAYMENT_METHODS.map(([code, label]) => (
            <TouchableOpacity
              key={code}
              style={[styles.payButton, paymentMethod === code && styles.payButtonActive]}
              onPress={() => setPaymentMethod(code)}
            >
              <Text style={paymentMethod === code ? styles.payTextActive : styles.payText}>{label}</Text>
            </TouchableOpacity>
          ))}
        </View>

        <TextInput
          style={styles.input}
          placeholder="CPF/CNPJ do consumidor (opcional)"
          value={customerDocument}
          onChangeText={setCustomerDocument}
          keyboardType="numeric"
        />

        <View style={styles.totalRow}>
          <Text style={styles.totalLabel}>Total</Text>
          <Text style={styles.totalValue}>{money(cartTotal)}</Text>
        </View>

        <TouchableOpacity style={styles.button} onPress={finishSale} disabled={loading || cart.length === 0}>
          {loading ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>Finalizar venda e emitir NFC-e</Text>}
        </TouchableOpacity>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#f3f4f6' },
  centered: { flex: 1, justifyContent: 'center', padding: 24 },
  title: { fontSize: 24, fontWeight: '700', color: '#111827', marginBottom: 4 },
  subtitle: { fontSize: 14, color: '#6b7280', marginBottom: 20 },
  sectionTitle: { fontSize: 15, fontWeight: '700', color: '#374151', marginTop: 16, marginBottom: 8 },
  input: {
    backgroundColor: '#fff',
    borderRadius: 10,
    borderWidth: 1,
    borderColor: '#d1d5db',
    paddingHorizontal: 14,
    paddingVertical: 10,
    marginBottom: 12,
    fontSize: 15,
  },
  button: {
    backgroundColor: '#1d4ed8',
    borderRadius: 10,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: 10,
  },
  buttonSecondary: { backgroundColor: '#e5e7eb' },
  buttonText: { color: '#fff', fontWeight: '700', fontSize: 15 },
  error: { color: '#b91c1c', marginBottom: 12 },
  card: { backgroundColor: '#fff', borderRadius: 12, padding: 16, marginVertical: 12 },
  line: { fontSize: 14, color: '#374151', marginBottom: 6 },
  bold: { fontWeight: '700', color: '#111827' },
  mono: { fontFamily: 'monospace', fontSize: 12 },
  hint: { fontSize: 13, color: '#6b7280', marginTop: 6 },
  rejectionBox: { backgroundColor: '#fee2e2', borderRadius: 8, padding: 12, marginTop: 10 },
  rejectionTitle: { fontWeight: '700', color: '#991b1b', marginBottom: 4 },
  rejectionText: { color: '#991b1b' },
  pendingBanner: { backgroundColor: '#fef3c7', borderRadius: 10, padding: 12, marginBottom: 12 },
  pendingText: { color: '#92400e', fontWeight: '600' },
  productRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  productButton: {
    backgroundColor: '#fff',
    borderRadius: 10,
    padding: 12,
    minWidth: 100,
    borderWidth: 1,
    borderColor: '#e5e7eb',
  },
  productName: { fontSize: 13, fontWeight: '600', color: '#111827' },
  productPrice: { fontSize: 13, color: '#1d4ed8', marginTop: 4 },
  cartRow: { flexDirection: 'row', backgroundColor: '#fff', borderRadius: 8, padding: 10, marginBottom: 6 },
  payButton: {
    backgroundColor: '#fff',
    borderRadius: 999,
    paddingHorizontal: 16,
    paddingVertical: 8,
    borderWidth: 1,
    borderColor: '#d1d5db',
  },
  payButtonActive: { backgroundColor: '#1d4ed8', borderColor: '#1d4ed8' },
  payText: { color: '#374151' },
  payTextActive: { color: '#fff', fontWeight: '700' },
  totalRow: { flexDirection: 'row', justifyContent: 'space-between', marginTop: 8, alignItems: 'center' },
  totalLabel: { fontSize: 16, color: '#374151' },
  totalValue: { fontSize: 24, fontWeight: '800', color: '#111827' },
});
