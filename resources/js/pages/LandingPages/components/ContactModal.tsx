import { AlertCircle, CheckCircle, Loader2, Send } from 'lucide-react';
import { FormEvent, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import { withBasePath } from '@/lib/base-path';

interface ContactPerson {
    id: number;
    name: string;
    given_name: string | null;
    family_name: string | null;
    type: string;
    has_email: boolean;
}

interface ContactModalProps {
    isOpen: boolean;
    onClose: () => void;
    selectedPerson: ContactPerson | null;
    contactPersons: ContactPerson[];
    resourceId: number;
    datasetTitle: string;
}

type FormStatus = 'idle' | 'submitting' | 'success' | 'error';

/**
 * Contact Modal for Landing Pages
 *
 * Allows users to send messages to contact persons without exposing email addresses.
 * Includes honeypot spam protection.
 */
export function ContactModal({ isOpen, onClose, selectedPerson, contactPersons, resourceId, datasetTitle }: ContactModalProps) {
    const [formStatus, setFormStatus] = useState<FormStatus>('idle');
    const [errorMessage, setErrorMessage] = useState<string>('');

    // Form fields
    const [senderName, setSenderName] = useState('');
    const [senderEmail, setSenderEmail] = useState('');
    const [message, setMessage] = useState('');
    const [sendToAll, setSendToAll] = useState(selectedPerson === null);
    const [copyToSender, setCopyToSender] = useState(false);
    const [honeypot, setHoneypot] = useState(''); // Should remain empty

    const resetForm = () => {
        setSenderName('');
        setSenderEmail('');
        setMessage('');
        setSendToAll(selectedPerson === null);
        setCopyToSender(false);
        setHoneypot('');
        setFormStatus('idle');
        setErrorMessage('');
    };

    const handleClose = () => {
        if (formStatus !== 'submitting') {
            resetForm();
            onClose();
        }
    };

    const handleSubmit = async (e: FormEvent) => {
        e.preventDefault();

        // Client-side validation
        if (!senderName.trim()) {
            setErrorMessage('Please enter your name.');
            return;
        }
        if (!senderEmail.trim() || !senderEmail.includes('@')) {
            setErrorMessage('Please enter a valid email address.');
            return;
        }
        if (message.trim().length < 10) {
            setErrorMessage('Please enter a message (at least 10 characters).');
            return;
        }

        setFormStatus('submitting');
        setErrorMessage('');

        try {
            const response = await fetch(withBasePath(`/datasets/${resourceId}/contact`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    sender_name: senderName.trim(),
                    sender_email: senderEmail.trim(),
                    message: message.trim(),
                    send_to_all: sendToAll,
                    copy_to_sender: copyToSender,
                    resource_creator_id: sendToAll ? null : selectedPerson?.id,
                    // Honeypot field - bots will fill this
                    website_url: honeypot,
                }),
            });

            if (response.ok) {
                setFormStatus('success');
                // Auto-close after 3 seconds
                setTimeout(() => {
                    handleClose();
                }, 3000);
            } else {
                const data = await response.json();
                setErrorMessage(data.message || 'Failed to send message. Please try again.');
                setFormStatus('error');
            }
        } catch {
            setErrorMessage('Network error. Please try again.');
            setFormStatus('error');
        }
    };

    const recipientLabel = sendToAll ? `All contact persons (${contactPersons.length})` : selectedPerson?.name || 'Selected contact';

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && handleClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Contact Request</DialogTitle>
                    <DialogDescription>
                        Send a message regarding: <span className="font-medium">{datasetTitle}</span>
                    </DialogDescription>
                </DialogHeader>

                {formStatus === 'success' ? (
                    <div className="flex flex-col items-center gap-4 py-8">
                        <CheckCircle className="h-12 w-12 text-green-500" />
                        <p className="text-center font-medium text-green-700">Message sent successfully!</p>
                        <p className="text-center text-sm text-gray-500">
                            The contact person(s) will receive your message and can reply directly to your email.
                        </p>
                    </div>
                ) : (
                    <form onSubmit={handleSubmit} className="space-y-4">
                        {/* Recipient selection - only show if multiple persons and not pre-selected for all */}
                        {contactPersons.length > 1 && selectedPerson !== null && (
                            <div className="space-y-2">
                                <Label>Send to</Label>
                                <RadioGroup value={sendToAll ? 'all' : 'single'} onValueChange={(v: string) => setSendToAll(v === 'all')}>
                                    <div className="flex items-center space-x-2">
                                        <RadioGroupItem value="single" id="single" />
                                        <Label htmlFor="single" className="font-normal">
                                            {selectedPerson.name} only
                                        </Label>
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <RadioGroupItem value="all" id="all" />
                                        <Label htmlFor="all" className="font-normal">
                                            All contact persons ({contactPersons.length})
                                        </Label>
                                    </div>
                                </RadioGroup>
                            </div>
                        )}

                        {/* Recipient display for single selection or "all" */}
                        {(contactPersons.length === 1 || selectedPerson === null) && (
                            <div className="rounded-lg bg-gray-50 p-3 text-sm">
                                <span className="text-gray-500">To: </span>
                                <span className="font-medium">{recipientLabel}</span>
                            </div>
                        )}

                        {/* Sender name */}
                        <div className="space-y-2">
                            <Label htmlFor="sender-name">Your name *</Label>
                            <Input
                                id="sender-name"
                                type="text"
                                value={senderName}
                                onChange={(e) => setSenderName(e.target.value)}
                                placeholder="John Doe"
                                disabled={formStatus === 'submitting'}
                                required
                            />
                        </div>

                        {/* Sender email */}
                        <div className="space-y-2">
                            <Label htmlFor="sender-email">Your email *</Label>
                            <Input
                                id="sender-email"
                                type="email"
                                value={senderEmail}
                                onChange={(e) => setSenderEmail(e.target.value)}
                                placeholder="john.doe@example.com"
                                disabled={formStatus === 'submitting'}
                                required
                            />
                        </div>

                        {/* Message */}
                        <div className="space-y-2">
                            <Label htmlFor="message">Message *</Label>
                            <Textarea
                                id="message"
                                value={message}
                                onChange={(e) => setMessage(e.target.value)}
                                placeholder="Your message..."
                                rows={5}
                                disabled={formStatus === 'submitting'}
                                required
                                minLength={10}
                            />
                            <p className="text-xs text-gray-500">Minimum 10 characters</p>
                        </div>

                        {/* Copy to sender checkbox */}
                        <div className="flex items-center space-x-2">
                            <Checkbox
                                id="copy-to-sender"
                                checked={copyToSender}
                                onCheckedChange={(checked) => setCopyToSender(checked === true)}
                                disabled={formStatus === 'submitting'}
                            />
                            <Label htmlFor="copy-to-sender" className="text-sm font-normal">
                                Send me a copy of this message
                            </Label>
                        </div>

                        {/* Honeypot field - hidden from humans */}
                        <div className="absolute -left-[9999px] opacity-0" aria-hidden="true">
                            <Input
                                type="text"
                                name="website_url"
                                tabIndex={-1}
                                autoComplete="off"
                                value={honeypot}
                                onChange={(e) => setHoneypot(e.target.value)}
                            />
                        </div>

                        {/* Error message */}
                        {errorMessage && (
                            <div className="flex items-center gap-2 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                                <AlertCircle className="h-4 w-4 flex-shrink-0" />
                                {errorMessage}
                            </div>
                        )}

                        <DialogFooter className="gap-3 sm:gap-3">
                            <Button type="button" variant="outline" onClick={handleClose} disabled={formStatus === 'submitting'}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={formStatus === 'submitting'} className="gap-2" style={{ backgroundColor: '#0C2A63' }}>
                                {formStatus === 'submitting' ? (
                                    <>
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                        Sending...
                                    </>
                                ) : (
                                    <>
                                        <Send className="h-4 w-4" />
                                        Send Message
                                    </>
                                )}
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
