<?php

namespace App\Console\Commands;

use App\Services\MailListImporter;
use Illuminate\Console\Command;

class LoadMailLists extends Command
{
    protected $signature = 'mail-lists:load {path? : A JSON file of lists (default database/data/mail-lists.json)}';

    protected $description = 'Load the organisation mailing lists from the data file; each list replaces its members, new ones are created';

    public function handle(MailListImporter $importer): int
    {
        $path = $this->argument('path') ?? database_path('data/mail-lists.json');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $result = $importer->importFile($path);

        $this->info("Loaded {$result['lists']} lists with {$result['members']} members.");

        return self::SUCCESS;
    }
}
