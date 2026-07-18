<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    /** Get or create this user's profile (one per user) */
    private function profile(Request $request): BusinessProfile
    {
        return BusinessProfile::firstOrCreate(['user_id' => $request->user()->id]);
    }

    /** STEP 1 — business type */
    public function step1(Request $request)
    {
        $validated = $request->validate([
            'business_type' => 'required|string|max:50',
        ]);

        $this->profile($request)->update($validated);

        return response()->json(['message' => 'Step 1 saved']);
    }

    /** STEP 2 — business info + schedule */
    public function step2(Request $request)
    {
        $validated = $request->validate([
            'business_name' => 'required|string|max:255',
            'phone'         => 'nullable|string|max:30',
            'city'          => 'nullable|string|max:100',
            'country'       => 'nullable|string|max:100',
            'working_days'  => 'nullable|array',
            'working_days.*'=> 'string|max:10',
            'working_from'  => 'nullable|string|max:5',
            'working_to'    => 'nullable|string|max:5',
        ]);

        $this->profile($request)->update($validated);

        return response()->json(['message' => 'Step 2 saved']);
    }

    /** STEP 3 — AI brain (services, FAQs, reply style) */
    public function step3(Request $request)
    {
        $validated = $request->validate([
            'services'    => 'nullable|string',
            'faqs'        => 'nullable|array',
            'faqs.*.q'    => 'nullable|string|max:500',
            'faqs.*.a'    => 'nullable|string|max:1000',
            'reply_style' => 'nullable|string|max:50',
        ]);

        $this->profile($request)->update($validated);

        return response()->json(['message' => 'Step 3 saved']);
    }

    /** Upload and extract text from PDF/Excel files */
    public function uploadKnowledgeFile(Request $request)
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

            // Store extracted text in knowledge_base
            $profile = $this->profile($request);
            $existingKnowledge = $profile->knowledge_base ?? '';
            $profile->update([
                'knowledge_base' => $existingKnowledge . "\n\n" . $extractedText,
            ]);

            return response()->json([
                'message' => 'File processed successfully',
                'extracted_length' => strlen($extractedText),
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('File extraction failed', [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName(),
            ]);
            return response()->json(['error' => 'Failed to process file: ' . $e->getMessage()], 500);
        }
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

    /** STEP 4 — connected channel */
    public function step4(Request $request)
    {
        $validated = $request->validate([
            'connected_channel' => 'nullable|string|max:50',
        ]);

        $this->profile($request)->update($validated);

        return response()->json(['message' => 'Step 4 saved']);
    }

    /** COMPLETE — mark onboarding done, return fresh user */
    public function complete(Request $request)
    {
        $user = $request->user();
        $user->update(['onboarding_completed' => true]);

        return response()->json([
            'message' => 'Onboarding complete',
            'user'    => $user->fresh(),
        ]);
    }
}
