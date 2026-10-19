import axios, { isAxiosError } from 'axios';
import { useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import type { ThesaurusReviewReminder } from '@/types/thesaurus-review';

type CompletionResponse = { thesaurusReviewReminder: ThesaurusReviewReminder; message?: string };

export function ThesaurusReviewStatus({ reminder }: { reminder: ThesaurusReviewReminder }) {
    const [responseStatus, setResponseStatus] = useState<ThesaurusReviewReminder | null>(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const pending = useRef(false);

    // Partial thesaurus reloads can still contain the original, open-quarter prop.
    // Completions are permanent within a quarter; newer server quarters take priority.
    const status =
        responseStatus && (responseStatus.period > reminder.period || (responseStatus.period === reminder.period && !responseStatus.isDue))
            ? responseStatus
            : reminder;

    const complete = async () => {
        if (pending.current || !status.isDue) return;
        pending.current = true;
        setProcessing(true);
        setError(null);

        try {
            const { data } = await axios.post<CompletionResponse>('/thesauri/review-completion', { period: status.period });
            setResponseStatus(data.thesaurusReviewReminder);
        } catch (failure) {
            if (isAxiosError<CompletionResponse>(failure) && failure.response?.status === 409 && failure.response.data.thesaurusReviewReminder) {
                setResponseStatus(failure.response.data.thesaurusReviewReminder);
                setError('The quarter has changed. Please review the current quarter before confirming again.');
            } else {
                setError('Could not complete the review. Please try again.');
            }
        } finally {
            pending.current = false;
            setProcessing(false);
        }
    };

    return (
        <div className="mb-5 space-y-3 rounded-lg border bg-muted/30 p-4" data-testid="thesaurus-review-status">
            <p className="font-medium">Quarterly thesaurus review · {status.period}</p>
            <p className="text-sm text-muted-foreground">
                Check the thesauri for updates, then mark the review as complete even if no updates are available. This completes the reminder for all
                Admins and Group Leaders and does not start any updates.
            </p>
            <p role="status" className="text-sm">
                {!status.isDue && (
                    <>
                        Review completed for this quarter.
                        {status.completedAt && (
                            <>
                                {' '}
                                Completed on <time dateTime={status.completedAt}>{new Date(status.completedAt).toLocaleDateString('en-GB')}</time>.
                            </>
                        )}
                    </>
                )}
            </p>
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            <Button
                type="button"
                variant="outline"
                className="h-auto max-w-full whitespace-normal"
                disabled={processing || !status.isDue}
                onClick={() => void complete()}
            >
                {processing ? 'Completing review…' : status.isDue ? "Mark this quarter's review as complete" : 'Review completed'}
            </Button>
        </div>
    );
}
