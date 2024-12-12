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
        $webhookUrl = url('https://b2d1-82-115-17-69.ngrok-free.app'); //TODO : Put actual url after deployment!!!
        $url = "https://api.telegram.org/bot{$botToken}/setWebhook?url={$webhookUrl}/telegram-webhook";

        $response = Http::get($url);
        Log::info('Set Webhook Response: ', $response->json());

        return response()->json(['status' => 'Webhook set']);
    }

    public function handleWebhook(Request $request)
    {
        Log::info('Received Telegram Update:', $request->all());
        
        $message = $request->input('message') ?? $request->input('channel_post') ?? null;
    

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


            if ($text) {
                TelegramMessage::create([
                    'chat_id' => $chatId,
                    'message_text' => $text,
                    'chat_type' => $chatType,
                    'channel_name' => $channelName
                ]);
            }

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
                    $botResponseText = "Url: $messageUrl" . "\n\n" . $gptResponse;

                    $targetChannelMessage = "Signal Url: {$messageUrl} \n\n $gptResponse";

                    $this->sendTelegramMessage($botChatId, $botResponseText, $message['message_id']);
                    $this->sendToChannel($targetChannelMessage);
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }

    //NOTE: Periodic check for updates in the channel.

    // public function checkChannelForMessages()
    // {
    //     $botToken = env('TELEGRAM_BOT_TOKEN');
    //     $url = "https://api.telegram.org/bot{$botToken}/getUpdates";
    //     $response = Http::get($url);

    //     if ($response->successful()) {
    //         $updates = $response->json()['result'];
    //         $channelIds = explode(',', env('TELEGRAM_CHANNEL_IDS'));

    //         foreach ($updates as $update) {
    //             $chatId = $update['message']['chat']['id'] ?? null;

    //             if (in_array($chatId, $channelIds)) {
    //                 $messageText = $update['message']['text'] ?? 'No text provided';
    //                 TelegramMessage::create([
    //                     'chat_id' => $chatId,
    //                     'message_text' => $messageText,
    //                     'chat_type' => $update['message']['chat']['type'],
    //                 ]);

    //                 $responseText = $this->getChatGptResponse($messageText);
    //                 $this->sendTelegramMessage($chatId, $responseText);
    //             }
    //         }
    //     }

    //     return response()->json(['status' => 'Checked for new messages']);
    // }

    private function getChatGptResponse($text)
    {
        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4-turbo', //Note: Can be 'gpt-4', 'gpt-4-turbo', 'gpt-3.5-turbo' 
                'messages' => [
                    ['role' => 'system', 'content' => "You will be provided with some trade signals.First, return the signal itself.Then title your thoughts with 'AI analysis' and a flair emoji,then rate the signal's risk from 1-10. 1 is the lowest and 10 is the highest. Use an emoji after the percent, green circle for low risk, yellow circle for medium risk and red circle for high risk. Afterwards, include a very brief explaination of why you rated it like that and what you think about the signal generally.At the end, tell if you recomend this or not. The exact pattern that you have to answer in is: 
                        
                    SIGNAL📈:
                    // the signal here

                    AI Analysis ✨:
                    Risk: Your estimation / 10 (the emoji here)
                    
                    Explanation: //Your brief report here

                    AI Recomendation: //can be 'Suggested ✅, Not sure  or Not suggested❌'
                        "], //TODO: Context can be changed here
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

    private function sendToChannel($text)
    {
        $channelIds = explode(',', env('TELEGRAM_SEND_CHANNELS_IDS'));
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        foreach ($channelIds as $target){

            $data = [
                'chat_id' => $target, // The numeral telegram id
                'text' => $text,
            ];
            
            Log::info('dataaaaaaaaaaaaaa: ', $data);
            $response = Http::post($url, $data);
    
            if ($response->successful()) {
                Log::info('Message sent to channel: ', $response->json());
            } else {
                Log::error('Failed to send message to channel: ', $response->json());
            }

        }

        
    }
}
