<?php

namespace App\Http\Controllers;

use App\Models\TelegramMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    // Set the webhook for the bot
    public function setWebhook()
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $webhookUrl = url('api/telegram/webhook');
        $url = "https://api.telegram.org/bot{$botToken}/setWebhook?url={$webhookUrl}";

        $response = Http::get($url);
        Log::info('Set Webhook Response: ', $response->json());

        return response()->json(['status' => 'Webhook set']);
    }

    // Handle incoming updates from Telegram
    public function handleWebhook(Request $request)
    {
        Log::info('Received Telegram Update:', $request->all());

        $message = $request->input('message') ?? $request->input('channel_post') ?? null;

        if ($message) {
            $chatId = $message['chat']['id'];
            $text = $message['text'] ?? '';
            $chatType = $message['chat']['type'];

            TelegramMessage::create([
                'chat_id' => $chatId,
                'message_text' => $text,
                'chat_type' => $chatType,
            ]);

            Log::info("Message from {$chatType} saved to database.");

            // Forward message from channel to bot for processing
            if ($chatType === 'channel') {
                $botChatId = env('TELEGRAM_BOT_ID');
                $this->forwardTelegramMessage($botChatId, $chatId, $message['message_id']);
                Log::info("Message from channel {$chatId} forwarded to bot.");
                $responseText = $this->getChatGptResponse($text);
                $this->sendTelegramMessage($botChatId, $responseText);
            } else if ($chatType === 'private' || $chatType === 'group') {
                $responseText = $this->getChatGptResponse($text);
                $this->sendTelegramMessage($chatId, $responseText);
            } else {
                Log::info("Unhandled chat type: {$chatType}");
            }
        } else {
            Log::warning('No recognizable message format in the update.');
        }

        return response()->json(['status' => 'ok']);
    }

    // Periodically check the channel for new messages (alternative approach)
    public function checkChannelForMessages()
    {
        Log::info('Checking for new messages in the channel...');

        $channelId = env('TELEGRAM_CHANNEL_ID');
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $url = "https://api.telegram.org/bot{$botToken}/getUpdates";

        $response = Http::get($url);
        Log::info('Telegram Channel Updates: ', $response->json());

        if ($response->successful()) {
            $updates = $response->json()['result'];

            foreach ($updates as $update) {
                if (isset($update['message']['chat']['id']) && $update['message']['chat']['id'] == $channelId) {
                    $messageText = $update['message']['text'] ?? 'No text provided';
                    $chatType = $update['message']['chat']['type'];

                    TelegramMessage::create([
                        'chat_id' => $channelId,
                        'message_text' => $messageText,
                        'chat_type' => $chatType,
                    ]);

                    $responseText = $this->getChatGptResponse($messageText);
                    $this->sendTelegramMessage($channelId, $responseText);
                }
            }
        }

        return response()->json(['status' => 'Checked for new messages']);
    }

    // Send a message to ChatGPT and get the response
    private function getChatGptResponse($text)
    {
        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a helpful crypto trade analyzer and assistant.'], //TODO: The context can be changed from here.
                    ['role' => 'user', 'content' => $text],
                ],
            ]);

        if ($response->successful()) {
            Log::info('ChatGPT Response: ', $response->json());
            return $response->json()['choices'][0]['message']['content'] ?? 'No response from ChatGPT.';
        } else {
            Log::error('ChatGPT API Error: ', $response->json());
            return 'Sorry, I could not process your request. Please try again later.';
        }
    }

    // Send a message to the specified chat ID on Telegram
    private function sendTelegramMessage($chatId, $text)
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        $response = Http::post($url, [
            'chat_id' => $chatId,
            'text' => $text,
        ]);

        if ($response->successful()) {
            Log::info('Telegram Message Sent: ', $response->json());
        } else {
            Log::error('Telegram Send Message Error: ', $response->json());
        }
    }

    // Forward a message from one chat to another
    private function forwardTelegramMessage($toChatId, $fromChatId, $messageId)
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $url = "https://api.telegram.org/bot{$botToken}/forwardMessage";

        $response = Http::post($url, [
            'chat_id' => $toChatId,
            'from_chat_id' => $fromChatId,
            'message_id' => $messageId,
        ]);

        if ($response->successful()) {
            Log::info('Telegram Message Forwarded: ', $response->json());
        } else {
            Log::error('Telegram Forward Message Error: ', $response->json());
        }
    }
}
