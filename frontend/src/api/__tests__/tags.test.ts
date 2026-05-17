import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  createTag,
  deleteTag,
  fetchTags,
  isTag,
  isTagListResponse,
  updateTag,
} from '../tags';

// ---------------------------------------------------------------------------
// isTag
// ---------------------------------------------------------------------------

describe('isTag', () => {
  it('returns true for a valid Tag', () => {
    expect(isTag({ id: 1, name: 'backend' })).toBe(true);
  });

  it('returns true when extra fields are present', () => {
    expect(isTag({ id: 2, name: 'frontend', extra: true })).toBe(true);
  });

  it('returns false for null', () => {
    expect(isTag(null)).toBe(false);
  });

  it('returns false for a non-object primitive', () => {
    expect(isTag('string')).toBe(false);
    expect(isTag(0)).toBe(false);
  });

  it('returns false when id is not a number', () => {
    expect(isTag({ id: '1', name: 'n' })).toBe(false);
  });

  it('returns false when name is not a string', () => {
    expect(isTag({ id: 1, name: 42 })).toBe(false);
  });

  it('returns false when fields are missing', () => {
    expect(isTag({ id: 1 })).toBe(false);
    expect(isTag({ name: 'n' })).toBe(false);
    expect(isTag({})).toBe(false);
  });
});

// ---------------------------------------------------------------------------
// isTagListResponse
// ---------------------------------------------------------------------------

describe('isTagListResponse', () => {
  it('returns true for a valid response', () => {
    expect(
      isTagListResponse({
        items: [{ id: 1, name: 'backend' }],
        limit: 20,
        offset: 0,
      }),
    ).toBe(true);
  });

  it('returns true for an empty items array', () => {
    expect(isTagListResponse({ items: [], limit: 20, offset: 0 })).toBe(true);
  });

  it('returns false when items is not an array', () => {
    expect(isTagListResponse({ items: null, limit: 20, offset: 0 })).toBe(
      false,
    );
  });

  it('returns false when an item in the array is not a Tag', () => {
    expect(
      isTagListResponse({
        items: [{ id: '1', name: 'n' }],
        limit: 20,
        offset: 0,
      }),
    ).toBe(false);
  });

  it('returns false when limit is not a number', () => {
    expect(isTagListResponse({ items: [], limit: '20', offset: 0 })).toBe(
      false,
    );
  });

  it('returns false when offset is missing', () => {
    expect(isTagListResponse({ items: [], limit: 20 })).toBe(false);
  });

  it('returns false for null', () => {
    expect(isTagListResponse(null)).toBe(false);
  });
});

// ---------------------------------------------------------------------------
// fetch functions — mocked fetch
// ---------------------------------------------------------------------------

afterEach(() => {
  vi.restoreAllMocks();
});

function mockFetch(status: number, body: unknown): void {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue({
      ok: status >= 200 && status < 300,
      status,
      json: () => Promise.resolve(body),
    }),
  );
}

describe('fetchTags', () => {
  it('returns typed list on success', async () => {
    const payload = {
      items: [{ id: 1, name: 'backend' }],
      limit: 20,
      offset: 0,
    };
    mockFetch(200, payload);

    const result = await fetchTags();
    expect(result.items).toHaveLength(1);
    expect(result.items[0]?.name).toBe('backend');
  });

  it('throws on HTTP error', async () => {
    mockFetch(500, {});
    await expect(fetchTags()).rejects.toThrow('HTTP 500');
  });

  it('throws when response shape is wrong', async () => {
    mockFetch(200, { unexpected: true });
    await expect(fetchTags()).rejects.toThrow('expected shape');
  });
});

describe('createTag', () => {
  it('returns the created Tag', async () => {
    mockFetch(201, { id: 7, name: 'new-tag' });
    const tag = await createTag({ name: 'new-tag' });
    expect(tag.id).toBe(7);
    expect(tag.name).toBe('new-tag');
  });

  it('throws on HTTP error', async () => {
    mockFetch(422, {});
    await expect(createTag({ name: '' })).rejects.toThrow('HTTP 422');
  });
});

describe('updateTag', () => {
  it('returns the updated Tag', async () => {
    mockFetch(200, { id: 3, name: 'renamed' });
    const tag = await updateTag(3, { name: 'renamed' });
    expect(tag.name).toBe('renamed');
  });

  it('throws on HTTP error', async () => {
    mockFetch(404, {});
    await expect(updateTag(99, { name: 'x' })).rejects.toThrow('HTTP 404');
  });

  it('throws when response shape is wrong', async () => {
    mockFetch(200, { wrong: true });
    await expect(updateTag(1, { name: 'x' })).rejects.toThrow('expected shape');
  });
});

describe('deleteTag', () => {
  it('resolves without error on success', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({ ok: true, status: 204 }),
    );
    await expect(deleteTag(1)).resolves.toBeUndefined();
  });

  it('throws on HTTP error', async () => {
    mockFetch(404, {});
    await expect(deleteTag(99)).rejects.toThrow('HTTP 404');
  });
});
