import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Progress } from '@/components/ui/progress';

interface EditorLoadingModalProps {
    progress: number;
    message: string;
    error?: string | null;
    onRetry?: () => void;
    onGoBack?: () => void;
}

export function EditorLoadingModal({ progress, message, error = null, onRetry, onGoBack }: EditorLoadingModalProps) {
    const normalizedProgress = Math.max(0, Math.min(100, Math.round(progress)));
    const hasError = error !== null;

    return (
        <Dialog open>
            <DialogContent
                showCloseButton={false}
                className="sm:max-w-xl"
                data-testid="editor-loading-modal"
                onEscapeKeyDown={(event) => event.preventDefault()}
                onPointerDownOutside={(event) => event.preventDefault()}
                onInteractOutside={(event) => event.preventDefault()}
            >
                <DialogHeader>
                    <DialogTitle>{hasError ? 'Data Editor could not be loaded' : 'Loading Data Editor'}</DialogTitle>
                    <DialogDescription>
                        {hasError
                            ? 'ERNIE could not prepare this resource for editing.'
                            : 'The selected resource and the editor workspace are being prepared.'}
                    </DialogDescription>
                </DialogHeader>

                {hasError ? (
                    <p className="text-sm text-destructive" role="alert" data-testid="editor-loading-error">
                        {error}
                    </p>
                ) : (
                    <div className="space-y-4" aria-busy="true">
                        <Progress
                            value={normalizedProgress}
                            aria-label="Data Editor loading progress"
                            aria-valuetext={`${normalizedProgress}% complete`}
                            className="[&_[data-slot=progress-indicator]]:motion-reduce:transition-none"
                        />
                        <div className="flex items-start justify-between gap-4 text-sm">
                            <p className="min-h-10 text-muted-foreground" role="status" aria-live="polite" data-testid="editor-loading-message">
                                {message}
                            </p>
                            <span className="shrink-0 font-medium tabular-nums" aria-hidden="true" data-testid="editor-loading-percentage">
                                {normalizedProgress}%
                            </span>
                        </div>
                    </div>
                )}

                {hasError && (
                    <DialogFooter>
                        {onGoBack && (
                            <Button type="button" variant="outline" onClick={onGoBack}>
                                Go back
                            </Button>
                        )}
                        {onRetry && (
                            <Button type="button" onClick={onRetry}>
                                Try again
                            </Button>
                        )}
                    </DialogFooter>
                )}
            </DialogContent>
        </Dialog>
    );
}
