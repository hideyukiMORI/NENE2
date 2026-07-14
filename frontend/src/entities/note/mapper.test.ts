import { describe, expect, it } from 'vitest';

import { noteDtoFixture, noteListDtoFixture } from './handlers';
import { mapNoteDtoToModel, mapNoteListDtoToModel } from './mapper';

describe('mapNoteDtoToModel', () => {
  it('maps dto to domain', () => {
    expect(mapNoteDtoToModel(noteDtoFixture)).toMatchObject({
      id: noteDtoFixture.id,
      title: noteDtoFixture.title,
      body: noteDtoFixture.body,
    });
  });
});

describe('mapNoteListDtoToModel', () => {
  it('maps list dto to domain list (total 欠落は null)', () => {
    const list = mapNoteListDtoToModel(noteListDtoFixture);
    expect(list.items).toHaveLength(noteListDtoFixture.items.length);
    expect(list.limit).toBe(noteListDtoFixture.limit);
    expect(list.offset).toBe(noteListDtoFixture.offset);
    expect(list.total).toBeNull();
  });
});
