<?php

namespace App\Http\Controllers;

use App\Models\TelegramMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use function PHPUnit\Framework\isEmpty;

class TelegramController extends Controller
{ 

    public function setWebhook()
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $webhookUrl = url('https://2358-82-115-17-69.ngrok-free.app'); //TODO : Put actual url after deployment!!!
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
        $botChatId = env('TELEGRAM_BOT_ID');
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? null;
        $caption = $message['caption'] ?? null;  // Caption (text below image)
        $chatType = $message['chat']['type'];
        $channelName = $message['chat']['title'] ?? "Direct";
        $chatUsername = $message['chat']['username'] ?? null;
        $messageId = $message['message_id'] ?? $message['channel_post']['message_id'];
        $blockedChannelId = env('TELEGRAM_SEND_CHANNELS_IDS');
        
        // Block forwarding messages from a specific channel
        if ($chatId == $blockedChannelId) {
            Log::info("Blocked forwarding from target channel");
            return response()->json(['status' => 'ignored']);
        }

        // Construct URL for public/private messages
        $messageUrl = $chatUsername && $messageId 
            ? "https://t.me/$chatUsername/$messageId" 
            : "The channel is private.";
    
        // Handle long messages
        if ($text && strlen($text) > 550) {
            $this->sendTelegramMessage($botChatId, "Message is too long. Please send a signal to evaluate.", $message['message_id']);
            return response()->json(["status" => "Message is too long."]);
        }
    
        // Handle forwarded messages
        if (isset($message['forward_from_message_id'])) {
            $signalText = $text ?? $caption;  // Use text or caption if available
            $responseText = $this->getChatGptResponse($signalText);
            $this->sendTelegramMessage($chatId, $responseText, $message['forward_from_message_id']);
        }
    
        // Process private chats
        if ($chatType === 'private') {
            $signalText = $text ?? $caption;  
            $responseText = $this->getChatGptResponse($signalText);
            $this->sendTelegramMessage($chatId, $responseText, $message['message_id']);
            $signal = $this->extractSignal($responseText);
            $this->sendToChannel($signal);
    
            if ($signalText && $responseText !== "Please send a valid signal to evaluate.") {
                TelegramMessage::create([
                    'chat_id' => $chatId,
                    'message_text' => $signalText,
                    'chat_type' => $chatType,
                    'channel_name' => $channelName,
                ]);
            }
        }
    
        // Process channel posts
        if ($chatType === 'channel') {
            if ($text || $caption) {
                $signalText = $text ?? $caption;  
                $gptResponse = $this->getChatGptResponse($signalText);
                $botResponseText = "Url: $messageUrl" . "\n\n" . $gptResponse;
                // $avgRating = TelegramController::calculateAvgRating($channelName);

                $signal = $this->extractSignal($gptResponse);
    
                if ($gptResponse !== "Please send a valid signal to evaluate.") {
                    // Check AI Recommendation
                    if (preg_match('/AI Recommendation:\s*(Suggested ✅)/', $gptResponse)) {
                        // Prevent forwarding message to the bot itself
                        if ($chatId != $botChatId) {
                            $this->forwardTelegramMessage($botChatId, $chatId, $message['message_id']);
                        }
    

                        preg_match('/Risk:\s*(\d+)/', $gptResponse, $matches);
                        $gptRating = $matches[1] ?? null;
    

                        TelegramMessage::create([
                            'chat_id' => $chatId,
                            'message_text' => $signalText,
                            'chat_type' => $chatType,
                            'channel_name' => $channelName,
                            'AI_Rating' => $gptRating
                        ]);
    
                        $this->sendTelegramMessage($botChatId, $botResponseText, $message['message_id']);
                        $this->sendToChannel($signal);
                    } else {
                        $this->sendTelegramMessage($botChatId, $botResponseText, $message['message_id']);
                        Log::info("AI Recommendation not 'Suggested ✅': Signal ignored.");
                    }
                } else {
                    Log::info("GPT no signal: The sent message is not a trade signal, so it got ignored.");
                }
            }
        }
    }
    
    return response()->json(['status' => 'ok']);
}

    //NOTE: Periodic check for updates in the channel.

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
                'model' => 'gpt-4-turbo', //Note: Can be 'gpt-4', 'gpt-4-turbo', 'gpt-3.5-turbo' 
                'messages' => [
                    ['role' => 'system', 'content' => "First, check if the provided message is a correct trade signal. If not, return the exact text of 'Please send a valid signal to evaluate.'. Otherwise, you have to rate the signal based on: 
                        1. Market Structure: Identify patterns such as higher highs, lower lows, and consolidation phases.
                        2. Trend Analysis: Utilize moving averages (e.g., 50-day and 200-day EMAs) to determine short- and long-term trends.
                        3. Volume Analysis: Assess volume spikes and divergences to gauge market sentiment and confirm price movements.
                        4. Technical Indicators: Interpret key indicators such as RSI, MACD, Bollinger Bands, and Fibonacci retracements to determine overbought/oversold conditions and potential reversals.
                        5. Candlestick Patterns: Highlight significant candlestick patterns (e.g., engulfing, pin bars, doji) and their implications for market direction.
                        6. Support & Resistance: Identify horizontal levels, trendlines, and zones of high liquidity.
                        7. Risk Assessment: Evaluate the risk-to-reward ratio and suggest stop-loss and take-profit levels for potential trades.
                        8. Systematic Insights: Incorporate data-driven insights by analyzing alternative data sources, such as market sentiment from news articles and social media, to enhance the understanding of market dynamics.
                        9. Scenario Analysis: Perform stress testing by simulating various market scenarios to assess potential impacts on the cryptocurrency's price, considering factors like macroeconomic changes and technological developments.
                        Check the signal in different timeframes (1h , 3h, 6h, 12h, 1d). What I want you to return at the end is:
                        A corrected version of the given signal based on your analysis
                        A risk evaluation from one to ten. the lowest and 10 is the highest. Use an emoji after the percent, green circle for low risk, yellow circle for medium risk and red circle for high risk.
                        At the end, tell if you recommend this signal or not
                        I want you to answer exactly in this pattern without any further explanations or changes, also set the block spacing and the format of the response to a neat and readable format: 
                        SIGNAL📈: 
                        //the corrected signal in the cornix format here
                        AI Analysis ✨: 
                        Risk: //Your estimation / 10 (the emoji here)
                        Explanation:
                        //tell what you have changed and why you did it in a short form
                        AI Recommendation:
                        //can be 'Suggested ✅, Not sure🤔 or Not suggested❌'
"], //Note: Context can be changed here
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
            
            $response = Http::post($url, $data);
    
            if ($response->successful()) {
                Log::info('Message sent to channel: ', $response->json());
            } else {
                Log::error('Failed to send message to channel: ', $response->json());
            }

        }

        
    }

    public static function calculateAvgRating($channelName)
{
    $messagesArray = TelegramMessage::where('channel_name', $channelName)
        ->whereNotNull('AI_Rating')
        ->get();

    if ($messagesArray->isEmpty()) {
        return "No data found.";
    }

    $averageRating = round($messagesArray->avg('AI_Rating'), 1);
    $riskLevel = $averageRating < 6 ? 'low' : ($averageRating < 8 ? 'medium' : 'high');
    $emoji = $riskLevel === 'low' ? '🟢' : ($riskLevel === 'medium' ? '🟡' : '🔴');

    return "{$averageRating} / 10, This channel usually provides {$riskLevel} risk signals. {$emoji}";
}

private function extractSignal($aiResponse)
{
    $matches = [];
    preg_match('/SIGNAL📈:\s*(.*?)(?=\nAI Analysis ✨:)/s', $aiResponse, $matches);

    return $matches[1] ?? 'No signal details found.';
}
}
