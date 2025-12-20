<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SendContactMessageRequest;
use App\Models\Resource;
use App\Services\ContactMessageService;
use Illuminate\Http\JsonResponse;

/**
 * Controller for handling contact form submissions on landing pages.
 */
class ContactMessageController extends Controller
{
    public function __construct(
        private readonly ContactMessageService $contactMessageService
    ) {}

    /**
     * Send a contact message to contact person(s) of a dataset.
     */
    public function send(SendContactMessageRequest $request, Resource $resource): JsonResponse
    {
        $ipAddress = $request->ip();

        // Check rate limiting
        if ($this->contactMessageService->isRateLimited($ipAddress)) {
            return response()->json([
                'success' => false,
                'message' => 'You have sent too many messages. Please try again later.',
                'error' => 'rate_limited',
            ], 429);
        }

        // Process the contact form
        $contactMessage = $this->contactMessageService->processContactForm(
            resource: $resource,
            senderName: $request->validated('sender_name'),
            senderEmail: $request->validated('sender_email'),
            recipientContributorIds: $request->validated('recipient_contributor_ids'),
            message: $request->validated('message'),
            sendCopyToSender: $request->boolean('send_copy_to_sender'),
            ipAddress: $ipAddress,
            honeypotTriggered: $request->isHoneypotTriggered(),
        );

        // If honeypot was triggered, pretend success (to fool bots)
        if ($contactMessage->honeypot_triggered) {
            return response()->json([
                'success' => true,
                'message' => 'Your message has been sent.',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your message has been sent successfully.',
            'remaining_messages' => $this->contactMessageService->getRemainingMessages($ipAddress),
        ]);
    }
}
