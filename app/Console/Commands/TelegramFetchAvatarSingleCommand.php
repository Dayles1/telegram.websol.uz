<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TelegramAvatarFetcher;

class TelegramFetchAvatarSingleCommand extends Command
{
    protected $signature = 'telegram:fetch-avatar {phoneId}';
    protected $description = 'Start Madeline for a single phone id, download profile photo and update User->avatar';

    protected TelegramAvatarFetcher $fetcher;

    public function __construct(TelegramAvatarFetcher $fetcher)
    {
        parent::__construct();
        $this->fetcher = $fetcher;
    }

    public function handle()
    {
        $phoneId = (int)$this->argument('phoneId');
        $result = $this->fetcher->fetchByPhoneId($phoneId);

        if ($result['success']) {
            $this->info("✅ " . ($result['message'] ?? 'OK') . " -> " . ($result['path'] ?? ''));
            return 0;
        }

        $this->error("❌ " . ($result['message'] ?? 'Error'));
        return 1;
    }
}
