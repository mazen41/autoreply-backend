<?php

namespace App\Services;

use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\SequenceEnrollment;
use App\Models\Conversation;
use App\Models\BusinessProfile;
use App\Services\BusinessHoursService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SequenceService
{
    protected BusinessHoursService $businessHoursService;

    public function __construct(BusinessHoursService $businessHoursService)
    {
        $this->businessHoursService = $businessHoursService;
    }

    public function createSequence(array $data, int $businessId): Sequence
    {
        return DB::transaction(function () use ($data, $businessId) {
            $sequence = Sequence::create([
                'business_id' => $businessId,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'trigger_type' => $data['trigger_type'] ?? 'manual',
                'trigger_config' => $data['trigger_config'] ?? null,
                'channel' => $data['channel'] ?? null,
                'status' => 'draft',
                'settings' => $data['settings'] ?? null,
                'timezone' => $data['timezone'] ?? 'UTC',
                'business_hours' => $data['business_hours'] ?? null,
            ]);

            if (isset($data['steps']) && is_array($data['steps'])) {
                foreach ($data['steps'] as $index => $stepData) {
                    SequenceStep::create([
                        'sequence_id' => $sequence->id,
                        'step_order' => $index + 1,
                        'step_type' => $stepData['step_type'] ?? 'message',
                        'message' => $stepData['message'] ?? null,
                        'config' => $stepData['config'] ?? null,
                        'delay_hours' => $stepData['delay_hours'] ?? 0,
                        'delay_unit' => $stepData['delay_unit'] ?? 'hours',
                        'condition_config' => $stepData['condition_config'] ?? null,
                        'is_active' => $stepData['is_active'] ?? true,
                    ]);
                }
            }

            return $sequence;
        });
    }

    public function updateSequence(Sequence $sequence, array $data): Sequence
    {
        return DB::transaction(function () use ($sequence, $data) {
            $sequence->update([
                'name' => $data['name'] ?? $sequence->name,
                'description' => $data['description'] ?? $sequence->description,
                'trigger_type' => $data['trigger_type'] ?? $sequence->trigger_type,
                'trigger_config' => $data['trigger_config'] ?? $sequence->trigger_config,
                'channel' => $data['channel'] ?? $sequence->channel,
                'settings' => $data['settings'] ?? $sequence->settings,
                'timezone' => $data['timezone'] ?? $sequence->timezone,
                'business_hours' => $data['business_hours'] ?? $sequence->business_hours,
            ]);

            if (isset($data['steps']) && is_array($data['steps'])) {
                // Delete existing steps
                $sequence->steps()->delete();

                // Recreate steps
                foreach ($data['steps'] as $index => $stepData) {
                    SequenceStep::create([
                        'sequence_id' => $sequence->id,
                        'step_order' => $index + 1,
                        'step_type' => $stepData['step_type'] ?? 'message',
                        'message' => $stepData['message'] ?? null,
                        'config' => $stepData['config'] ?? null,
                        'delay_hours' => $stepData['delay_hours'] ?? 0,
                        'delay_unit' => $stepData['delay_unit'] ?? 'hours',
                        'condition_config' => $stepData['condition_config'] ?? null,
                        'is_active' => $stepData['is_active'] ?? true,
                    ]);
                }
            }

            return $sequence->fresh();
        });
    }

    public function deleteSequence(Sequence $sequence): bool
    {
        return DB::transaction(function () use ($sequence) {
            // Stop all active enrollments
            $sequence->activeEnrollments()->each(function ($enrollment) {
                $enrollment->stop('sequence_deleted');
            });

            // Delete sequence
            return $sequence->delete();
        });
    }

    public function duplicateSequence(Sequence $sequence): Sequence
    {
        return DB::transaction(function () use ($sequence) {
            $newSequence = Sequence::create([
                'business_id' => $sequence->business_id,
                'name' => $sequence->name . ' (Copy)',
                'description' => $sequence->description,
                'trigger_type' => $sequence->trigger_type,
                'trigger_config' => $sequence->trigger_config,
                'channel' => $sequence->channel,
                'status' => 'draft',
                'settings' => $sequence->settings,
            ]);

            foreach ($sequence->steps as $step) {
                SequenceStep::create([
                    'sequence_id' => $newSequence->id,
                    'step_order' => $step->step_order,
                    'step_type' => $step->step_type,
                    'message' => $step->message,
                    'config' => $step->config,
                    'delay_hours' => $step->delay_hours,
                    'delay_unit' => $step->delay_unit,
                    'condition_config' => $step->condition_config,
                    'is_active' => $step->is_active,
                ]);
            }

            return $newSequence;
        });
    }

    public function activateSequence(Sequence $sequence): Sequence
    {
        if (!$sequence->canBeActivated()) {
            throw new \Exception('Sequence cannot be activated in its current state');
        }

        // Validate sequence structure
        $validationErrors = $this->validateSequence($sequence);
        if (!empty($validationErrors)) {
            throw new \Exception('Sequence validation failed: ' . implode(', ', $validationErrors));
        }

        // Validate that sequence has at least one step
        if ($sequence->steps()->count() === 0) {
            throw new \Exception('Sequence must have at least one step before activation');
        }

        // Validate that sequence has a configured channel
        if (!$sequence->channel) {
            throw new \Exception('Sequence must have a configured channel before activation');
        }

        // Validate trigger configuration if not manual
        if ($sequence->trigger_type !== 'manual') {
            // Some trigger types require specific configuration
            $triggersRequiringConfig = ['tag_added'];
            if (in_array($sequence->trigger_type, $triggersRequiringConfig)) {
                $triggerConfig = $sequence->trigger_config ?? [];
                if (empty($triggerConfig)) {
                    throw new \Exception('Sequence trigger configuration is required for tag_added triggers');
                }
            }
            // no_reply and order_created can work with default configurations
        }

        // Validate that all message steps have content
        $messageSteps = $sequence->steps()->where('step_type', 'message')->get();
        foreach ($messageSteps as $step) {
            if (empty($step->message)) {
                throw new \Exception("Message step {$step->step_order} is missing content");
            }
        }

        // Validate that all delay steps have valid values
        $delaySteps = $sequence->steps()->where('step_type', 'delay')->get();
        foreach ($delaySteps as $step) {
            if ($step->delay_hours < 0) {
                throw new \Exception("Delay step {$step->step_order} has invalid delay value");
            }
        }

        // Validate that all condition steps have valid configuration
        $conditionSteps = $sequence->steps()->where('step_type', 'condition')->get();
        foreach ($conditionSteps as $step) {
            if (empty($step->condition_config) || empty($step->condition_config['type'])) {
                throw new \Exception("Condition step {$step->step_order} is missing configuration");
            }
        }

        // Validate business hours configuration if provided
        if ($sequence->business_hours) {
            $businessHoursErrors = $this->businessHoursService->validateBusinessHours($sequence->business_hours);
            if (!empty($businessHoursErrors)) {
                throw new \Exception('Business hours validation failed: ' . implode(', ', $businessHoursErrors));
            }
        }

        $sequence->activate();
        return $sequence;
    }

    public function pauseSequence(Sequence $sequence): Sequence
    {
        if (!$sequence->canBePaused()) {
            throw new \Exception('Sequence cannot be paused');
        }

        // Pause all active enrollments
        $sequence->activeEnrollments()->each(function ($enrollment) {
            $enrollment->stop('sequence_paused');
        });

        $sequence->pause();
        return $sequence;
    }

    public function archiveSequence(Sequence $sequence): Sequence
    {
        if (!$sequence->canBeArchived()) {
            throw new \Exception('Sequence cannot be archived');
        }

        // Stop all active enrollments
        $sequence->activeEnrollments()->each(function ($enrollment) {
            $enrollment->stop('sequence_archived');
        });

        $sequence->archive();
        return $sequence;
    }

    public function getSequencesForBusiness(int $businessId, array $filters = [])
    {
        $query = Sequence::forBusiness($businessId);

        if (isset($filters['status'])) {
            if ($filters['status'] === 'active') {
                $query->active();
            } elseif ($filters['status'] === 'draft') {
                $query->draft();
            } elseif ($filters['status'] === 'paused') {
                $query->paused();
            } elseif ($filters['status'] === 'archived') {
                $query->archived();
            }
        }

        if (isset($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }

        if (isset($filters['trigger_type'])) {
            $query->where('trigger_type', $filters['trigger_type']);
        }

        return $query->withCount(['enrollments as total_enrollments'])
            ->withCount(['enrollments as active_enrollments' => function ($q) {
                $q->where('status', 'active');
            }])
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    public function getSequenceAnalytics(Sequence $sequence): array
    {
        $totalEnrollments = $sequence->getTotalEnrollments();
        $activeEnrollments = $sequence->getActiveEnrollments();
        $completedEnrollments = $sequence->getCompletedEnrollments();
        $conversionRate = $sequence->getConversionRate();

        $messagesSent = $sequence->stepExecutions()
            ->where('status', 'executed')
            ->whereHas('step', function ($q) {
                $q->where('step_type', 'message');
            })
            ->count();

        return [
            'total_enrollments' => $totalEnrollments,
            'active_enrollments' => $activeEnrollments,
            'completed_enrollments' => $completedEnrollments,
            'stopped_enrollments' => $sequence->enrollments()->where('status', 'stopped')->count(),
            'failed_enrollments' => $sequence->enrollments()->where('status', 'failed')->count(),
            'conversion_rate' => $conversionRate,
            'messages_sent' => $messagesSent,
            'total_steps' => $sequence->steps()->count(),
        ];
    }

    public function validateSequence(Sequence $sequence): array
    {
        $errors = [];

        if (empty($sequence->name)) {
            $errors[] = 'Sequence name is required';
        }

        if ($sequence->steps()->count() === 0) {
            $errors[] = 'Sequence must have at least one step';
        }

        // Validate steps
        foreach ($sequence->steps()->active()->ordered()->get() as $step) {
            if ($step->isMessageStep() && empty($step->message)) {
                $errors[] = "Step {$step->step_order}: Message content is required";
            }

            if ($step->isDelayStep() && $step->delay_hours < 0) {
                $errors[] = "Step {$step->step_order}: Delay cannot be negative";
            }

            if ($step->isConditionStep() && empty($step->condition_config)) {
                $errors[] = "Step {$step->step_order}: Condition configuration is required";
            }
        }

        return $errors;
    }
}
