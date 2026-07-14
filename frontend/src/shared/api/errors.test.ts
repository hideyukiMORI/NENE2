/**
 * toMessageKey（02 ER-2）: slug 表 → status switch → 'error.unknown' の 2 段固定形の検証。
 */
import { describe, expect, it, vi } from 'vitest';

import { apiClient } from './client';
import { toMessageKey } from './errors';

function problemResponse(status: number, type: string): Response {
  return new Response(JSON.stringify({ type, title: 'x', status }), {
    status,
    headers: { 'content-type': 'application/problem+json' },
  });
}

async function captureError(status: number, type: string): Promise<unknown> {
  vi.stubGlobal('fetch', () => Promise.resolve(problemResponse(status, type)));
  try {
    await apiClient.get('/api/x');
    return null;
  } catch (error) {
    return error;
  } finally {
    vi.unstubAllGlobals();
  }
}

describe('toMessageKey', () => {
  it('AppError でない値は error.unknown', () => {
    expect(toMessageKey(new Error('boom'))).toBe('error.unknown');
    expect(toMessageKey(undefined)).toBe('error.unknown');
  });

  it.each([
    [401, 'error.unauthorized'],
    [403, 'error.forbidden'],
    [404, 'error.notFound'],
    [409, 'error.conflict'],
    [422, 'error.validation'],
    [429, 'error.rateLimit'],
    [500, 'error.serverError'],
  ] as const)('HTTP %d は %s へ写像される', async (status, expected) => {
    const error = await captureError(
      status,
      `https://nene2.dev/problems/some-problem`,
    );
    expect(toMessageKey(error)).toBe(expected);
  });
});
