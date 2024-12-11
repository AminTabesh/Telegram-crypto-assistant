<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class OpenAIController extends Controller
{
    private $baseUrl = 'https://api.openai.com/v1/chat/completions';

    public function getResponse(Request $request)
    {
        // Get the API key from the environment file
        $apiKey = env('OPENAI_API_KEY');

        // Get the user input from the request
        $prompt = $request->input('prompt');

        if (!$prompt) {
            return response()->json(['error' => 'Prompt is required'], 400);
        }

        // Make the request to OpenAI API
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl, [
            'model' => 'gpt-3.5-turbo', // Specify the GPT model
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);


        if ($response->failed()) {
            return response()->json([
                'error' => 'Failed to communicate with OpenAI API',
                'details' => $response->json(),
            ], 500);
        }

        // Return the GPT response
        return response()->json($response->json());
    }
}
