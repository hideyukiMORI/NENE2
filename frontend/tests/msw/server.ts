// MSW central server（R2⑧ — per-test の差し替えは server.use()）。
// entity の handlers.ts を直 import して合成する（01 1-4: これが handlers の唯一の
// slice 外消費経路 — index.ts からは export しない）。
import { setupServer } from 'msw/node';

import { noteHandlers } from '@/entities/note/handlers';
// [nene2-gen:handler-imports] — gen:entity がこの行の上に追記する

export const server = setupServer(
  ...noteHandlers,
  // [nene2-gen:handlers] — gen:entity がこの行の上に追記する
);
