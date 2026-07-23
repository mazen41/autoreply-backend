<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessKnowledgeFile;
use App\Models\BusinessProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class KnowledgeController extends Controller
{
    /** Get or create this user's profile (one per user) */
    private function profile(Request $request): BusinessProfile
    {
        return BusinessProfile::firstOrCreate(['user_id' => $request->user()->id]);
    }

    /** Get all knowledge files for the user */
    public function index(Request $request)
    {
        $profile = $this->profile($request);
        $files = $profile->knowledgeFiles()
            ->orderBy('uploaded_at', 'desc')
            ->get(['id', 'filename', 'file_type', 'uploaded_at']);

        return response()->json([
            'files' => $files,
            'ai_instructions' => $profile->ai_instructions,
            'profile' => $profile,
        ]);
    }

    /** Upload and extract text from PDF/Excel files */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,xlsx,xls|max:10240', // Max 10MB
        ]);

        $file = $request->file('file');
        $extractedText = '';

        try {
            if ($file->getClientOriginalExtension() === 'pdf') {
                $extractedText = $this->extractPdfText($file);
            } elseif (in_array($file->getClientOriginalExtension(), ['xlsx', 'xls'])) {
                $extractedText = $this->extractExcelText($file);
            } else {
                return response()->json(['error' => 'Unsupported file type'], 400);
            }

            if (empty($extractedText)) {
                return response()->json(['error' => 'Could not extract text from file'], 400);
            }

            // Store file record
            $profile = $this->profile($request);
            $knowledgeFile = BusinessKnowledgeFile::create([
                'business_profile_id' => $profile->id,
                'filename' => $file->getClientOriginalName(),
                'file_type' => $file->getClientOriginalExtension(),
                'extracted_text' => $extractedText,
            ]);

            return response()->json([
                'message' => 'File processed successfully',
                'file' => $knowledgeFile,
                'extracted_length' => strlen($extractedText),
            ]);
        } catch (\Exception $e) {
            Log::error('File extraction failed', [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName(),
            ]);
            return response()->json(['error' => 'Failed to process file: ' . $e->getMessage()], 500);
        }
    }

    /** Delete a knowledge file */
    public function delete(Request $request, $id)
    {
        $profile = $this->profile($request);
        $file = $profile->knowledgeFiles()->findOrFail($id);
        
        $file->delete();

        return response()->json(['message' => 'File deleted successfully']);
    }

    /** Update AI instructions */
    public function updateInstructions(Request $request)
    {
        $request->validate([
            'ai_instructions' => 'nullable|string|max:10000',
        ]);

        $this->profile($request)->update([
            'ai_instructions' => $request->ai_instructions,
        ]);

        return response()->json(['message' => 'AI instructions updated successfully']);
    }

    /** Update business profile details */
    public function updateProfile(Request $request)
    {
        $request->validate([
            'business_name' => 'nullable|string|max:255',
            'business_type' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'working_days' => 'nullable|array',
            'working_from' => 'nullable|string|max:10',
            'working_to' => 'nullable|string|max:10',
            'services' => 'nullable|string',
            'faqs' => 'nullable|array',
            'reply_style' => 'nullable|string|max:255',
        ]);

        $this->profile($request)->update($request->only([
            'business_name',
            'business_type',
            'phone',
            'city',
            'country',
            'working_days',
            'working_from',
            'working_to',
            'services',
            'faqs',
            'reply_style',
        ]));

        return response()->json(['message' => 'Profile updated successfully']);
    }

    /** Test AI response with current knowledge and instructions */
    public function testResponse(Request $request)
    {
        $request->validate([
            'test_question' => 'required|string|max:1000',
        ]);

        $profile = $this->profile($request);
        
        // Build system prompt using the same logic as ProcessAutoReply
        $systemPrompt = $this->buildTestSystemPrompt($profile);
        
        // Call AI for test response
        $testResponse = $this->callConfiguredAI($systemPrompt, [
            ['role' => 'user', 'content' => $request->test_question]
        ]);

        if (!$testResponse) {
            return response()->json(['error' => 'Failed to generate test response'], 500);
        }

        return response()->json([
            'test_response' => $testResponse,
        ]);
    }

    private function extractPdfText($file)
    {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($file->getPathname());
        return $pdf->getText();
    }

    private function extractExcelText($file)
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getPathname());
        $text = '';

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                $rowData = [];
                foreach ($cellIterator as $cell) {
                    $rowData[] = $cell->getFormattedValue();
                }
                $text .= implode(' | ', array_filter($rowData)) . "\n";
            }
            $text .= "\n--- Sheet End ---\n\n";
        }

        return $text;
    }

    private function buildTestSystemPrompt(BusinessProfile $business): string
    {
        if (!$business) {
            return "You are an AI customer support assistant. Answer questions truthfully. If you do not know the answer, politely state that you don't know and offer to connect them with a human agent.";
        }

        $workingDays = is_array($business->working_days) ? implode(', ', $business->working_days) : ($business->working_days ?? 'N/A');
        $workingHours = "{$workingDays} from {$business->working_from} to {$business->working_to}";

        $faqsText = '';
        if (!empty($business->faqs)) {
            $faqs = is_array($business->faqs) ? $business->faqs : json_decode($business->faqs, true);
            if (is_array($faqs)) {
                foreach ($faqs as $faq) {
                    $q = $faq['question'] ?? $faq['q'] ?? '';
                    $a = $faq['answer'] ?? $faq['a'] ?? '';
                    if ($q && $a) $faqsText .= "Q: {$q}\nA: {$a}\n";
                }
            }
        }

        // Build knowledge base from individual files
        $knowledgeText = '';
        foreach ($business->knowledgeFiles()->get() as $file) {
            $knowledgeText .= "\n\n--- File: {$file->filename} ---\n";
            $knowledgeText .= $file->extracted_text;
        }

        // Truncate if too long to avoid token limits (keep under 20,000 chars)
        if (strlen($knowledgeText) > 20000) {
            $knowledgeText = substr($knowledgeText, 0, 20000) . "\n\n[Content truncated due to length]";
        }

        $prompt = "You are the AI assistant for {$business->business_name}, a {$business->business_type} business.\n";
        $prompt .= "Your job is to answer customer questions accurately using ONLY the information provided below.\n\n";

        $prompt .= "### BUSINESS INFORMATION ###\n";
        $prompt .= "- Business Name: {$business->business_name}\n";
        $prompt .= "- Business Type: {$business->business_type}\n";
        $prompt .= "- Location: {$business->city}, {$business->country}\n";
        $prompt .= "- Contact Phone: {$business->phone}\n";
        $prompt .= "- Working Hours: {$workingHours}\n";
        $prompt .= "- Services/Products: {$business->services}\n";
        
        if ($faqsText) {
            $prompt .= "\n### FREQUENTLY ASKED QUESTIONS ###\n{$faqsText}\n";
        }

        // Add knowledge base from uploaded files
        if (!empty($knowledgeText)) {
            $prompt .= "\n### KNOWLEDGE BASE & DOCUMENTATION ###\n{$knowledgeText}\n";
        }

        // Add custom AI instructions
        if (!empty($business->ai_instructions)) {
            $prompt .= "\n### CUSTOM INSTRUCTIONS ###\n{$business->ai_instructions}\n";
        }

        $prompt .= "\n### CRITICAL RULES ###\n";
        $prompt .= "1. NEVER say vague filler like 'I am here to assist you with any questions' as a substitute for a real answer.\n";
        $prompt .= "2. If you do not know the answer based on the provided information, DO NOT guess or make things up. Honestly say you don't have that information and offer to have a human follow up.\n";
        $prompt .= "3. Actively use the conversation history context provided. Do not repeat or contradict yourself.\n";
        $prompt .= "4. Keep replies concise, clear, and friendly.\n";
        $prompt .= "5. Reply in the same language the customer used (Arabic or English).\n";
        $prompt .= "6. Reply style should be: " . ($business->reply_style ?? 'friendly and professional') . ".\n";

        return $prompt;
    }

    private function callConfiguredAI(string $systemPrompt, array $contextMessages): ?string
    {
        $primary = config('services.ai.provider', 'gemini');
        $fallback = config('services.ai.fallback_provider', $primary === 'gemini' ? 'claude' : 'gemini');
        $providers = array_values(array_unique(array_filter([$primary, $fallback])));

        foreach ($providers as $provider) {
            $reply = match ($provider) {
                'claude' => $this->callClaudeAPI($systemPrompt, $contextMessages),
                'gemini' => $this->callGeminiAPI($systemPrompt, $contextMessages),
                default => null,
            };

            if ($reply) {
                return $reply;
            }
        }

        return null;
    }

    private function callClaudeAPI(string $systemPrompt, array $contextMessages): ?string
    {
        $apiKey = config('services.claude.api_key');
        if (!$apiKey) {
            return null;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.claude.model', 'claude-3-5-sonnet-20241022'),
                'max_tokens' => 1000,
                'system' => $systemPrompt,
                'messages' => $contextMessages,
            ]);

            if ($response->successful()) {
                return $response->json('content.0.text');
            }
        } catch (\Exception $e) {
            Log::error('Claude API call failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function callGeminiAPI(string $systemPrompt, array $contextMessages): ?string
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            return null;
        }

        try {
            $contents = [];
            foreach ($contextMessages as $msg) {
                $contents[] = [
                    'role' => $msg['role'],
                    'parts' => [['text' => $msg['content']]]
                ];
            }

            $response = \Illuminate\Support\Facades\Http::post(
                "https://generativelanguage.googleapis.com/v1beta/models/" . 
                config('services.gemini.model', 'gemini-1.5-flash') . 
                ":generateContent?key={$apiKey}",
                [
                    'contents' => $contents,
                    'systemInstruction' => [
                        'parts' => [['text' => $systemPrompt]]
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => 1000,
                        'temperature' => 0.4,
                    ]
                ]
            );

            if ($response->successful()) {
                return $response->json('candidates.0.content.parts.0.text');
            }
        } catch (\Exception $e) {
            Log::error('Gemini API call failed', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
