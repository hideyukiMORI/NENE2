/**
 * テーマコントローラ（TH-05: data-theme を付与する唯一の登録ファイル）。
 *
 * 公開 API 形状の正本はこの W0.starter 同梱実装（規約 03 §9.11 の委任を受けて確定）:
 * - モードは 'light' | 'dark'。light は data-theme 属性の「不在」で表現する
 *   （テーマファイルの @theme 直値 = 既定 light、[data-theme='dark'] = 同名上書き — TH-04）。
 * - 永続化キーは localStorage 'nene2-theme'（DM-03。保存が無い間は OS 設定に追従する）。
 * - 初期属性は index.html の同期スニペット（DM-03）が描画前に確定する。
 * - テーマ状態は useSyncExternalStore の module store（CS-2）。
 */
import { useSyncExternalStore } from 'react';

export type ThemeMode = 'light' | 'dark';

const STORAGE_KEY = 'nene2-theme';
const listeners = new Set<() => void>();

function notify(): void {
  for (const listener of listeners) listener();
}

function readStored(): ThemeMode | null {
  try {
    const value = localStorage.getItem(STORAGE_KEY);
    return value === 'light' || value === 'dark' ? value : null;
  } catch {
    return null;
  }
}

function systemTheme(): ThemeMode {
  return typeof matchMedia === 'function' &&
    matchMedia('(prefers-color-scheme: dark)').matches
    ? 'dark'
    : 'light';
}

function applyToScope(mode: ThemeMode): void {
  // テーマスコープ要素は <html> 固定（TH-05）。data-theme の書き込みは本ファイルのみ。
  if (mode === 'dark') {
    document.documentElement.dataset['theme'] = 'dark';
  } else {
    delete document.documentElement.dataset['theme'];
  }
}

let current: ThemeMode = readStored() ?? systemTheme();

function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

function getTheme(): ThemeMode {
  return current;
}

function setTheme(mode: ThemeMode): void {
  current = mode;
  try {
    localStorage.setItem(STORAGE_KEY, mode);
  } catch {
    // 保存不能（プライバシーモード等）でも表示は切り替える
  }
  applyToScope(mode);
  notify();
}

// module store 標準形（02 CS-2: listeners + notify + subscribe/get/set）
export const themeStore = { subscribe, getTheme, setTheme };

export function useTheme(): ThemeMode {
  return useSyncExternalStore(subscribe, getTheme);
}
