<?php

namespace App\Console\Commands\Database;

use Illuminate\Console\Command;

class Export extends Command
{
    use DatabaseManagementTrait;

    protected $signature = 'db:export';

    protected $description = 'Command description';

    public function handle()
    {
        static::prepare();
        $path = static::saveDump();
        $this->info("Dump saved: {$path}");
    }
}
