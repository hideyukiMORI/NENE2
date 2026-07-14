// openapi 生成型の import 面（これ以外を書かない — 02 TY-1。手書きは生成型の別名付けのみ）
import type { components } from '@/shared/api/schema.gen';

export type NoteDto = components['schemas']['ExampleNoteResponse'];
export type NoteListDto = components['schemas']['ExampleNoteListResponse'];
