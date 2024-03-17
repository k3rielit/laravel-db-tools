<?php

namespace App\Console\Commands\Database;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

trait DatabaseManagementTrait
{

    /**
     * A list of tables to skip, like telescops's multi-gigabyte logs.
     */
    public static array $tableBlacklist = [
        'telescope_entries',
        'telescope_entries_tags',
        'telescope_monitoring',
    ];

    /**
     * Prepare the environment, mainly the current php configuration.
     */
    public static function prepare(): void
    {
        $config = [
            'max_execution_time' => '0',
            'memory_limit' => '8000M',
        ];
        foreach ($config as $key => $value) {
            ini_set($key, $value);
        }
    }

    /**
     * Returns a Collection of table names.
     */
    public static function getTableNames(): Collection
    {
        return collect(Schema::getConnection()->getDoctrineSchemaManager()->listTableNames())->filter(fn(string $item) => !in_array($item, static::$tableBlacklist));
    }

    /**
     * Dumps the tables, columns, and column types into a Collection.
     */
    public static function getDatabaseSchema(): Collection
    {
        $tables = static::getTableNames()->mapWithKeys(function (string $table) {
            $columns = collect(Schema::getColumnListing($table))->mapWithKeys(function (string $column) use ($table) {
                return [$column => Schema::getColumnType($table, $column)];
            });
            return [$table => $columns];
        });
        return $tables;
    }

    /**
     * Returns a Collection of tables, and their respective records.
     */
    public static function dumpDatabaseRecords(): Collection
    {
        $tables = static::getTableNames();
        return $tables->mapWithKeys(function (string $table) {
            return [$table => DB::table($table)->get()];
        });
    }

    /**
     * Returns the path for a new/existing dump file.
     */
    public static function getDumpPath(string|int|null $timestamp = null): string
    {
        $directory = storage_path('dump');
        if (!file_exists($directory)) {
            mkdir($directory);
        }
        $file = ($timestamp ? $timestamp : Carbon::now()->getTimestamp()) . '.json';
        touch($file);
        return implode('/', [$directory, $file]);
    }

    /**
     * Returns a list of dumps in the filesystem.
     */
    public static function getDumpedFiles(): Collection
    {
        $directory = storage_path('dump');
        return collect(File::files($directory))->filter(fn($item) => \Str::endsWith($item, '.json'))->sortDesc();
    }

    /**
     * Dumps the database schema and all of the records into a single JSON file.
     * The file name is the current timestamp.
     */
    public static function saveDump(): string
    {
        $path = static::getDumpPath();
        $content = json_encode([
            'schema' => static::getDatabaseSchema(),
            'records' => static::dumpDatabaseRecords(),
        ]);
        file_put_contents($path, [$content]);
        return $path;
    }

    public static function loadDump(string|null $path): void
    {
        if (!$path || !is_file($path)) {
            return;
        }
        $content = json_decode(file_get_contents($path), true);
        $currentSchema = static::getDatabaseSchema()->toArray();
        $loadedSchema = $content['schema'] ?? [];
        $allowedSchema = [];

        /**
         * Process the loaded and current schema in pairs to determine the insertable data structure
         */
        foreach ($loadedSchema as $tableName => $loadedColumns) {
            // Check whether the table exists in the current database
            if (!key_exists($tableName, $currentSchema)) {
                error_log("Missing table `{$tableName}` from current schema, skipping.");
                continue;
            }
            $currentColumns = $currentSchema[$tableName] ?? null;
            $allowedColumns = [];
            foreach ($loadedColumns as $columnName => $loadedColumnType) {
                $currentColumnType = $currentColumns[$columnName] ?? null;
                // Check whether the loaded column value is insertable into the current one by the current type
                if (key_exists($columnName, $currentColumns) && in_array($currentColumnType, [$loadedColumnType, 'text', 'string'])) {
                    $allowedColumns[$columnName] = $currentColumnType;
                }
            }
            if (count($allowedColumns) > 0) {
                $allowedSchema[$tableName] = $allowedColumns;
            }
        }

        /**
         * Process the allowed schema
         */
        if (count($allowedSchema) <= 0) {
            error_log("No common schema was found, importing is impossible.");
        }
        foreach ($allowedSchema as $tableName => $columns) {
            // Delete previous data
            $purgedTable = false;
            try {
                $deleted = DB::table($tableName)->delete();
                $purgedTable = true;
                error_log("Purged {$deleted} records from `{$tableName}`, inserting new records...");
            } catch (\Throwable $th) {
                error_log("Failed to purge table `{$tableName}` (updating the records instead): " . $th->getMessage());
            }
            // Insert or update values
            $method = $purgedTable === true ? 'insert' : 'update';
            $guessedPrimaryKey = in_array('id', $columns) ? 'id' : (in_array('slug', $columns) ? 'slug' : (in_array('uuid', $columns) ? 'uuid' : (in_array('created_at', $columns) ? 'created_at' : array_key_first($columns))));
            error_log("Loading data with method: {$method}(), guessed primary key: {$guessedPrimaryKey}");
            $tableContent = $content['records'][$tableName];
            foreach ($tableContent as $record) {
                $query = DB::table($tableName);
                if ($method === 'update') {
                    $query->where($guessedPrimaryKey, '=', $record[$guessedPrimaryKey] ?? null);
                }
                $query->$method($record);
            }
        }
    }

}