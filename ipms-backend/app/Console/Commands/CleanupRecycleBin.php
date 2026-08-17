<?php

namespace App\Console\Commands;

use App\Models\ApiDocument;
use App\Models\Document;
use App\Models\Folder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupRecycleBin extends Command
{
    protected $signature = 'recycle:cleanup {--days=30 : Days to keep documents in recycle bin}';
    protected $description = 'Permanently delete expired recycle bin documents';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));

        // Documents
        $documents = Document::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->get();
        $docCount = 0;
        foreach ($documents as $doc) {
            if ($doc->file) {
                Storage::delete($doc->file);
            }
            $doc->forceDelete();
            $docCount++;
        }

        // API Documents
        $apiDocCount = ApiDocument::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->forceDelete();

        // Folders
        $folderCount = Folder::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->forceDelete();

        $this->info("Cleaned up: {$docCount} documents, {$apiDocCount} API docs, {$folderCount} folders.");

        return self::SUCCESS;
    }
}
