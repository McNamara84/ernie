import { useState, useCallback, useEffect, useRef, FormEvent, ChangeEvent } from 'react';
import { X, Send, AlertCircle, CheckCircle, Loader2, Mail, User } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { withBasePath } from '@/lib/base-path';

interface ContactPerson {
    id: number;
    name: string;
    email: string | null;
    affiliations: string[];
}

interface ContactModalProps {
    isOpen: boolean;
    onClose: () => void;
    resourceId: number;
    contactPersons: ContactPerson[];
    selectedContactPerson: ContactPerson | null;
}

const MAX_MESSAGE_LENGTH = 5000;

/**
 * Contact Modal for sending messages to contact persons
 * 
 * Features:
 * - Pre-selects clicked contact person or all if "send to all" was clicked
 * - Honeypot field for spam protection
 * - Character counter for message
 * - Optional copy to sender
 */
export function ContactModal({
    isOpen,
    onClose,
    resourceId,
    contactPersons,
    selectedContactPerson,
}: ContactModalProps) {
    // Form state
    const [senderName, setSenderName] = useState('');
    const [senderEmail, setSenderEmail] = useState('');
    const [message, setMessage] = useState('');
    const [sendCopy, setSendCopy] = useState(false);
    const [selectedRecipients, setSelectedRecipients] = useState<number[]>([]);
    
    // Honeypot field (should remain empty)
    const [website, setWebsite] = useState('');
    
    // UI state
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState(false);
    
    const modalRef = useRef<HTMLDivElement>(null);
    const firstInputRef = useRef<HTMLInputElement>(null);

    // Initialize selected recipients when modal opens
    useEffect(() => {
        if (isOpen) {
            if (selectedContactPerson) {
                setSelectedRecipients([selectedContactPerson.id]);
            } else {
                // "Send to all" was clicked
                setSelectedRecipients(contactPersons.map((cp) => cp.id));
            }
            setError(null);
            setSuccess(false);
            
            // Focus first input after modal opens
            setTimeout(() => {
                firstInputRef.current?.focus();
            }, 100);
        }
    }, [isOpen, selectedContactPerson, contactPersons]);

    // Handle escape key and click outside
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && isOpen) {
                onClose();
            }
        };

        const handleClickOutside = (e: MouseEvent) => {
            if (modalRef.current && !modalRef.current.contains(e.target as Node)) {
                onClose();
            }
        };

        if (isOpen) {
            document.addEventListener('keydown', handleKeyDown);
            document.addEventListener('mousedown', handleClickOutside);
            // Prevent body scroll
            document.body.style.overflow = 'hidden';
        }

        return () => {
            document.removeEventListener('keydown', handleKeyDown);
            document.removeEventListener('mousedown', handleClickOutside);
            document.body.style.overflow = '';
        };
    }, [isOpen, onClose]);

    const handleRecipientToggle = useCallback((personId: number) => {
        setSelectedRecipients((prev) => {
            if (prev.includes(personId)) {
                return prev.filter((id) => id !== personId);
            }
            return [...prev, personId];
        });
    }, []);

    const handleSubmit = useCallback(
        async (e: FormEvent) => {
            e.preventDefault();
            setError(null);

            // Client-side validation
            if (!senderName.trim()) {
                setError('Please enter your name.');
                return;
            }
            if (!senderEmail.trim()) {
                setError('Please enter your email address.');
                return;
            }
            if (selectedRecipients.length === 0) {
                setError('Please select at least one recipient.');
                return;
            }
            if (message.trim().length < 10) {
                setError('Your message must be at least 10 characters long.');
                return;
            }

            setIsSubmitting(true);

            try {
                const response = await fetch(
                    withBasePath(`/api/v1/resources/${resourceId}/contact`),
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                        },
                        body: JSON.stringify({
                            sender_name: senderName.trim(),
                            sender_email: senderEmail.trim(),
                            recipient_contributor_ids: selectedRecipients,
                            message: message.trim(),
                            send_copy_to_sender: sendCopy,
                            website, // Honeypot field
                        }),
                    },
                );

                const data = await response.json();

                if (!response.ok) {
                    if (response.status === 429) {
                        setError('You have sent too many messages. Please try again later.');
                    } else if (response.status === 422 && data.errors) {
                        // Validation errors
                        const firstError = Object.values(data.errors)[0];
                        setError(Array.isArray(firstError) ? firstError[0] : String(firstError));
                    } else {
                        setError(data.message || 'An error occurred. Please try again.');
                    }
                    return;
                }

                setSuccess(true);
                
                // Clear form
                setSenderName('');
                setSenderEmail('');
                setMessage('');
                setSendCopy(false);
                setSelectedRecipients([]);

                // Close modal after delay
                setTimeout(() => {
                    onClose();
                    setSuccess(false);
                }, 2500);
            } catch {
                setError('Network error. Please check your connection and try again.');
            } finally {
                setIsSubmitting(false);
            }
        },
        [senderName, senderEmail, selectedRecipients, message, sendCopy, website, resourceId, onClose],
    );

    const handleMessageChange = useCallback((e: ChangeEvent<HTMLTextAreaElement>) => {
        const value = e.target.value;
        if (value.length <= MAX_MESSAGE_LENGTH) {
            setMessage(value);
        }
    }, []);

    if (!isOpen) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div
                ref={modalRef}
                className="w-full max-w-lg rounded-lg bg-white shadow-xl"
                role="dialog"
                aria-modal="true"
                aria-labelledby="contact-modal-title"
            >
                {/* Header */}
                <div className="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                    <h2 id="contact-modal-title" className="text-lg font-semibold text-gray-900">
                        Send a Message
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                        aria-label="Close"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </div>

                {/* Success state */}
                {success ? (
                    <div className="flex flex-col items-center justify-center px-6 py-12">
                        <CheckCircle className="mb-4 h-16 w-16 text-green-500" />
                        <p className="text-center text-lg font-medium text-gray-900">
                            Message sent successfully!
                        </p>
                        <p className="mt-2 text-center text-sm text-gray-600">
                            The contact person(s) will receive your message shortly.
                        </p>
                    </div>
                ) : (
                    <form onSubmit={handleSubmit}>
                        <div className="max-h-[70vh] space-y-4 overflow-y-auto px-6 py-4">
                            {/* Error message */}
                            {error && (
                                <div className="flex items-start gap-2 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                                    <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                                    <span>{error}</span>
                                </div>
                            )}

                            {/* Sender name */}
                            <div>
                                <Label htmlFor="sender-name" className="flex items-center gap-1">
                                    <User className="h-4 w-4" />
                                    Your Name
                                </Label>
                                <Input
                                    ref={firstInputRef}
                                    id="sender-name"
                                    type="text"
                                    value={senderName}
                                    onChange={(e) => setSenderName(e.target.value)}
                                    placeholder="Enter your name"
                                    required
                                    className="mt-1"
                                />
                            </div>

                            {/* Sender email */}
                            <div>
                                <Label htmlFor="sender-email" className="flex items-center gap-1">
                                    <Mail className="h-4 w-4" />
                                    Your Email
                                </Label>
                                <Input
                                    id="sender-email"
                                    type="email"
                                    value={senderEmail}
                                    onChange={(e) => setSenderEmail(e.target.value)}
                                    placeholder="your.email@example.com"
                                    required
                                    className="mt-1"
                                />
                            </div>

                            {/* Recipients */}
                            {contactPersons.length > 1 && (
                                <div>
                                    <Label>Send to:</Label>
                                    <div className="mt-2 space-y-2 rounded-md border border-gray-200 p-3">
                                        {contactPersons.map((person) => (
                                            <label
                                                key={person.id}
                                                className="flex cursor-pointer items-center gap-2"
                                            >
                                                <Checkbox
                                                    checked={selectedRecipients.includes(person.id)}
                                                    onCheckedChange={() => handleRecipientToggle(person.id)}
                                                />
                                                <span className="text-sm text-gray-700">{person.name}</span>
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Message */}
                            <div>
                                <div className="flex items-center justify-between">
                                    <Label htmlFor="message">Your Message</Label>
                                    <span
                                        className={`text-xs ${
                                            message.length > MAX_MESSAGE_LENGTH * 0.9
                                                ? 'text-orange-600'
                                                : 'text-gray-400'
                                        }`}
                                    >
                                        {message.length}/{MAX_MESSAGE_LENGTH}
                                    </span>
                                </div>
                                <Textarea
                                    id="message"
                                    value={message}
                                    onChange={handleMessageChange}
                                    placeholder="Write your message here..."
                                    required
                                    rows={6}
                                    className="mt-1 resize-none"
                                />
                            </div>

                            {/* Send copy checkbox */}
                            <label className="flex cursor-pointer items-center gap-2">
                                <Checkbox
                                    checked={sendCopy}
                                    onCheckedChange={(checked) => setSendCopy(checked === true)}
                                />
                                <span className="text-sm text-gray-700">
                                    Send a copy of this message to my email
                                </span>
                            </label>

                            {/* Honeypot field - hidden from users */}
                            <input
                                type="text"
                                name="website"
                                value={website}
                                onChange={(e) => setWebsite(e.target.value)}
                                tabIndex={-1}
                                autoComplete="off"
                                className="absolute -left-[9999px] h-0 w-0 opacity-0"
                                aria-hidden="true"
                            />
                        </div>

                        {/* Footer */}
                        <div className="flex justify-end gap-3 border-t border-gray-200 px-6 py-4">
                            <Button type="button" variant="outline" onClick={onClose} disabled={isSubmitting}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={isSubmitting}>
                                {isSubmitting ? (
                                    <>
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        Sending...
                                    </>
                                ) : (
                                    <>
                                        <Send className="mr-2 h-4 w-4" />
                                        Send Message
                                    </>
                                )}
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}
