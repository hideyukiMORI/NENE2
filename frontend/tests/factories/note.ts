// テストデータファクトリ（R2⑧ — exemplar: nene-invoice/frontend/tests/factories）
import type { Note, NoteId } from '@/entities/note';

let sequence = 0;

export function makeNote(overrides: Partial<Note> = {}): Note {
  sequence += 1;
  return {
    id: sequence as NoteId,
    title: `Note ${String(sequence)}`,
    body: `Body of note ${String(sequence)}`,
    ...overrides,
  };
}
