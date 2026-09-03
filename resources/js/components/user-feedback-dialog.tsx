import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { AlertCircle, ChevronDown, Heart, Lightbulb, MessageCircle, Send, Wrench } from 'lucide-react';
import { type FormEvent, useId, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { LoadingButton } from '@/components/ui/loading-button';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { SidebarGroup, SidebarGroupContent, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { Textarea } from '@/components/ui/textarea';
import { createFeedbackTechnicalSnapshot } from '@/lib/feedback-diagnostics';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';
import type {
    FeedbackDiagnosticEvent,
    FeedbackTechnicalSnapshot,
    UserFeedbackCategory,
    UserFeedbackRequest,
    UserFeedbackResponse,
} from '@/types/feedback';

const categories: Array<{
    value: UserFeedbackCategory;
    label: string;
    description: string;
    icon: typeof AlertCircle;
}> = [
    { value: 'problem', label: 'Problem', description: 'Something did not work', icon: AlertCircle },
    { value: 'idea', label: 'Idea', description: 'A possible improvement', icon: Lightbulb },
    { value: 'praise', label: 'Praise', description: 'Something worked well', icon: Heart },
    { value: 'other', label: 'Other', description: 'Anything else', icon: MessageCircle },
];

function diagnosticLabel(event: FeedbackDiagnosticEvent): string {
    if (event.type === 'navigation') return `Navigation · ${event.path}`;
    if (event.type === 'http_error') return `${event.method} ${event.path}${event.status ? ` · HTTP ${event.status}` : ''}`;
    return event.message;
}

interface FeedbackErrorDetails {
    message: string;
    field?: 'category' | 'message';
}

function responseDetails(error: unknown): FeedbackErrorDetails {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as { message?: unknown; errors?: Record<string, string[]> } | undefined;
        if (error.response?.status === 429) {
            return { message: 'You have submitted feedback several times. Please wait a few minutes and try again.' };
        }

        for (const field of ['category', 'message'] as const) {
            const fieldMessage = data?.errors?.[field]?.find((message) => typeof message === 'string');
            if (fieldMessage) return { message: fieldMessage, field };
        }

        const firstValidationMessage = data?.errors
            ? Object.values(data.errors)
                  .flat()
                  .find((message) => typeof message === 'string')
            : undefined;
        if (firstValidationMessage) return { message: firstValidationMessage };
        if (typeof data?.message === 'string' && data.message !== 'The given data was invalid.') return { message: data.message };
    }

    return { message: 'Feedback could not be submitted. Check your connection and try again.' };
}

export function UserFeedbackDialog() {
    const { auth } = usePage<SharedData>().props;
    const [open, setOpen] = useState(false);
    const [category, setCategory] = useState<UserFeedbackCategory | ''>('');
    const [message, setMessage] = useState('');
    const [snapshot, setSnapshot] = useState<FeedbackTechnicalSnapshot | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [categoryError, setCategoryError] = useState('');
    const [messageError, setMessageError] = useState('');
    const [submissionError, setSubmissionError] = useState('');
    const descriptionId = useId();
    const categoryErrorId = useId();
    const messageHelpId = useId();
    const messageErrorId = useId();
    const firstCategoryRef = useRef<HTMLButtonElement>(null);
    const messageRef = useRef<HTMLTextAreaElement>(null);

    const handleOpenChange = (nextOpen: boolean) => {
        if (submitting && !nextOpen) return;
        if (nextOpen) {
            setSnapshot(createFeedbackTechnicalSnapshot(auth.user.id));
            setSubmissionError('');
        }
        setOpen(nextOpen);
    };

    const resetAfterSuccess = () => {
        setCategory('');
        setMessage('');
        setCategoryError('');
        setMessageError('');
        setSubmissionError('');
        setSnapshot(null);
    };

    const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        const trimmedMessage = message.trim();
        const nextCategoryError = category === '' ? 'Choose what you would like to share.' : '';
        const nextMessageError =
            trimmedMessage.length < 10
                ? 'Enter at least 10 characters.'
                : trimmedMessage.length > 5000
                  ? 'Feedback must not exceed 5,000 characters.'
                  : '';

        setCategoryError(nextCategoryError);
        setMessageError(nextMessageError);
        setSubmissionError('');

        if (nextCategoryError || nextMessageError || snapshot === null || category === '') {
            requestAnimationFrame(() => (nextCategoryError ? firstCategoryRef.current : messageRef.current)?.focus());
            return;
        }

        setSubmitting(true);
        try {
            const payload: UserFeedbackRequest = {
                category,
                message: trimmedMessage,
                ...snapshot,
            };
            await axios.post<UserFeedbackResponse>('/feedback', payload);
            setOpen(false);
            resetAfterSuccess();
            toast.success('Thanks — your feedback has been submitted.');
        } catch (error) {
            const details = responseDetails(error);
            if (details.field === 'category') setCategoryError(details.message);
            if (details.field === 'message') setMessageError(details.message);
            if (details.field === undefined) setSubmissionError(details.message);
            requestAnimationFrame(() => {
                if (details.field === 'category') firstCategoryRef.current?.focus();
                if (details.field === 'message') messageRef.current?.focus();
            });
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <SidebarGroup className="px-0 py-0 group-data-[collapsible=icon]:p-0">
                <SidebarGroupContent>
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <DialogTrigger asChild>
                                <SidebarMenuButton
                                    type="button"
                                    tooltip="Give feedback"
                                    data-testid="user-feedback-trigger"
                                    className="text-neutral-600 hover:text-neutral-800 dark:text-neutral-300 dark:hover:text-neutral-100"
                                >
                                    <MessageCircle aria-hidden="true" />
                                    <span>Give feedback</span>
                                </SidebarMenuButton>
                            </DialogTrigger>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroupContent>
            </SidebarGroup>

            <DialogContent
                className="max-h-[min(90vh,52rem)] overflow-y-auto sm:max-w-2xl"
                data-testid="user-feedback-dialog"
                aria-describedby={descriptionId}
                onEscapeKeyDown={(event) => submitting && event.preventDefault()}
                onPointerDownOutside={(event) => submitting && event.preventDefault()}
            >
                <DialogHeader>
                    <DialogTitle>Give feedback</DialogTitle>
                    <DialogDescription id={descriptionId}>
                        Help us improve ERNIE. Tell us what worked well, what got in your way, or what you would change. It usually takes less than a
                        minute.
                    </DialogDescription>
                </DialogHeader>

                <form className="space-y-5" onSubmit={handleSubmit} noValidate>
                    <fieldset className="space-y-3" disabled={submitting}>
                        <legend className="text-sm font-medium">
                            What would you like to share? <span aria-hidden="true">*</span>
                        </legend>
                        <RadioGroup
                            value={category}
                            onValueChange={(value) => {
                                setCategory(value as UserFeedbackCategory);
                                setCategoryError('');
                            }}
                            className="grid grid-cols-1 gap-2 sm:grid-cols-2"
                            aria-invalid={categoryError ? true : undefined}
                            aria-describedby={categoryError ? categoryErrorId : undefined}
                            required
                        >
                            {categories.map((item) => {
                                const CategoryIcon = item.icon;
                                const selected = category === item.value;
                                return (
                                    <label
                                        key={item.value}
                                        htmlFor={`feedback-category-${item.value}`}
                                        className={cn(
                                            'flex min-h-14 cursor-pointer items-center gap-3 rounded-lg border p-3 transition-colors',
                                            'hover:border-gfz-primary/60 hover:bg-gfz-primary/5',
                                            'focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50',
                                            selected && 'border-gfz-primary bg-gfz-primary/10',
                                        )}
                                    >
                                        <RadioGroupItem
                                            ref={item.value === 'problem' ? firstCategoryRef : undefined}
                                            id={`feedback-category-${item.value}`}
                                            value={item.value}
                                        />
                                        <CategoryIcon className="size-4 shrink-0 text-gfz-primary" aria-hidden="true" />
                                        <span className="min-w-0">
                                            <span className="block text-sm font-medium">{item.label}</span>
                                            <span className="block text-xs text-muted-foreground">{item.description}</span>
                                        </span>
                                    </label>
                                );
                            })}
                        </RadioGroup>
                        {categoryError && (
                            <p id={categoryErrorId} className="text-sm text-destructive" role="alert">
                                {categoryError}
                            </p>
                        )}
                    </fieldset>

                    <div className="space-y-2">
                        <label htmlFor="user-feedback-message" className="text-sm font-medium">
                            Your feedback <span aria-hidden="true">*</span>
                        </label>
                        <Textarea
                            ref={messageRef}
                            id="user-feedback-message"
                            value={message}
                            onChange={(event) => {
                                setMessage(event.target.value);
                                if (messageError) setMessageError('');
                            }}
                            placeholder="Tell us what happened, what worked well, or what would improve your work."
                            rows={6}
                            minLength={10}
                            maxLength={5000}
                            required
                            disabled={submitting}
                            aria-invalid={messageError ? true : undefined}
                            aria-describedby={[messageHelpId, messageError ? messageErrorId : ''].filter(Boolean).join(' ')}
                        />
                        <div id={messageHelpId} className="flex justify-between gap-4 text-xs text-muted-foreground">
                            <span>Do not include confidential or personal data.</span>
                            <span className="shrink-0 tabular-nums">{message.length.toLocaleString('en-US')} / 5,000</span>
                        </div>
                        {messageError && (
                            <p id={messageErrorId} className="text-sm text-destructive" role="alert">
                                {messageError}
                            </p>
                        )}
                    </div>

                    <div className="rounded-lg border bg-muted/40 p-3 text-sm" data-testid="user-feedback-identity">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">Sent as</p>
                        <p className="mt-1 font-medium">{auth.user.name}</p>
                        <p className="break-all text-muted-foreground">{auth.user.email}</p>
                    </div>

                    {snapshot && (
                        <Collapsible className="rounded-lg border" data-testid="user-feedback-technical-details">
                            <CollapsibleTrigger asChild>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="group w-full justify-between rounded-lg px-3 py-2"
                                    disabled={submitting}
                                >
                                    <span className="flex items-center gap-2">
                                        <Wrench className="size-4 text-muted-foreground" aria-hidden="true" />
                                        Technical details included
                                        <span className="text-xs font-normal text-muted-foreground">
                                            ({snapshot.diagnostics.length} recent events)
                                        </span>
                                    </span>
                                    <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180" aria-hidden="true" />
                                </Button>
                            </CollapsibleTrigger>
                            <CollapsibleContent className="border-t px-3 py-3 text-xs">
                                <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                                    <dt className="font-medium">Page</dt>
                                    <dd className="min-w-0 break-all text-muted-foreground">{snapshot.page.path}</dd>
                                    <dt className="font-medium">Theme</dt>
                                    <dd className="text-muted-foreground">
                                        {snapshot.environment.appearance} (resolved: {snapshot.environment.resolved_theme})
                                    </dd>
                                    <dt className="font-medium">Viewport</dt>
                                    <dd className="text-muted-foreground">
                                        {snapshot.environment.viewport_width} × {snapshot.environment.viewport_height} CSS px · DPR{' '}
                                        {snapshot.environment.device_pixel_ratio}
                                    </dd>
                                    <dt className="font-medium">Locale</dt>
                                    <dd className="text-muted-foreground">
                                        {snapshot.environment.locale} · {snapshot.environment.timezone}
                                    </dd>
                                    <dt className="font-medium">Browser</dt>
                                    <dd className="text-muted-foreground">The request User-Agent is added securely by the server.</dd>
                                </dl>

                                <div className="mt-3 border-t pt-3">
                                    <p className="font-medium">Recent session diagnostics</p>
                                    {snapshot.diagnostics.length === 0 ? (
                                        <p className="mt-1 text-muted-foreground">No diagnostic events are available yet.</p>
                                    ) : (
                                        <ol className="mt-2 space-y-2">
                                            {snapshot.diagnostics.map((diagnostic, index) => (
                                                <li key={`${diagnostic.occurred_at}-${index}`} className="rounded-md bg-muted px-2 py-1.5">
                                                    <span className="font-medium">{diagnosticLabel(diagnostic)}</span>
                                                    <span className="mt-0.5 block text-muted-foreground">{diagnostic.occurred_at}</span>
                                                </li>
                                            ))}
                                        </ol>
                                    )}
                                </div>
                            </CollapsibleContent>
                        </Collapsible>
                    )}

                    <div className="rounded-lg bg-muted/50 p-3 text-xs leading-relaxed text-muted-foreground">
                        Your feedback, name, email address and the technical details listed above will be emailed to all active ERNIE administrators.
                        No separate feedback record is created in ERNIE. For urgent technical support, use the contact details in the{' '}
                        <a href="/legal-notice" target="_blank" rel="noreferrer" className="font-medium text-primary underline underline-offset-2">
                            Legal Notice
                        </a>
                        . Read the{' '}
                        <a
                            href="https://dataservices.gfz-potsdam.de/web/about-us/data-protection"
                            target="_blank"
                            rel="noreferrer"
                            className="font-medium text-primary underline underline-offset-2"
                        >
                            Data Privacy Protection
                        </a>
                        .
                    </div>

                    {submissionError && (
                        <div
                            className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive"
                            role="alert"
                        >
                            <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            <span>{submissionError}</span>
                        </div>
                    )}

                    <DialogFooter className="gap-2 sm:gap-2">
                        <Button type="button" variant="outline" onClick={() => handleOpenChange(false)} disabled={submitting}>
                            Cancel
                        </Button>
                        <LoadingButton type="submit" loading={submitting} className="gap-2">
                            {!submitting && <Send className="size-4" aria-hidden="true" />}
                            {submitting ? 'Sending…' : 'Send feedback'}
                        </LoadingButton>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
