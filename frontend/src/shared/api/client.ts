// frontend/src/shared/api/client.ts — The only place HTTP lives.
// transport 生成（createNene2Transport）はフリートでこのパス 1 箇所のみ（02 A-2）。
import {
  createNene2Transport,
  createSessionTokenStore,
  isNene2ClientError,
  type Nene2ClientError,
} from '@hideyukimori/nene2-client';

import { env } from '@/shared/config/env';

/**
 * アプリ固有 storage key。命名は `nene_<product>_token` 固定（02 AU-1）。
 * 新製品ではプロダクト名（`nene-` 接頭辞を除いた製品名）に変更する。
 */
export const tokenStore = createSessionTokenStore({ key: 'nene_nene2_token' });

const transport = createNene2Transport({
  baseUrl: env.apiBaseUrl,
  tokenStore,
  // fetch はモジュールロード時に束縛せず呼び出し時に解決する: テストは MSW の
  // server.listen() で globalThis.fetch を差し替えるため（payout#155 現物形）。
  fetch: (input, init) => globalThis.fetch(input, init),
});

/** RFC 9457 正規化済みエラー。UI 文言への解決は shared/api/errors.ts のみ（02 §9）。 */
export type AppError = Nene2ClientError;
export const isAppError = isNene2ClientError;

/** 公開表面は 5 メソッド。シグネチャは payout#155 の「公開表面不変」形（02 A-2）。 */
export const apiClient = {
  get: <T>(path: string, signal?: AbortSignal) =>
    transport.get<T>(path, { signal }),
  post: <T>(path: string, body?: unknown, signal?: AbortSignal) =>
    transport.post<T>(path, body, { signal }),
  patch: <T>(path: string, body?: unknown, signal?: AbortSignal) =>
    transport.patch<T>(path, body, { signal }),
  delete: <T = void>(path: string, signal?: AbortSignal) =>
    transport.delete<T>(path, { signal }),
  upload: <T>(path: string, formData: FormData, signal?: AbortSignal) =>
    transport.upload<T>(path, formData, { signal }),
} as const;
