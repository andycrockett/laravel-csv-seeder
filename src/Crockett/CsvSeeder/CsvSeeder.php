<?php

namespace Crockett\CsvSeeder;

use DB;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class CsvSeeder extends Seeder
{

    /**
     * DB table name
     *
     * @var string
     */
    public $table;

    /**
     * Model name
     *
     * @var string
     */
    public $model;

    /**
     * Limit mapping columns to what is specified by the $fillable/$guarded model attributes
     *
     * @var bool
     */
    public $model_guard = true;

    /**
     * CSV filename
     *
     * @var string
     */
    public $filename;

    /**
     * CSV delimiter
     *
     * @var string
     */
    public $delimiter = ',';

    /**
     * Number of rows to skip at the start of the CSV.
     *
     * @var int
     */
    public $offset_rows = 0;

    /**
     * Automatically skips the first row of the CSV -
     * Only when the first row's column values are equal to the column names resolved (or defined) in $mapping.
     *
     * Setting $offset_rows to anything higher than 0 bypasses this.
     *
     * If you know your CSV doesn't have headers it's somewhat safer
     * to disable this, but generally it shouldn't affect anything.
     *
     * @var bool
     */
    public $skip_header_row = true;

    /**
     * CSV header name mapping -
     * An array that uses the CSV column index as the array key and a column name as the array value.
     * Column names do not have to match the CSV headers.
     *
     * Using the indexes, you can specify which columns to read from the CSV and which to ignore.
     * For example, to only read the first, third, and fourth columns from your CSV:
     * [
     *     0 => 'first_column_name',
     *     2 => 'third_column_name',
     *     3 => 'fourth_column_name',
     * ];
     *
     * @var array
     */
    public $mapping = [];

    /**
     * Aliases CSV headers to differently named DB columns.
     *
     * Useful when $this->mapping is being resolved automatically and you're
     * reading a CSV with headers named differently from your DB columns.
     *
     * For example, to alias a CSV column named "email_address" to a DB column named "email":
     * [
     *    'email_address' => 'email'
     *    // 'csv_header' => 'alias_name'
     * ];
     *
     * @var array
     */
    public $aliases = [];

    /**
     * Specifies DB columns that should have their values hashed prior to insertion.
     * Override this as needed.
     *
     * If you set any $aliases, be sure to use the aliased DB column name.
     *
     * @var array|string
     */

    public $hashable = 'password';

    /**
     * A SQL INSERT query will execute every time this number of rows are read from the CSV.
     * Without this, large INSERTS will fail silently.
     *
     * @var int
     */
    public $insert_chunk_size = 50;

    /**
     * A closure that takes an array of CSV rows ($chunk) and inserts them into the DB.
     * Use to override the default insertion behavior.
     *
     * Example:
     *     function ($chunk) {
     *         // insert $rows individually with model::create()
     *         foreach($chunk as $row) {
     *             YourModel::create($row);
     *         }
     *     }
     *
     * @var closure|callable|null
     */
    public $insert_callback = null;

    /**
     * Show messages in the console
     *
     * @var bool
     */
    public $console_logs = true;

    /**
     * Write messages to laravel.log
     *
     * @var bool
     */
    public $write_logs = false;

    /**
     * Enables or disables query logging. Recommended for large CSVs.
     *
     * @var bool
     */
    public $disable_query_log = true;

    /**
     * The prefix for log messages
     *
     * @var string
     */
    public $log_prefix = '';

    /**
     * Array of columns to encrypt using native Laravel encryption
     *
     * @var array
     */

    public $insert_encrypt = [];

    /**
     * Holder for columns read from the DB table
     *
     * @var array
     */
    private $table_columns;

    /**
     * CsvSeeder constructor.
     *
     * @param null $filename
     * @param null $table
     * @param null $model
     * @param string $delimiter
     * @param null $mapping
     * @param null $aliases
     * @param null $insert_callback
     * @param null $insert_encrypt
     */
    public function __construct(
        $filename = null,
        $table = null,
        $model = null,
        $delimiter = null,
        $mapping = null,
        $aliases = null,
        $insert_callback = null,
        $insert_encrypt = null
    ) {
        if ( ! is_null($filename)) {
            $this->seedFromCSV($filename, $table, $model, $delimiter, $mapping, $aliases, $insert_callback,
                $insert_encrypt);
        }
    }

    /**
     * Run DB seed
     */
    public function run()
    {
        $this->runSeeder();
    }

    /**
     * @param null $filename
     * @param null $table
     * @param null $model
     * @param string $delimiter
     * @param null $aliases
     * @param null $mapping
     * @param null $insert_callback
     * @param null $insert_encrypt
     */
    public function seedFromCSV(
        $filename = null,
        $table = null,
        $model = null,
        $delimiter = null,
        $aliases = null,
        $mapping = null,
        $insert_callback = null,
        $insert_encrypt = null
    ) {
        $this->filename        = $filename ?: $this->filename;
        $this->table           = $table ?: $this->table;
        $this->model           = $model ?: $this->model;
        $this->delimiter       = $delimiter ?: $this->delimiter;
        $this->aliases         = $aliases ?: $this->aliases;
        $this->mapping         = $mapping ?: $this->mapping;
        $this->insert_callback = $insert_callback ?: $this->insert_callback;
        $this->insert_encrypt  = $insert_encrypt ?: $this->insert_encrypt;

        $this->runSeeder();
    }

    public function runSeeder()
    {
        // abort for missing filename
        if (empty($this->filename)) {
            $this->log('CSV filename was not specified.', 'critical');
            $this->console('CSV filename was not specified.', 'error');

            return;
        }

        // resolve the model
        if ( ! empty($this->model)) {
            if ($this->resolveModel() === false) {
                $this->log("$this->model could not be resolved.", 'warning');
                $this->console("$this->model could not be resolved.", 'error');
                // continue, despite the model
            }
        }

        // resolve the table name or abort
        if ($this->resolveTable() === false) {
            $this->log("Table could not be resolved or does not exist.", 'warning');
            $this->console('Table could not be resolved or does not exist. Try setting it manually.', 'error');

            return;
        }

        // update the log_prefix with the table name
        $this->log_prefix = $this->log_prefix . "$this->table: ";

        // load the allowed table columns or abort if there are none
        if ($this->resolveTableColumns() === false) {
            $this->log('Unable to resolve DB columns', 'critical');
            $this->console('Unable to resolve DB columns.', 'error');

            return;
        };

        // convert hashable to array
        if (is_string($this->hashable)) {
            $this->hashable = [$this->hashable];
        }

        // disable query log
        if ($this->disable_query_log) {
            DB::disableQueryLog();
        }

        // read and parse the CSV, seeding the database
        $this->parseCSV();

        // reset seeder for another run
        $this->resetSeeder();
    }

    /**
     * Reset the seeder for another use
     */
    public function resetSeeder()
    {
        $this->filename    = null;
        $this->model       = null;
        $this->table       = null;
        $this->aliases     = [];
        $this->mapping     = [];
        $this->hashable    = [];
        $this->delimiter   = ',';
        $this->offset_rows = 0;
        $this->log_prefix  = '';

        $this->insert_chunk_size = 50;
        $this->insert_callback   = null;
    }

    /**
     * Opens a CSV file and returns it as a resource
     *
     * @param $filename
     *
     * @return FALSE|resource
     */
    public function openCSV($filename)
    {
        if ( ! file_exists($filename) || ! is_readable($filename)) {
            return false;
        }

        // check if file is gzipped
        $file_info      = finfo_open(FILEINFO_MIME_TYPE);
        $file_mime_type = finfo_file($file_info, $filename);
        finfo_close($file_info);
        $gzipped = strcmp($file_mime_type, "application/x-gzip") == 0;

        $handle = $gzipped ? gzopen($filename, 'r') : fopen($filename, 'r');

        return $handle;
    }

    /**
     * Parse rows from the CSV and pass chunks of rows to the insert function
     */
    public function parseCSV()
    {
        $handle = $this->openCSV($this->filename);

        // abort for bad CSV
        if ($handle === false) {
            $this->console("CSV file {$this->filename} does not exist or is not readable.", 'error');

            return;
        }

        $row_count = 0;
        $skipped   = 0; // rows that were skipped
        $failed    = 0; // chunk inserts that failed
        $chunk     = new Collection(); // accumulator for rows until the chunk_limit is reached
        $mapping   = empty($this->mapping) ? [] : $this->cleanMapping($this->mapping);
        $encrypt   = empty($this->insert_encrypt) ? [] : $this->insert_encrypt;
        $offset    = $this->offset_rows;

        while (($row = fgetcsv($handle, 0, $this->delimiter)) !== false) {

            if ($row_count == 0 && $offset == 0) {
                // Resolve mapping from the first row
                if (empty($mapping)) {
                    $mapping = $this->cleanMapping($row);
                }

                // Automagically skip the header row
                if ( ! empty($mapping) && $this->skip_header_row) {
                    if ($this->isHeaderRow($row, $mapping)) {
                        $offset++;
                    }
                }
            }

            // Skip the offset rows
            while ($offset > 0) {
                $offset--;
                continue 2;
            }

            // Resolve mapping using the first row after offset
            if (empty($mapping)) {
                $mapping = $this->cleanMapping($row);
                // abort if mapping empty
                if (empty($mapping)) {
                    $this->console("The mapping columns do not exist on the DB table.", 'error');

                    return;
                }
            }

            $row = $this->parseRow($row, $mapping, $encrypt);

            // Insert only non-empty rows from the csv file
            if ($row->isEmpty()) {
                $skipped++;
                continue;
            }

            $chunk->push($row);

            // Chunk size reached, insert and clear the chunk
            if (count($chunk) >= $this->insert_chunk_size) {
                if ( ! $this->insert($chunk)) {
                    $failed++;
                }
                $chunk = new Collection();
            }

            $row_count++;
        }

        // convert failed chunks to failed rows
        $failed = $failed * $this->insert_chunk_size;

        // Insert any leftover rows from the last chunk
        if (count($chunk) > 0) {
            if ( ! $this->insert($chunk)) {
                $failed += count($chunk);
            }
        }

        fclose($handle);

        // log results to console
        $log = 'Imported ' . ($row_count - $skipped - $failed) . ' of ' . $row_count . ' rows. ';
        if ($skipped > 0) {
            $log .= $skipped . " empty rows. ";
        }
        if ($failed > 0) {
            $log .= "<error>" . $failed . " failed rows.</error>";
        }

        $this->console($log);
    }

    /**
     * Insert a chunk of rows into the DB
     *
     * @param Collection $chunk
     *
     * @return bool   TRUE on success else FALSE
     */
    public function insert(Collection $chunk)
    {
        $callback = $this->getInsertCallback();

        try {
            call_user_func($callback, $chunk);
        } catch (\Exception $e) {
            $this->log("Chunk insert failed:\n" . $e->getMessage(), 'critical');

            return false;
        }

        return true;
    }

    /**
     * Resolve the function that inserts chunks into the database or returns the default behavior.
     *
     * @returns closure|callable
     */
    public function getInsertCallback()
    {
        return is_object($this->insert_callback)
            ? $this->insert_callback
            : function (Collection $chunk) {
                if (empty($this->model)) {
                    // use DB table insert method
                    DB::table($this->table)->insert($chunk->toArray());
                } else {
                    // use model insert method
                    $model = $this->resolveModel();
                    $model->insert($chunk->toArray());
                }
            };
    }

    /**
     * Strips UTF-8 BOM characters from a string
     *
     * @param $string
     *
     * @return string
     */
    public function stripUtf8Bom($string)
    {
        $bom    = pack('H*', 'EFBBBF');
        $string = preg_replace("/^$bom/", '', $string);

        return $string;
    }

    /**
     * Truncate a table (optionally ignore foreign keys)
     */
    public function truncateTable($ignore_foreign_keys = false)
    {
        if (empty($this->table)) {
            if ($this->resolveTable() === false) {
                $this->log('Unable to truncate table: Table not specified.', 'warning');
                $this->console('Unable to truncate table: Table not specified.', 'error');

                return;
            };
        }

        if ($ignore_foreign_keys) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        }

        DB::table($this->table)->truncate();

        if ($ignore_foreign_keys) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }

    /**
     * Check if the column values in $row are the same as the column names in $mapping.
     */
    protected function isHeaderRow(array $row, array $mapping)
    {
        $is_header_row = true;

        foreach ($mapping as $index => $column) {
            if (array_key_exists($index, $row)) {
                if ($row[$index] != $column) {
                    $is_header_row = false;
                }
            }
        }

        return $is_header_row;
    }

    /**
     * Parse a CSV row into a DB insertable array
     *
     * @param array $row List of CSV columns
     * @param array $mapping Array of csvCol => dbCol
     * @param array $encrypt - Array of column names to encrypt
     *
     * @return Collection
     */
    protected function parseRow(array $row, array $mapping, array $encrypt)
    {
        $columns = new Collection();
        // apply mapping to a given row
        foreach ($mapping as $csv_index => $column_name) {
            $column_value = (array_key_exists($csv_index, $row) && isset($row[$csv_index]) && $row[$csv_index] !== '')
                ? $row[$csv_index]
                : null;

            if ( ! empty($encrypt)) {

                if (in_array($column_name, $encrypt)) {
                    $column_value = encrypt($column_value);
                }
            }
            $columns->put($column_name, $column_value);
        }

        $columns = $this->aliasColumns($columns);

        $columns = $this->hashColumns($columns);

        return $columns;
    }

    /**
     * Remove columns in the mapping that don't exist in the DB table
     *
     * @param array $mapping
     *
     * @return array
     */
    protected function cleanMapping(array $mapping)
    {
        $columns    = $mapping;
        $columns[0] = $this->stripUtf8Bom($columns[0]);

        // Cull columns that don't exist in the database or were guarded by the model
        foreach ($columns as $index => $column) {
            // apply column alias
            $column = $this->aliasColumn($column);
            if (array_search($column, $this->table_columns) === false) {
                array_pull($columns, $index);
            }
        }

        return $columns;
    }

    /**
     * Apply alias to a single column
     */
    protected function aliasColumn($column)
    {
        return is_array($this->aliases) && array_key_exists($column, $this->aliases)
            ? $this->aliases[$column]
            : $column;
    }

    /**
     * Apply aliases to a group of columns
     */
    protected function aliasColumns(Collection $columns)
    {
        if (is_array($this->aliases) && ! empty($this->aliases)) {
            foreach ($this->aliases as $csv_column => $alias_column) {
                if ($columns->contains($csv_column)) {
                    $columns->put($alias_column, $columns->get($csv_column));
                    $columns->pull($csv_column);
                }
            }
        }

        return $columns;
    }

    /**
     * Hash any hashable columns
     */
    protected function hashColumns(Collection $columns)
    {
        if (is_array($this->hashable) && ! empty($this->hashable)) {
            foreach ($this->hashable as $hashable) {
                if ($columns->has($hashable)) {
                    $columns->put($hashable, bcrypt($columns[$hashable]));
                }
            }
        }
        return $columns;
    }

    /**
     * Apply model attributes like $fillable and $guarded to an array of columns
     *
     * @param array $columns
     *
     * @return array
     */
    protected function guardColumns(array $columns)
    {
        if ( ! $this->model_guard || empty($this->model)) {
            return $columns;
        }

        $model = $this->resolveModel();

        // filter out columns not allowed by the $fillable attribute
        if (method_exists($model, 'getFillable')) {
            if ( ! empty($fillable = $model->getFillable())) {
                foreach ($columns as $index => $column) {
                    if (array_search($column, $fillable) === false) {
                        array_pull($columns, $index);
                    }
                }
            };
        }

        return $columns;
    }

    /**
     * Returns a new model instance
     */
    protected function resolveModel($parameters = [])
    {
        try {
            $model = app($this->model, $parameters);
        } catch (\Exception $e) {
            return false;
        }

        return $model;
    }

    /**
     * Tries to resolve the table name using the filename
     */
    protected function resolveTable()
    {
        // try to resolve using model
        if (empty($this->table) && ! empty($this->model)) {
            $model = $this->resolveModel();
            if ($model !== false) {
                $this->table = method_exists($model, 'getTable')
                    ? $model->getTable()
                    : null;
            }
        }

        // try to resolve using filename
        if (empty($this->table) && ! empty($this->filename)) {
            $file = explode('/', $this->filename);
            $file = explode('.', $file[count($file) - 1]);

            $this->table = $file[0];

            $this->console('Table name "' . $this->table . '" resolved from CSV filename');
        }

        return DB::getSchemaBuilder()->hasTable($this->table);
    }

    /**
     * Resolves allowed columns for the table. Applies model guard if available.
     */
    protected function resolveTableColumns()
    {
        // get every column that exists on the table
        $columns = DB::getSchemaBuilder()->getColumnListing($this->table);

        // Run the model guard on the columns
        $columns = $this->guardColumns($columns);

        $this->table_columns = $columns;

        return ! empty($columns);
    }

    /**
     * Show a message in the console
     */
    protected function console($message, $style = null)
    {
        if ($this->console_logs === false) {
            return;
        }

        $message = $style ? "<$style>$message</$style>" : $message;

        $this->command->line('<info>CSVSeeder: </info>' . $this->log_prefix . $message);
    }

    /**
     * Write a message to the logs using Laravel's Log helper
     */
    protected function log($message, $level = 'info')
    {
        if ($this->write_logs === false) {
            return;
        }

        logger()->log($level, 'CSVSeeder: ' . $this->log_prefix . $message);
    }
}