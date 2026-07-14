// container hook（02 UI-1: 判別ユニオン 3 値固定・error 腕に retry 同梱・所属は features）
import { useNoteList, type Note } from '@/entities/note';

export type ViewNotesState =
  | { status: 'loading' }
  | { status: 'error'; retry: () => void }
  | { status: 'success'; notes: Note[] };

export function useViewNotes(): ViewNotesState {
  const query = useNoteList({});
  if (query.isPending) return { status: 'loading' };
  if (query.isError) {
    return { status: 'error', retry: () => void query.refetch() };
  }
  return { status: 'success', notes: query.data.items };
}
