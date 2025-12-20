<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for contact form submissions on landing pages.
 */
class SendContactMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Anyone can submit a contact form (guests included).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sender_name' => ['required', 'string', 'max:255'],
            'sender_email' => ['required', 'email', 'max:255'],
            'recipient_contributor_ids' => ['required', 'array', 'min:1'],
            'recipient_contributor_ids.*' => ['required', 'integer', 'exists:resource_contributors,id'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'send_copy_to_sender' => ['boolean'],
            // Honeypot field - should be empty
            'website' => ['nullable', 'string', 'max:0'],
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sender_name.required' => 'Please enter your name.',
            'sender_email.required' => 'Please enter your email address.',
            'sender_email.email' => 'Please enter a valid email address.',
            'recipient_contributor_ids.required' => 'Please select at least one recipient.',
            'recipient_contributor_ids.min' => 'Please select at least one recipient.',
            'message.required' => 'Please enter a message.',
            'message.min' => 'Your message must be at least 10 characters long.',
            'message.max' => 'Your message must not exceed 5000 characters.',
        ];
    }

    /**
     * Check if the honeypot field was triggered (spam bot detected).
     */
    public function isHoneypotTriggered(): bool
    {
        $website = $this->input('website');

        return $website !== null && $website !== '';
    }
}
