<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateNotesTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('notes')
            ->addColumn('title', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('body', 'text', ['null' => false])
            ->create();
    }
}
