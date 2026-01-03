<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\ContactPersonMessage;
use App\Models\ContactMessage;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Contact Message Controller
 *
 * Handles contact form submissions from landing pages.
 * Includes honeypot spam protection and rate-limiting.
 */
class ContactMessageController extends Controller
{
    /**
     * Maximum messages allowed per IP per hour
     */
    private const RATE_LIMIT_MAX = 5;

    private const RATE_LIMIT_MINUTES = 60;

    /**
     * Store a new contact message from a landing page with DOI.
     * Route: POST /{doiPrefix}/{slug}/contact
     */
    public function store(Request $request, string $doiPrefix, string $slug): JsonResponse
    {
        // Find landing page by DOI prefix and slug
        $landingPage = LandingPage::where('doi_prefix', $doiPrefix)
            ->where('slug', $slug)
            ->first();

        if ($landingPage === null) {
            abort(404, 'Landing page not found');
        }

        return $this->processContactMessage($request, $landingPage->resource_id);
    }

    /**
     * Store a new contact message from a draft landing page (without DOI).
     * Route: POST /draft-{resourceId}/{slug}/contact
     */
    public function storeDraft(Request $request, int $resourceId, string $slug): JsonResponse
    {
        // Find landing page by resource ID and slug (no DOI)
        $landingPage = LandingPage::where('resource_id', $resourceId)
            ->whereNull('doi_prefix')
            ->where('slug', $slug)
            ->first();

        if ($landingPage === null) {
            abort(404, 'Landing page not found');
        }

        // Use $landingPage->resource_id for consistency with store() method.
        // While this should always equal $resourceId (from route parameter),
        // using the model's value ensures consistency if data ever mismatches.
        return $this->processContactMessage($request, $landingPage->resource_id);
    }

    /**
     * Process the contact message (shared logic).
     */
    private function processContactMessage(Request $request, int $resourceId): JsonResponse
    {
        // Check honeypot field (should be empty)
        if ($request->filled('website_url')) {
            // Log potential bot attempt but return success to not reveal detection
            Log::info('Contact form honeypot triggered', [
                'resource_id' => $resourceId,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Message sent successfully.',
            ]);
        }

        // Check rate limiting
        $ipAddress = $request->ip() ?? 'unknown';
        $recentCount = ContactMessage::countRecentFromIp($ipAddress, self::RATE_LIMIT_MINUTES);

        if ($recentCount >= self::RATE_LIMIT_MAX) {
            throw ValidationException::withMessages([
                'rate_limit' => ['Too many messages. Please try again later.'],
            ]);
        }

        // Validate the request
        $validated = $request->validate([
            'sender_name' => 'required|string|max:255',
            'sender_email' => 'required|email|max:255',
            'message' => 'required|string|min:10|max:5000',
            'send_to_all' => 'boolean',
            'copy_to_sender' => 'boolean',
            'resource_creator_id' => 'nullable|integer|exists:resource_creators,id',
        ]);

        // Load resource with creators
        $resource = Resource::with([
            'creators.creatorable',
            'creators.affiliations',
            'titles',
        ])->findOrFail($resourceId);

        // Determine recipients
        $recipients = $this->getRecipients(
            $resource,
            $validated['send_to_all'] ?? false,
            $validated['resource_creator_id'] ?? null
        );

        if (empty($recipients)) {
            throw ValidationException::withMessages([
                'recipients' => ['No contact persons available for this dataset.'],
            ]);
        }

        // Create contact message record
        $contactMessage = ContactMessage::create([
            'resource_id' => $resourceId,
            'resource_creator_id' => $validated['resource_creator_id'] ?? null,
            'send_to_all' => $validated['send_to_all'] ?? false,
            'sender_name' => $validated['sender_name'],
            'sender_email' => $validated['sender_email'],
            'message' => $validated['message'],
            'copy_to_sender' => $validated['copy_to_sender'] ?? false,
            'ip_address' => $ipAddress,
        ]);

        // Send emails to all recipients
        foreach ($recipients as $recipient) {
            Mail::to($recipient['email'])->queue(
                new ContactPersonMessage(
                    $contactMessage,
                    $resource,
                    $recipient['name'],
                    false
                )
            );
        }

        // Send copy to sender if requested
        if ($validated['copy_to_sender'] ?? false) {
            Mail::to($validated['sender_email'])->queue(
                new ContactPersonMessage(
                    $contactMessage,
                    $resource,
                    $validated['sender_name'],
                    true
                )
            );
        }

        // Mark as sent
        $contactMessage->markAsSent();

        Log::info('Contact message sent', [
            'contact_message_id' => $contactMessage->id,
            'resource_id' => $resourceId,
            'recipients_count' => count($recipients),
            'copy_to_sender' => $validated['copy_to_sender'] ?? false,
        ]);

        return response()->json([
            'message' => 'Message sent successfully.',
            'recipients_count' => count($recipients),
        ]);
    }

    /**
     * Get recipients for the contact message.
     *
     * @return array<int, array{email: string, name: string}>
     */
    private function getRecipients(Resource $resource, bool $sendToAll, ?int $resourceCreatorId): array
    {
        $recipients = [];

        // Get all contact persons (creators with email)
        $contactPersons = $resource->creators->filter(
            fn (ResourceCreator $creator) => $creator->email !== null && $creator->email !== ''
        );

        if ($sendToAll) {
            // Send to all contact persons
            foreach ($contactPersons as $creator) {
                /** @var \App\Models\Person|\App\Models\Institution $creatorable */
                $creatorable = $creator->creatorable;
                // Email is guaranteed non-null by the filter above
                $recipients[] = [
                    'email' => (string) $creator->email,
                    'name' => $this->getCreatorName($creatorable),
                ];
            }
        } elseif ($resourceCreatorId !== null) {
            // Send to specific creator
            $creator = $contactPersons->firstWhere('id', $resourceCreatorId);
            if ($creator !== null && $creator->email !== null) {
                /** @var \App\Models\Person|\App\Models\Institution $creatorable */
                $creatorable = $creator->creatorable;
                $recipients[] = [
                    'email' => $creator->email,
                    'name' => $this->getCreatorName($creatorable),
                ];
            }
        }

        return $recipients;
    }

    /**
     * Get the display name for a creator.
     *
     * @param  \App\Models\Person|\App\Models\Institution  $creatorable
     */
    private function getCreatorName($creatorable): string
    {
        // Check if it's a Person (has given_name/family_name) or Institution (has name)
        if (isset($creatorable->family_name)) {
            $name = $creatorable->family_name;
            if (isset($creatorable->given_name)) {
                $name = $creatorable->given_name.' '.$creatorable->family_name;
            }

            return $name;
        }

        return $creatorable->name ?? 'Contact Person';
    }
}
