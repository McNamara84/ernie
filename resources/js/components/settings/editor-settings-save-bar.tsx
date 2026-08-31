import { CheckCircle2, CircleAlert } from 'lucide-react';

import { Button } from '@/components/ui/button';

interface EditorSettingsSaveBarProps {
    isDirty: boolean;
    processing: boolean;
    recentlySuccessful: boolean;
}

export function EditorSettingsSaveBar({ isDirty, processing, recentlySuccessful }: EditorSettingsSaveBarProps) {
    const status = processing ? 'Saving changes…' : isDirty ? 'Unsaved changes' : recentlySuccessful ? 'Changes saved' : 'No unsaved changes';

    return (
        <div className="sticky top-16 z-30 flex flex-col gap-3 rounded-xl border bg-background/95 px-4 py-3 shadow-sm backdrop-blur-sm sm:flex-row sm:items-center sm:justify-between md:top-0">
            <div className="min-w-0">
                <h1 className="text-lg font-semibold">Editor Settings</h1>
                <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                    <span
                        className="inline-flex items-center gap-1.5 font-medium text-foreground"
                        data-testid="settings-save-status"
                        aria-live="polite"
                        aria-atomic="true"
                    >
                        {isDirty && !processing ? (
                            <CircleAlert className="size-4 text-amber-600" aria-hidden="true" />
                        ) : recentlySuccessful && !processing ? (
                            <CheckCircle2 className="size-4 text-emerald-600" aria-hidden="true" />
                        ) : null}
                        {status}
                    </span>
                    <span aria-hidden="true">·</span>
                    <span>Domains and datacenters save immediately.</span>
                </div>
            </div>
            <Button type="submit" className="shrink-0 self-start sm:self-auto" disabled={processing || !isDirty}>
                {processing ? 'Saving…' : 'Save changes'}
            </Button>
        </div>
    );
}
