import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  createNote,
  fetchNoteById,
  fetchNotes,
  isNote,
  isNoteListResponse,
} from '../notes';

// ---------------------------------------------------------------------------
// isNote
// ---------------------------------------------------------------------------

describe('isNote', () => {
  it('returns true for a valid Note', () => {
    expect(isNote({ id: 1, title: 'Hello', body: 'World' })).toBe(true);
  });

  it('returns true when extra fields are present', () => {
    expect(isNote({ id: 2, title: 'T', body: 'B', extra: true })).toBe(true);
  });

  it('returns false for null', () => {
    expect(isNote(null)).toBe(false);
  });

  it('returns false for a non-object primitive', () => {
    expect(isNote('string')).toBe(false);
    expect(isNote(42)).toBe(false);
  });

  it('returns false when id is not a number', () => {
    expect(isNote({ id: '1', title: 'T', body: 'B' })).toBe(false);
  });

  it('returns false when title is not a string', () => {
    expect(isNote({ id: 1, title: 99, body: 'B' })).toBe(false);
  });

  it('returns false when body is not a string', () => {
    expect(isNote({ id: 1, title: 'T', body: null })).toBe(false);
  });

  it('returns false when fields are missing', () => {
    expect(isNote({ id: 1, title: 'T' })).toBe(false);
    expect(isNote({ id: 1, body: 'B' })).toBe(false);
    expect(isNote({ title: 'T', body: 'B' })).toBe(false);
  });
});

// ---------------------------------------------------------------------------
// isNoteListResponse
// ---------------------------------------------------------------------------

describe('isNoteListResponse', () => {
  it('returns true for a valid response', () => {
    expect(
      isNoteListResponse({
        items: [{ id: 1, title: 'T', body: 'B' }],
        limit: 20,
        offset: 0,
      }),
    ).toBe(true);
  });

  it('returns true for an empty items array', () => {
    expect(isNoteListResponse({ items: [], limit: 20, offset: 0 })).toBe(true);
  });

  it('returns false when items is not an array', () => {
    expect(isNoteListResponse({ items: 'nope', limit: 20, offset: 0 })).toBe(
      false,
    );
  });

  it('returns false when an item in the array is not a Note', () => {
    expect(
      isNoteListResponse({
        items: [{ id: '1', title: 'T', body: 'B' }],
        limit: 20,
        offset: 0,
      }),
    ).toBe(false);
  });

  it('returns false when limit is missing', () => {
    expect(isNoteListResponse({ items: [], offset: 0 })).toBe(false);
  });

  it('returns false when offset is missing', () => {
    expect(isNoteListResponse({ items: [], limit: 20 })).toBe(false);
  });

  it('returns false for null', () => {
    expect(isNoteListResponse(null)).toBe(false);
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

describe('fetchNotes', () => {
  it('returns typed list on success', async () => {
    const payload = {
      items: [{ id: 1, title: 'T', body: 'B' }],
      limit: 20,
      offset: 0,
    };
    mockFetch(200, payload);

    const result = await fetchNotes();
    expect(result.items).toHaveLength(1);
    expect(result.items[0]?.title).toBe('T');
  });

  it('throws on HTTP error', async () => {
    mockFetch(500, {});
    await expect(fetchNotes()).rejects.toThrow('HTTP 500');
  });

  it('throws when response shape is wrong', async () => {
    mockFetch(200, { bad: true });
    await expect(fetchNotes()).rejects.toThrow('expected shape');
  });
});

describe('fetchNoteById', () => {
  it('returns a Note on success', async () => {
    mockFetch(200, { id: 5, title: 'Hi', body: 'There' });
    const note = await fetchNoteById(5);
    expect(note.id).toBe(5);
  });

  it('throws on HTTP error', async () => {
    mockFetch(404, {});
    await expect(fetchNoteById(99)).rejects.toThrow('HTTP 404');
  });

  it('throws when response shape is wrong', async () => {
    mockFetch(200, { surprise: true });
    await expect(fetchNoteById(1)).rejects.toThrow('expected shape');
  });
});

describe('createNote', () => {
  it('returns the created Note', async () => {
    mockFetch(201, { id: 10, title: 'New', body: 'Body' });
    const note = await createNote({ title: 'New', body: 'Body' });
    expect(note.id).toBe(10);
  });

  it('throws on HTTP error', async () => {
    mockFetch(422, {});
    await expect(createNote({ title: '', body: '' })).rejects.toThrow(
      'HTTP 422',
    );
  });
});
