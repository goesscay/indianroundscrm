<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Port of ai_service.py using the Anthropic Messages API over Laravel's HTTP client. */
class AiController extends Controller
{
    private function ask(string $system, string $prompt): string
    {
        $key = trim((string) env('ANTHROPIC_API_KEY', ''));
        if ($key === '') {
            Fail::http(503, 'AI is not configured: set ANTHROPIC_API_KEY on the server.');
        }
        $model = env('ANTHROPIC_MODEL', 'claude-sonnet-4-5-20250929');
        try {
            $resp = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                ->acceptJson()->timeout(90)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model, 'max_tokens' => 1500, 'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
        } catch (\Throwable $e) {
            Log::error('Anthropic request failed: '.$e->getMessage());
            Fail::http(500, 'AI error: '.$e->getMessage());
        }
        if (! $resp->successful()) {
            Log::error('Anthropic error '.$resp->status().': '.$resp->body());
            Fail::http(500, 'AI error: '.($resp->json('error.message') ?: $resp->body()));
        }
        $text = '';
        foreach ((array) $resp->json('content', []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        return $text;
    }

    public function itinerary(Request $request)
    {
        $b = Fail::validate(Fail::body($request), [
            'destination' => 'required|string', 'days' => 'nullable|integer', 'budget' => 'nullable|string',
            'package_type' => 'nullable|string', 'adults' => 'nullable|integer', 'children' => 'nullable|integer',
            'hotel_category' => 'nullable|string', 'meal_plan' => 'nullable|string',
        ]);
        $days = $b['days'] ?? 5;
        $system = 'You are an expert travel itinerary planner for Indian Rounds, an Indian travel agency. '
            .'Create concise, day-by-day itineraries. Use INR for currency mentions. Keep responses '
            .'under 500 words, formatted with clear day headings.';
        $prompt = "Create a {$days}-day travel itinerary for {$b['destination']}.\n"
            .'Package type: '.($b['package_type'] ?? 'Family')."\n"
            .'Guests: '.($b['adults'] ?? 2).' adults, '.($b['children'] ?? 0)." children\n"
            .'Hotel category: '.($b['hotel_category'] ?? '4 Star')."\n"
            .'Meal plan: '.($b['meal_plan'] ?? 'AP')."\n"
            .'Budget: '.($b['budget'] ?? '')."\n\n"
            .'Provide day-wise activities, recommended experiences, and dining suggestions.';
        return ['content' => $this->ask($system, $prompt)];
    }

    public function quoteRecommend(Request $request)
    {
        $b = Fail::validate(Fail::body($request), [
            'destination' => 'required|string', 'budget' => 'nullable|string', 'hotel_category' => 'nullable|string',
            'adults' => 'nullable|integer', 'children' => 'nullable|integer', 'days' => 'nullable|integer',
        ]);
        $system = 'You are a travel pricing expert for Indian Rounds. Provide realistic INR quote '
            .'recommendations based on destination and budget. Include a range and breakdown.';
        $prompt = "Recommend a supplier quote and selling price range for:\n"
            ."Destination: {$b['destination']}\n"
            .'Duration: '.($b['days'] ?? 5)." days\n"
            .'Guests: '.($b['adults'] ?? 2).' adults, '.($b['children'] ?? 0)." children\n"
            .'Hotel category: '.($b['hotel_category'] ?? '4 Star')."\n"
            .'Customer budget: '.($b['budget'] ?? '')."\n\n"
            .'Give: 1) Suggested supplier cost range (INR), 2) Recommended selling price (INR), '
            .'3) Expected profit margin, 4) Brief pricing rationale.';
        return ['content' => $this->ask($system, $prompt)];
    }
}
