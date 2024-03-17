<?php

namespace App\Console\Commands\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Import extends Command
{
    use DatabaseManagementTrait;

    protected $signature = 'db:import';

    protected $description = 'Command description';

    public function handle()
    {
        /**
         * Prepare
         */
        DB::beginTransaction();
        static::prepare();
        $files = static::getDumpedFiles();

        /**
         * Gather necessary user input
         */
        if ($files->count() === 0) {
            $this->error("No dumps found.");
            return;
        }
        $this->table(['Timestamp', 'Path'], $files->map(fn($item) => [pathinfo(basename($item), PATHINFO_FILENAME), $item])->toArray());
        $latestTimestamp = pathinfo(basename($files->first() ?? ''), PATHINFO_FILENAME);
        $selected = $this->ask("Which timestamp should be imported (default: latest)?", $latestTimestamp);
        $path = static::getDumpPath($selected);
        if (!is_file($path)) {
            $this->error("File doesn't exist: {$path}");
            return;
        }
        $this->info("Importing: {$path}");

        /**
         * Start loading the dump
         */
        static::loadDump($path);

        /**
         * Either commit or roll back the database changes
         */
        $commit = $this->ask("Queries completed, commit transaction? (y/n)", 'n') === 'y';
        if ($commit === true) {
            $this->info("Committing changes...");
            DB::commit();
        } else {
            $this->info("Rolling back changes...");
            DB::rollBack();
        }
        $this->info("All done!");
    }
}
