<?php

namespace App\Http\Controllers;

use App\Models\TelegramMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    public function setWebhook()
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $webhookUrl = url('https://5e59-82-115-17-69.ngrok-free.app'); //TODO : Put actual url after deployment!!!
        $url = "https://api.telegram.org/bot{$botToken}/setWebhook?url={$webhookUrl}/telegram-webhook";

        $response = Http::get($url);
        Log::info('Set Webhook Response: ', $response->json());

        return response()->json(['status' => 'Webhook set']);
    }

    public function handleWebhook(Request $request)
    {
        Log::info('Received Telegram Update:', $request->all());
        
        $message = $request->input('message') ?? $request->input('channel_post') ?? null;
        
        Log::info('xxxxxxxxxxxxxxxxx:', $message); //TODO: Delete this line.

        if ($message) {
            $chatId = $message['chat']['id'];
            $text = $message['text'] ?? null;
            $chatType = $message['chat']['type'];
            $channelName = $message['chat']['title'] ?? "Direct" ;
            $chatUsername = $message['chat']['username'] ?? null;
            $messageId = $message['message_id'] ?? $message['channel_post']['message_id'];

            if ($chatUsername && $messageId) {

                $messageUrl = "https://t.me/$chatUsername/$messageId";
            }else {
                $messageUrl = "The channel is private.";
            }


            TelegramMessage::create([
                'chat_id' => $chatId,
                'message_text' => $text,
                'chat_type' => $chatType,
                'channel_name' => $channelName
            ]);

            if (isset($message['forward_from_message_id'])) {
                $responseText = $this->getChatGptResponse($text);
                $this->sendTelegramMessage($chatId, $responseText, $message['forward_from_message_id']);
            }

            if ($chatType === 'private') {
                $responseText = $this->getChatGptResponse($text);
                $this->sendTelegramMessage($chatId, $responseText, $message['message_id']);
            }

            if ($chatType === 'channel') {
                $botChatId = env('TELEGRAM_BOT_ID');
                if ($text) {
                    $this->forwardTelegramMessage($botChatId, $chatId, $message['message_id']);
                    $gptResponse = $this->getChatGptResponse($text);
                    $responseText = "Replying to " . '"'. substr($text, 0, 100) . "... \" :" . "\n\n" . "Url: $messageUrl" . "\n\n" . $gptResponse;
                    $this->sendTelegramMessage($botChatId, $responseText, $message['message_id']);
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }

    public function checkChannelForMessages()
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $url = "https://api.telegram.org/bot{$botToken}/getUpdates";
        $response = Http::get($url);

        if ($response->successful()) {
            $updates = $response->json()['result'];
            $channelIds = explode(',', env('TELEGRAM_CHANNEL_IDS'));

            foreach ($updates as $update) {
                $chatId = $update['message']['chat']['id'] ?? null;

                if (in_array($chatId, $channelIds)) {
                    $messageText = $update['message']['text'] ?? 'No text provided';
                    TelegramMessage::create([
                        'chat_id' => $chatId,
                        'message_text' => $messageText,
                        'chat_type' => $update['message']['chat']['type'],
                    ]);

                    $responseText = $this->getChatGptResponse($messageText);
                    $this->sendTelegramMessage($chatId, $responseText);
                }
            }
        }

        return response()->json(['status' => 'Checked for new messages']);
    }

    private function getChatGptResponse($text)
    {
        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a helpful assistant.'], //TODO: Context can be changed here
                    ['role' => 'user', 'content' => $text],
                ],
            ]);

        if ($response->successful()) {
            return $response->json()['choices'][0]['message']['content'] ?? 'No response from ChatGPT.';
        } else {
            Log::error('ChatGPT API Error: ', $response->json());
            return 'Sorry, I could not process your request. Please try again later.';
        }
    }

    private function sendTelegramMessage($chatId, $text, $replyToMessageId = null)
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        $data = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($replyToMessageId) {
            $data['reply_to_message_id'] = $replyToMessageId;
        }

        $response = Http::post($url, $data);

        if ($response->successful()) {
            Log::info('Telegram Message Sent: ', $response->json());
        } else {
            Log::error('Telegram Send Message Error: ', $response->json());
        }
    }

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
