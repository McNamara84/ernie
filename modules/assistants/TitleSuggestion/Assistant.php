<?php

declare(strict_types=1);

namespace Modules\Assistants\TitleSuggestion;

use App\Models\AssistantSuggestion;
use App\Models\Title;
use App\Services\Assistance\GenericTableAssistant;
use Closure;
use Nitotm\Eld\Eld;

class Assistant extends GenericTableAssistant
{
    private Eld $detector;

    public function __construct()
    {
        parent::__construct();

        $this->detector = new Eld();
    }

    protected function getManifestPath(): string
    {
        return __DIR__ . '/manifest.json';
    }

    protected function discover(Closure $onProgress): int
    {
        $titles = Title::whereNull('language')
            ->orWhere('language', '')
            ->cursor();

        $count = 0;

        foreach ($titles as $title) {
            $onProgress("Detecting language for title #{$title->id}");

            $detection = $this->detectLanguage($title->value);

            if ($detection === null) {
                continue;
            }

            if ($this->storeSuggestion(
                resourceId: $title->resource_id,
                targetType: 'title',
                targetId: $title->id,
                suggestedValue: $detection['code'],
                suggestedLabel: $detection['label'],
                similarityScore: $detection['confidence'],
                metadata: ['title' => $title->value],
            )) {
                $count++;
            }
        }

        return $count;
    }

    protected function applyAccepted(AssistantSuggestion $suggestion): array
    {
        $title = Title::find($suggestion->target_id);

        if ($title === null) {
            return [
                'success' => false,
                'message' => 'Title record not found.',
            ];
        }

        $title->language = $suggestion->suggested_value;
        $title->save();

        return [
            'success' => true,
            'message' => "Title language set to {$suggestion->suggested_label}.",
        ];
    }

    private function detectLanguage(string $text): ?array
    {
        $result = $this->detector->detect($text);

        if ($result === null || empty($result->language)) {
            return null;
        }

        $languageCode = (string) $result->language;
        $confidence = isset($result->score) ? (float) $result->score : null;

        if ($languageCode === '' || $confidence === null) {
            return null;
        }

        return [
            'code' => $languageCode,
            'label' => $this->languageLabel($languageCode),
            'confidence' => $confidence,
        ];
    }

    private function languageLabel(string $code): string
    {
        return match ($code) {
            'de' => 'German',
            'en' => 'English',
            'fr' => 'French',
            'es' => 'Spanish',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'nl' => 'Dutch',
            'ru' => 'Russian',
            'zh' => 'Chinese',
            'ja' => 'Japanese',
            default => strtoupper($code),
        };
    }
}
