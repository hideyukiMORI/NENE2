// key ファクトリ（02 ST-4 — 裸配列 key の直書き MUST NOT）
import type { NoteId, NoteListParams } from './model';

export const noteKeys = {
  all: ['note'] as const,
  lists: () => [...noteKeys.all, 'list'] as const,
  list: (params: NoteListParams) => [...noteKeys.lists(), params] as const,
  details: () => [...noteKeys.all, 'detail'] as const,
  detail: (id: NoteId) => [...noteKeys.details(), id] as const,
};
