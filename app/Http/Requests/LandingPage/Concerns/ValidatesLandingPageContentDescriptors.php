<?php

declare(strict_types=1);

namespace App\Http\Requests\LandingPage\Concerns;

use App\Http\Controllers\LandingPageController;
use App\Models\LandingPageLink;
use App\Models\Resource;
use Illuminate\Validation\Validator;

trait ValidatesLandingPageContentDescriptors
{
    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $resource = $this->route('resource');
            $template = $this->input('template');

            if ((! is_string($template) || $template === '') && $resource instanceof Resource) {
                $template = $resource->landingPage?->template;
            }

            $supportsPrimaryUrl = is_string($template)
                && LandingPageController::templateSupportsFtpUrl($template);
            $ftpUrl = trim((string) $this->input('ftp_url', ''));

            foreach (['ftp_format_id', 'ftp_size_id'] as $field) {
                if (! $this->filled($field)) {
                    continue;
                }

                if (! $supportsPrimaryUrl) {
                    $validator->errors()->add($field, 'Content descriptors are not supported by this landing page template.');
                } elseif ($ftpUrl === '') {
                    $validator->errors()->add($field, 'A content descriptor requires a primary download URL.');
                }
            }

            $links = $this->input('links', []);
            if (! is_array($links)) {
                return;
            }

            foreach ($links as $index => $link) {
                if (! is_array($link)) {
                    continue;
                }

                $kind = $link['kind'] ?? LandingPageLink::KIND_RELATED;
                if ($kind === LandingPageLink::KIND_DOWNLOAD) {
                    continue;
                }

                foreach (['format_id', 'size_id'] as $field) {
                    if (($link[$field] ?? null) !== null && ($link[$field] ?? '') !== '') {
                        $validator->errors()->add(
                            "links.{$index}.{$field}",
                            'Content descriptors are only allowed for direct download links.',
                        );
                    }
                }
            }
        }];
    }
}
