function apiBase(): string {
  return (
    (import.meta.env.VITE_NENE2_API_BASE_URL as string | undefined) ?? '/api'
  );
}

export type Tag = {
  readonly id: number;
  readonly name: string;
};

export type TagListResponse = {
  readonly items: readonly Tag[];
  readonly limit: number;
  readonly offset: number;
};

export type CreateTagRequest = {
  readonly name: string;
};

export type UpdateTagRequest = {
  readonly name: string;
};

export function isTag(value: unknown): value is Tag {
  if (typeof value !== 'object' || value === null) return false;
  const c = value as Record<string, unknown>;
  return typeof c.id === 'number' && typeof c.name === 'string';
}

export function isTagListResponse(value: unknown): value is TagListResponse {
  if (typeof value !== 'object' || value === null) return false;
  const c = value as Record<string, unknown>;
  return (
    Array.isArray(c.items) &&
    c.items.every(isTag) &&
    typeof c.limit === 'number' &&
    typeof c.offset === 'number'
  );
}

export async function fetchTags(
  limit = 20,
  offset = 0,
): Promise<TagListResponse> {
  const url = `${apiBase()}/examples/tags?limit=${limit}&offset=${offset}`;
  const response = await fetch(url, {
    headers: { Accept: 'application/json' },
  });

  if (!response.ok) {
    throw new Error(`Failed to fetch tags: HTTP ${response.status}.`);
  }

  const payload: unknown = await response.json();

  if (!isTagListResponse(payload)) {
    throw new Error('Tag list response did not match the expected shape.');
  }

  return payload;
}

export async function createTag(input: CreateTagRequest): Promise<Tag> {
  const response = await fetch(`${apiBase()}/examples/tags`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(input),
  });

  if (!response.ok) {
    throw new Error(`Failed to create tag: HTTP ${response.status}.`);
  }

  const payload: unknown = await response.json();

  if (!isTag(payload)) {
    throw new Error('Create tag response did not match the expected shape.');
  }

  return payload;
}

export async function updateTag(
  id: number,
  input: UpdateTagRequest,
): Promise<Tag> {
  const response = await fetch(`${apiBase()}/examples/tags/${id}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(input),
  });

  if (!response.ok) {
    throw new Error(`Failed to update tag ${id}: HTTP ${response.status}.`);
  }

  const payload: unknown = await response.json();

  if (!isTag(payload)) {
    throw new Error('Update tag response did not match the expected shape.');
  }

  return payload;
}

export async function deleteTag(id: number): Promise<void> {
  const response = await fetch(`${apiBase()}/examples/tags/${id}`, {
    method: 'DELETE',
    headers: { Accept: 'application/json' },
  });

  if (!response.ok) {
    throw new Error(`Failed to delete tag ${id}: HTTP ${response.status}.`);
  }
}
