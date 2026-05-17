const defaultApiBaseUrl = '/api';

function apiBase(): string {
  return (
    (import.meta.env.VITE_NENE2_API_BASE_URL as string | undefined) ??
    defaultApiBaseUrl
  );
}

export type Note = {
  readonly id: number;
  readonly title: string;
  readonly body: string;
};

export type NoteListResponse = {
  readonly items: readonly Note[];
  readonly limit: number;
  readonly offset: number;
};

export type CreateNoteRequest = {
  readonly title: string;
  readonly body: string;
};

function isNote(value: unknown): value is Note {
  if (typeof value !== 'object' || value === null) return false;
  const c = value as Record<string, unknown>;
  return (
    typeof c.id === 'number' &&
    typeof c.title === 'string' &&
    typeof c.body === 'string'
  );
}

function isNoteListResponse(value: unknown): value is NoteListResponse {
  if (typeof value !== 'object' || value === null) return false;
  const c = value as Record<string, unknown>;
  return (
    Array.isArray(c.items) &&
    c.items.every(isNote) &&
    typeof c.limit === 'number' &&
    typeof c.offset === 'number'
  );
}

export async function fetchNotes(
  limit = 20,
  offset = 0,
): Promise<NoteListResponse> {
  const url = `${apiBase()}/examples/notes?limit=${limit}&offset=${offset}`;
  const response = await fetch(url, {
    headers: { Accept: 'application/json' },
  });

  if (!response.ok) {
    throw new Error(`Failed to fetch notes: HTTP ${response.status}.`);
  }

  const payload: unknown = await response.json();

  if (!isNoteListResponse(payload)) {
    throw new Error('Note list response did not match the expected shape.');
  }

  return payload;
}

export async function fetchNoteById(id: number): Promise<Note> {
  const response = await fetch(`${apiBase()}/examples/notes/${id}`, {
    headers: { Accept: 'application/json' },
  });

  if (!response.ok) {
    throw new Error(`Failed to fetch note ${id}: HTTP ${response.status}.`);
  }

  const payload: unknown = await response.json();

  if (!isNote(payload)) {
    throw new Error('Note response did not match the expected shape.');
  }

  return payload;
}

export async function createNote(input: CreateNoteRequest): Promise<Note> {
  const response = await fetch(`${apiBase()}/examples/notes`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify(input),
  });

  if (!response.ok) {
    throw new Error(`Failed to create note: HTTP ${response.status}.`);
  }

  const payload: unknown = await response.json();

  if (!isNote(payload)) {
    throw new Error('Create note response did not match the expected shape.');
  }

  return payload;
}
