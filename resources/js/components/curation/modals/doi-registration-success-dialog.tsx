import { motion } from 'framer-motion';
import { PartyPopper, Sparkles } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import type { PublishedRecordCounts } from '@/components/curation/types/datacite-form-types';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

interface DoiRegistrationSuccessDialogProps {
    open: boolean;
    doi: string;
    counts: PublishedRecordCounts;
    onContinue: () => void;
    redirectAfterMs?: number;
}

const CONFETTI_COLORS = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#eab308'];

export function DoiRegistrationSuccessDialog({ open, doi, counts, onContinue, redirectAfterMs = 5_000 }: DoiRegistrationSuccessDialogProps) {
    const [remainingSeconds, setRemainingSeconds] = useState(Math.ceil(redirectAfterMs / 1_000));
    const hasContinuedRef = useRef(false);
    const prefersReducedMotion = useMemo(
        () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true,
        [],
    );
    const particles = useMemo(
        () =>
            Array.from({ length: 42 }, (_, index) => ({
                id: index,
                left: `${(index * 37) % 100}%`,
                color: CONFETTI_COLORS[index % CONFETTI_COLORS.length],
                delay: (index % 9) * 0.08,
                rotation: (index * 47) % 360,
            })),
        [],
    );

    const continueOnce = useCallback(() => {
        if (hasContinuedRef.current) return;
        hasContinuedRef.current = true;
        onContinue();
    }, [onContinue]);

    useEffect(() => {
        if (!open) {
            hasContinuedRef.current = false;
            setRemainingSeconds(Math.ceil(redirectAfterMs / 1_000));
            return;
        }

        const startedAt = Date.now();
        const intervalId = window.setInterval(() => {
            const remainingMs = Math.max(0, redirectAfterMs - (Date.now() - startedAt));
            setRemainingSeconds(Math.ceil(remainingMs / 1_000));
        }, 250);
        const timeoutId = window.setTimeout(continueOnce, redirectAfterMs);

        return () => {
            window.clearInterval(intervalId);
            window.clearTimeout(timeoutId);
        };
    }, [continueOnce, open, redirectAfterMs]);

    return (
        <Dialog open={open} onOpenChange={() => undefined}>
            <DialogContent className="overflow-hidden border-primary/30 sm:max-w-[600px]" data-testid="doi-registration-success-dialog">
                {!prefersReducedMotion && (
                    <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true" data-testid="doi-success-confetti">
                        {particles.map((particle) => (
                            <motion.span
                                key={particle.id}
                                className="absolute top-[-10%] h-3 w-1.5 rounded-sm"
                                style={{ left: particle.left, backgroundColor: particle.color }}
                                initial={{ y: -20, rotate: particle.rotation, opacity: 1 }}
                                animate={{ y: 650, rotate: particle.rotation + 540, opacity: [1, 1, 0] }}
                                transition={{ duration: 3.3, delay: particle.delay, ease: 'easeIn' }}
                            />
                        ))}
                    </div>
                )}

                <DialogHeader className="relative items-center text-center">
                    <motion.div
                        initial={prefersReducedMotion ? false : { scale: 0.6, rotate: -12, opacity: 0 }}
                        animate={{ scale: 1, rotate: 0, opacity: 1 }}
                        className="mb-2 rounded-full bg-primary/10 p-4 text-primary"
                    >
                        <PartyPopper className="size-10" aria-hidden="true" />
                    </motion.div>
                    <DialogTitle className="flex items-center gap-2 text-2xl">
                        DOI registered! <Sparkles className="size-5 text-amber-500" aria-hidden="true" />
                    </DialogTitle>
                    <DialogDescription>
                        <strong>{doi}</strong> was successfully registered at DataCite.
                    </DialogDescription>
                </DialogHeader>

                <div className="relative py-5 text-center">
                    <p className="text-sm font-medium tracking-wide text-muted-foreground uppercase">Canonically published records in ERNIE</p>
                    <motion.p
                        className="mt-1 text-7xl font-black tracking-tight text-primary tabular-nums sm:text-8xl"
                        initial={prefersReducedMotion ? false : { scale: 0.75, opacity: 0 }}
                        animate={{ scale: 1, opacity: 1 }}
                        transition={{ delay: 0.15, type: 'spring', stiffness: 180 }}
                        data-testid="published-record-total"
                    >
                        {counts.total.toLocaleString('en-US')}
                    </motion.p>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {counts.resources.toLocaleString('en-US')} Resources + {counts.igsns.toLocaleString('en-US')} IGSNs
                    </p>
                </div>

                <DialogFooter className="relative flex-col gap-2 sm:flex-col">
                    <Button type="button" onClick={continueOnce} className="w-full">
                        Continue to Resources
                    </Button>
                    <p className="text-center text-xs text-muted-foreground" aria-live="polite">
                        Continuing automatically in {remainingSeconds} seconds…
                    </p>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
