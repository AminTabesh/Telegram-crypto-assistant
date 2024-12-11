<?php

// app/Console/Commands/CheckChannelUpdates.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\TelegramController;

class CheckChannelUpdates extends Command
{
    protected $signature = 'telegram:check-channel';
    protected $description = 'Check for new messages in the Telegram channel';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        // Call your controller method to check the channel
        app(TelegramController::class)->checkChannelForMessages();

        $this->info('Checked for new messages in the channel.');
    }
}
