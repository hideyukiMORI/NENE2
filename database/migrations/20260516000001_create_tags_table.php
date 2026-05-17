<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateTagsTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('tags')
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->create();
    }
}
