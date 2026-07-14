// ドメイン型・branded ID・zod schema（z.infer が型の単一ソース — R1③）
import { z } from 'zod';

export type NoteId = number & { readonly __brand: 'NoteId' };

export const noteSchema = z.object({
  id: z.custom<NoteId>(
    (value) => typeof value === 'number' && Number.isInteger(value),
  ),
  title: z.string(),
  body: z.string(),
});
export type Note = z.infer<typeof noteSchema>;

// 一覧 UI 型（02 TY-5 の固定形 — total を持たない API は null で埋める）
export type NoteList = {
  items: Note[];
  limit: number;
  offset: number;
  total: number | null;
};

// list クエリのパラメータ型（query-keys.ts / queries.ts が参照）
export type NoteListParams = {
  limit?: number;
  offset?: number;
};
