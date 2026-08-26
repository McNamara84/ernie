<?php

declare(strict_types=1);

namespace App\Services;

final class IgsnRepositoryContactService
{
    public const TYPE_CURRENT = 'current';

    public const TYPE_ORIGINAL = 'original';

    private const EMAIL_PATTERN = '/(?<![A-Z0-9._%+\-@])[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}(?![A-Z0-9._%+\-@])/i';

    /**
     * @return array{type: string, label: string, has_email: bool}|null
     */
    public function publicDescriptor(string $type, ?string $contact, ?string $archive): ?array
    {
        $parsed = $this->parse($type, $contact, $archive);

        if ($parsed === null) {
            return null;
        }

        return [
            'type' => $type,
            'label' => $parsed['label'],
            'has_email' => $parsed['emails'] !== [],
        ];
    }

    /**
     * Resolve recipients from server-side metadata. The frontend never supplies
     * an address; it selects only current or original repository contact.
     *
     * @return list<array{email: string, name: string}>
     */
    public function recipients(string $type, ?string $contact, ?string $archive): array
    {
        $parsed = $this->parse($type, $contact, $archive);

        if ($parsed === null) {
            return [];
        }

        return array_map(
            static fn (string $email): array => ['email' => $email, 'name' => $parsed['label']],
            $parsed['emails'],
        );
    }

    /**
     * @return array{label: string, emails: list<string>}|null
     */
    private function parse(string $type, ?string $contact, ?string $archive): ?array
    {
        $value = trim((string) $contact);

        if ($value === '') {
            return null;
        }

        preg_match_all(self::EMAIL_PATTERN, $value, $matches);

        $emails = [];
        foreach ($matches[0] as $candidate) {
            $email = strtolower($candidate);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[$email] = $email;
            }
        }

        $label = preg_replace(self::EMAIL_PATTERN, ' ', $value) ?? '';
        $label = preg_replace('/[<>\[\]();,]+/', ' ', $label) ?? '';
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? '', " \t\n\r\0\x0B-–—:|");

        // A remaining @ may be an invalid or obfuscated address. Never expose
        // that value, even though it cannot be used as a mail recipient.
        if ($label === '' || str_contains($label, '@')) {
            $label = $this->fallbackLabel($type, $archive);
        }

        return [
            'label' => $label,
            'emails' => array_values($emails),
        ];
    }

    private function fallbackLabel(string $type, ?string $archive): string
    {
        $role = $type === self::TYPE_ORIGINAL ? 'Original' : 'Current';
        $archiveName = trim((string) $archive);

        return $archiveName !== '' ? $archiveName.' contact' : $role.' repository contact';
    }
}
