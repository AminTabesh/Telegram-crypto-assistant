<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateTelegramMessagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('telegram_messages', function (Blueprint $table) {
            // Add a new column
            $table->string('chat_type')->nullable()->after('message_text');

        
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('telegram_messages', function (Blueprint $table) {
            // Remove the added column
            $table->dropColumn('chat_type');
        });
    }
}
