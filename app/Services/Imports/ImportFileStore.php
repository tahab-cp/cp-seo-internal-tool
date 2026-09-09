<?php

namespace App\Services\Imports;

use App\Exceptions\CsvImportException;
use App\Models\ImportBatch;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Keeps uploaded CSVs on the private import disk (config/imports.php).
 * Paths are generated here, never taken from user input, and files are
 * only ever read back through the batch record.
 */
class ImportFileStore
{
    public function disk(): Filesystem
    {
        return Storage::disk(config('imports.disk'));
    }

    public function store(UploadedFile $file, ImportBatch $batch): string
    {
        $directory = trim((string) config('imports.directory'), '/').'/'.(int) $batch->project_id;
        $name = $batch->getKey().'-'.Str::lower(Str::random(16)).'.csv';

        $path = $this->disk()->putFileAs($directory, $file, $name);

        if ($path === false) {
            throw new CsvImportException('The CSV file could not be stored.');
        }

        return $path;
    }

    /**
     * Absolute local path for the native CSV parser. Remote disks are
     * copied to a temporary file first.
     */
    public function localPath(ImportBatch $batch): string
    {
        $stored = (string) $batch->stored_file_path;

        if ($stored === '' || ! $this->disk()->exists($stored)) {
            throw new CsvImportException('The uploaded CSV file is no longer available; upload it again.');
        }

        $adapter = $this->disk();

        if (method_exists($adapter, 'path') && config('filesystems.disks.'.config('imports.disk').'.driver') === 'local') {
            return $adapter->path($stored);
        }

        $temporary = tempnam(sys_get_temp_dir(), 'import-');
        file_put_contents($temporary, $adapter->get($stored));

        return $temporary;
    }

    public function safeOriginalName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));
        $name = preg_replace('/[^\w .()\-]+/u', '_', $name) ?? 'upload.csv';

        return Str::limit(trim($name) === '' ? 'upload.csv' : $name, 200, '');
    }
}
