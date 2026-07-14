/**
 * transport adapter の配線検証（02 A-2/AU-1）:
 * - Authorization と X-Authorization の両ヘッダがトークン付きリクエストに載ること
 *   （共有ホスティングのプロキシが標準ヘッダを落とす環境向けのミラー — nene2-client 内蔵）
 * - 公開表面 5 メソッドのうち get/upload の疎通形
 */
import { afterEach, describe, expect, it, vi } from 'vitest';

import { apiClient, tokenStore } from './client';

type FetchArgs = [RequestInfo | URL, RequestInit | undefined];

function stubFetch(response: () => Response): { calls: FetchArgs[] } {
  const calls: FetchArgs[] = [];
  vi.stubGlobal('fetch', (input: RequestInfo | URL, init?: RequestInit) => {
    calls.push([input, init]);
    return Promise.resolve(response());
  });
  return { calls };
}

function headersOf(call: FetchArgs): Headers {
  const [input, init] = call;
  if (input instanceof Request) return input.headers;
  return new Headers(init?.headers);
}

afterEach(() => {
  vi.unstubAllGlobals();
  tokenStore.clearToken();
});

describe('apiClient', () => {
  it('トークン付き GET は Authorization と X-Authorization の両ヘッダを送る', async () => {
    const { calls } = stubFetch(
      () => new Response(JSON.stringify({ ok: true }), { status: 200 }),
    );
    tokenStore.setToken('test-token');

    await apiClient.get<{ ok: boolean }>('/api/examples/ping');

    expect(calls).toHaveLength(1);
    const headers = headersOf(calls[0] as FetchArgs);
    expect(headers.get('authorization')).toBe('Bearer test-token');
    expect(headers.get('x-authorization')).toBe('Bearer test-token');
  });

  it('未サインインの GET は認証ヘッダを送らない', async () => {
    const { calls } = stubFetch(
      () => new Response(JSON.stringify({ ok: true }), { status: 200 }),
    );

    await apiClient.get<{ ok: boolean }>('/api/examples/ping');

    const headers = headersOf(calls[0] as FetchArgs);
    expect(headers.get('authorization')).toBeNull();
    expect(headers.get('x-authorization')).toBeNull();
  });

  it('upload は FormData をそのまま送る（Content-Type はブラウザ委譲）', async () => {
    const { calls } = stubFetch(
      () => new Response(JSON.stringify({ ok: true }), { status: 200 }),
    );
    const formData = new FormData();
    formData.set('file', new Blob(['x']), 'x.txt');

    await apiClient.upload<{ ok: boolean }>('/api/examples/upload', formData);

    const [, init] = calls[0] as FetchArgs;
    expect(init?.body).toBe(formData);
    expect(headersOf(calls[0] as FetchArgs).get('content-type')).toBeNull();
  });
});
