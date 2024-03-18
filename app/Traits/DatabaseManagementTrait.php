<?php

namespace App\Traits;

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
     * @param array|null $morphMap The morphMap structure: ["table1" => [ "column1" => "column1Renamed", "column2" => "column2Renamed" ] ]
     * @return Collection A Collection of column - column type items, grouped by table names.
     */
    public static function getDatabaseSchema(array|null $morphMap = null): Collection
    {
        return static::getTableNames()->mapWithKeys(function (string $table) use ($morphMap) {
            $columns = collect(Schema::getColumnListing($table))->mapWithKeys(function (string $column) use ($table) {
                return [$column => Schema::getColumnType($table, $column)];
            });
            if(!$morphMap) {
                return [$table => $columns];
            }
            // Get the morph map for the current table
            $tableMorphMap = $morphMap[$table] ?? null;
            if(!$tableMorphMap) {
                return [$table => collect()];
            }
            // Morph the schema
            $columns = $columns->filter(function(string $value, string $key) use ($tableMorphMap) {
                return key_exists($key, $tableMorphMap);
            })->mapWithKeys(function (string $value, string $key) use ($tableMorphMap) {
                $morphedKey = $tableMorphMap[$key] ?? null;
                return [$morphedKey => $value];
            });
            return [$table => $columns];
        });
    }

    /**
     * Returns a Collection of tables, and their respective records.
     * If the morphMap is defined, and a table doesn't exist, or is empty, no records will be morphed and assigned to it.
     * @param array|null $morphMap The morphMap structure: ["table1" => [ "column1" => "column1Renamed", "column2" => "column2Renamed" ] ]
     * @return Collection A Collection of records, grouped by table names.
     */
    public static function dumpDatabaseRecords(array|null $morphMap = null): Collection
    {
        $tables = static::getTableNames();
        return $tables->mapWithKeys(function (string $table) use ($morphMap) {
            $records = DB::table($table)->get();
            // If a morph map isn't defined, return the untouched models
            if(!$morphMap) {
                return [$table => $records];
            }
            // Get the morph map for the current table
            $tableMorphMap = $morphMap[$table] ?? null;
            if(!$tableMorphMap) {
                return [$table => collect()];
            }
            // Map each attribute key to its renamed key
            return [$table => $records->map(function ($record) use ($tableMorphMap) {
                $renamedAttributes = [];
                foreach ($record as $key => $value) {
                    $renamedAttributes[$tableMorphMap[$key] ?? $key] = $value;
                }
                return $renamedAttributes;
            })];
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
     * Dumps the database schema and every record into a single JSON file.
     * The file name is the current timestamp.
     * @param array|null $morphMap The morphMap structure: ["table1" => [ "column1" => "column1Renamed", "column2" => "column2Renamed" ] ]
     * @return string The created file's absolute path.
     */
    public static function saveDump(array|null $morphMap = null): string
    {
        $path = static::getDumpPath();
        $content = json_encode([
            'morph' => $morphMap,
            'schema' => static::getDatabaseSchema($morphMap),
            'records' => static::dumpDatabaseRecords($morphMap),
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
            return;
        }
        $retrySchema = [];
        foreach ($allowedSchema as $tableName => $columns) {
            $method = static::tryPurgingTable($tableName) === true ? 'insert' : 'update';
            $guessedPrimaryKey = static::guessPrimaryKey($columns);
            error_log("Loading data with method: {$method}(), guessed primary key: {$guessedPrimaryKey}");
            $tableContent = $content['records'][$tableName];

            /**
             * First try, if a series of inserts/updates fail, retry them afterward (for ex foreign constraint crashes)
             */
            try {
                foreach ($tableContent as $record) {
                    static::insertOrUpdateRecord($tableName, $columns, $guessedPrimaryKey, $record, $method);
                }
            } catch (\Throwable $exception) {
                error_log("Importing table `{$tableName}` failed, retrying later: " . $exception->getMessage());
                $retrySchema[$tableName] = $columns;
            }
        }

        /**
         * Retry importing crashing tables
         */
        if (count($retrySchema) <= 0) {
            error_log("Nothing to retry, skipping step (good).");
            return;
        }
        foreach ($retrySchema as $tableName => $columns) {
            $method = static::tryPurgingTable($tableName) === true ? 'insert' : 'update';
            $guessedPrimaryKey = static::guessPrimaryKey($columns);
            error_log("Retrying table `{$tableName}`, loading data with method: {$method}(), guessed primary key: {$guessedPrimaryKey}");
            $tableContent = $content['records'][$tableName];
            try {
                foreach ($tableContent as $record) {
                    static::insertOrUpdateRecord($tableName, $columns, $guessedPrimaryKey, $record, $method);
                }
            } catch (\Throwable $exception) {
                error_log("Retrying table `{$tableName}` failed: " . $exception->getMessage());
            }
        }
    }

    /**
     * Tries purging every record from the table, and returns an insert/update method.
     */
    public static function tryPurgingTable(string $table): bool
    {
        try {
            $deleted = DB::table($table)->delete();
            error_log("Purged {$deleted} records from `{$table}`, inserting new records...");
            return true;
        } catch (\Throwable $th) {
            error_log("Failed to purge table `{$table}` (updating the records instead): " . $th->getMessage());
        }
        return false;
    }

    /**
     * Very dumb primary key guesser without context.
     * The guesses are: `id` -> `slug` -> `uuid` -> `created_at` -> `array_key_first($columns)`
     */
    public static function guessPrimaryKey(array $columns): string|null
    {
        return in_array('id', $columns) ? 'id' : (in_array('slug', $columns) ? 'slug' : (in_array('uuid', $columns) ? 'uuid' : (in_array('created_at', $columns) ? 'created_at' : array_key_first($columns))));
    }

    public static function insertOrUpdateRecord(string $table, array $columnSchema, string $primaryKey, array $record, string $method): void
    {
        // Filter data and insert/update it
        $filteredRecord = array_filter(
            $record,
            function ($key) use ($columnSchema) {
                return key_exists($key, $columnSchema);
            },
            ARRAY_FILTER_USE_KEY
        );
        $query = DB::table($table);
        if ($method === 'update') {
            $query->where($primaryKey, '=', $filteredRecord[$primaryKey] ?? null);
        }
        $query->$method($filteredRecord);
    }

}