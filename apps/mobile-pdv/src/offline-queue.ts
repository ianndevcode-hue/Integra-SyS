import AsyncStorage from '@react-native-async-storage/async-storage';
import type { PendingSale } from './types';

const QUEUE_KEY = '@integra/pending-sales';
const LOG_KEY = '@integra/fiscal-logs';

/**
 * Fila offline do PDV:
 * - venda offline é salva localmente com status "pendente de emissão fiscal"
 * - a NFC-e NÃO é emitida offline (primeira versão)
 * - quando a conexão volta, as vendas são sincronizadas e emitidas via API
 */
export async function getPendingSales(): Promise<PendingSale[]> {
  const raw = await AsyncStorage.getItem(QUEUE_KEY);
  return raw ? (JSON.parse(raw) as PendingSale[]) : [];
}

export async function enqueueSale(sale: PendingSale): Promise<void> {
  const queue = await getPendingSales();
  queue.push(sale);
  await AsyncStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
}

export async function removeSale(localSaleId: string): Promise<void> {
  const queue = await getPendingSales();
  await AsyncStorage.setItem(QUEUE_KEY, JSON.stringify(queue.filter((sale) => sale.localSaleId !== localSaleId)));
}

/** Log local de eventos fiscais (rejeições, erros de sync). */
export async function appendLocalLog(entry: { at: string; message: string; data?: unknown }): Promise<void> {
  const raw = await AsyncStorage.getItem(LOG_KEY);
  const logs = raw ? (JSON.parse(raw) as unknown[]) : [];
  logs.unshift(entry);
  await AsyncStorage.setItem(LOG_KEY, JSON.stringify(logs.slice(0, 200)));
}

export async function getLocalLogs(): Promise<Array<{ at: string; message: string }>> {
  const raw = await AsyncStorage.getItem(LOG_KEY);
  return raw ? JSON.parse(raw) : [];
}
