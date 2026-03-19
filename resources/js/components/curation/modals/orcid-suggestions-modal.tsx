import { ExternalLink, Sparkles } from 'lucide-react';
import { useCallback, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import type { PendingOrcidAffiliation, PendingOrcidData, SelectedPendingData } from '@/hooks/use-orcid-autofill';

export interface OrcidSuggestionsModalProps {
    /** Whether the modal is open */
    open: boolean;
    /** Callback when the modal open state changes */
    onOpenChange: (open: boolean) => void;
    /** The pending ORCID data to display */
    pendingData: PendingOrcidData;
    /** Callback when user accepts selected data */
    onAccept: (selected: SelectedPendingData) => void;
    /** Callback when user discards all pending data */
    onDiscard: () => void;
}

/**
 * Modal displaying additional ORCID data available for curator review.
 * Shows affiliations (new/different), name differences, and email suggestions
 * with per-field checkboxes for selective acceptance.
 */
export function OrcidSuggestionsModal({ open, onOpenChange, pendingData, onAccept, onDiscard }: OrcidSuggestionsModalProps) {
    // Track which affiliations are selected (new = pre-checked, different = unchecked)
    const [selectedAffiliations, setSelectedAffiliations] = useState<Set<number>>(() => {
        const initial = new Set<number>();
        pendingData.affiliations.forEach((aff, idx) => {
            if (aff.status === 'new') initial.add(idx);
        });
        return initial;
    });

    const [applyFirstName, setApplyFirstName] = useState(false);
    const [applyLastName, setApplyLastName] = useState(false);
    const [applyEmail, setApplyEmail] = useState(false);

    const toggleAffiliation = useCallback((index: number) => {
        setSelectedAffiliations((prev) => {
            const next = new Set(prev);
            if (next.has(index)) {
                next.delete(index);
            } else {
                next.add(index);
            }
            return next;
        });
    }, []);

    const handleAccept = useCallback(() => {
        const selectedAffs = pendingData.affiliations.filter((_, idx) => selectedAffiliations.has(idx));
        onAccept({
            affiliations: selectedAffs,
            applyFirstName,
            applyLastName,
            applyEmail,
        });
        onOpenChange(false);
    }, [pendingData.affiliations, selectedAffiliations, applyFirstName, applyLastName, applyEmail, onAccept, onOpenChange]);

    const handleDiscard = useCallback(() => {
        onDiscard();
        onOpenChange(false);
    }, [onDiscard, onOpenChange]);

    const hasAffiliations = pendingData.affiliations.length > 0;
    const hasNameDiffs = pendingData.firstNameDiff !== null || pendingData.lastNameDiff !== null;
    const hasEmailSuggestion = pendingData.emailSuggestion !== null;

    const hasAnySelected = selectedAffiliations.size > 0 || applyFirstName || applyLastName || applyEmail;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Sparkles className="h-5 w-5 text-primary" aria-hidden="true" />
                        Additional ORCID Data Available
                    </DialogTitle>
                    <DialogDescription>
                        The ORCID profile contains data that differs from or extends the current entry. Select which items to apply.
                    </DialogDescription>
                </DialogHeader>

                <div className="max-h-[60vh] space-y-4 overflow-y-auto py-4">
                    {/* Affiliations Section */}
                    {hasAffiliations && (
                        <div className="space-y-3">
                            <h4 className="text-sm font-semibold text-foreground">Affiliations</h4>
                            <div className="space-y-2">
                                {pendingData.affiliations.map((aff, idx) => (
                                    <AffiliationRow
                                        key={`${aff.value}-${idx}`}
                                        affiliation={aff}
                                        checked={selectedAffiliations.has(idx)}
                                        onCheckedChange={() => toggleAffiliation(idx)}
                                        id={`orcid-aff-${idx}`}
                                    />
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Name Differences Section */}
                    {hasNameDiffs && (
                        <>
                            {hasAffiliations && <Separator />}
                            <div className="space-y-3">
                                <h4 className="text-sm font-semibold text-foreground">Name</h4>
                                <div className="space-y-2">
                                    {pendingData.firstNameDiff && (
                                        <DiffRow
                                            id="orcid-firstname"
                                            label="First Name"
                                            currentValue={pendingData.firstNameDiff.current}
                                            orcidValue={pendingData.firstNameDiff.orcid}
                                            checked={applyFirstName}
                                            onCheckedChange={setApplyFirstName}
                                        />
                                    )}
                                    {pendingData.lastNameDiff && (
                                        <DiffRow
                                            id="orcid-lastname"
                                            label="Last Name"
                                            currentValue={pendingData.lastNameDiff.current}
                                            orcidValue={pendingData.lastNameDiff.orcid}
                                            checked={applyLastName}
                                            onCheckedChange={setApplyLastName}
                                        />
                                    )}
                                </div>
                            </div>
                        </>
                    )}

                    {/* Email Section */}
                    {hasEmailSuggestion && (
                        <>
                            {(hasAffiliations || hasNameDiffs) && <Separator />}
                            <div className="space-y-3">
                                <h4 className="text-sm font-semibold text-foreground">Email</h4>
                                <div className="flex items-start gap-3 rounded-md border p-3">
                                    <Checkbox
                                        id="orcid-email"
                                        checked={applyEmail}
                                        onCheckedChange={(checked) => setApplyEmail(checked === true)}
                                    />
                                    <Label htmlFor="orcid-email" className="cursor-pointer text-sm leading-relaxed">
                                        <span className="font-medium">{pendingData.emailSuggestion}</span>
                                    </Label>
                                </div>
                            </div>
                        </>
                    )}
                </div>

                <DialogFooter className="gap-2 sm:gap-0">
                    <Button type="button" variant="outline" onClick={handleDiscard}>
                        Discard All
                    </Button>
                    <Button type="button" onClick={handleAccept} disabled={!hasAnySelected}>
                        Accept Selected
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * A single affiliation row with checkbox, status badge, and details
 */
function AffiliationRow({
    affiliation,
    checked,
    onCheckedChange,
    id,
}: {
    affiliation: PendingOrcidAffiliation;
    checked: boolean;
    onCheckedChange: () => void;
    id: string;
}) {
    return (
        <div className="flex items-start gap-3 rounded-md border p-3">
            <Checkbox id={id} checked={checked} onCheckedChange={onCheckedChange} className="mt-0.5" />
            <Label htmlFor={id} className="flex-1 cursor-pointer space-y-1">
                <div className="flex items-center gap-2">
                    <span className="text-sm font-medium">{affiliation.value}</span>
                    <Badge variant={affiliation.status === 'new' ? 'default' : 'secondary'} className="text-[10px] uppercase">
                        {affiliation.status === 'new' ? 'New' : 'Different'}
                    </Badge>
                </div>
                {affiliation.status === 'different' && affiliation.existingValue && (
                    <p className="text-xs text-muted-foreground">
                        Currently: <span className="font-medium">{affiliation.existingValue}</span>
                    </p>
                )}
                {affiliation.rorId && (
                    <a
                        href={affiliation.rorId}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1 text-xs text-blue-600 hover:underline"
                        onClick={(e) => e.stopPropagation()}
                    >
                        ROR: {affiliation.rorId}
                        <ExternalLink className="h-3 w-3" />
                    </a>
                )}
            </Label>
        </div>
    );
}

/**
 * A name/email difference row showing current vs ORCID value
 */
function DiffRow({
    id,
    label,
    currentValue,
    orcidValue,
    checked,
    onCheckedChange,
}: {
    id: string;
    label: string;
    currentValue: string;
    orcidValue: string;
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
}) {
    return (
        <div className="flex items-start gap-3 rounded-md border p-3">
            <Checkbox id={id} checked={checked} onCheckedChange={(c) => onCheckedChange(c === true)} className="mt-0.5" />
            <Label htmlFor={id} className="flex-1 cursor-pointer space-y-1">
                <div className="text-sm font-medium">{label}</div>
                <div className="flex flex-col gap-0.5 text-xs">
                    <span className="text-muted-foreground">
                        Currently: <span className="font-medium">{currentValue}</span>
                    </span>
                    <span className="text-primary">
                        ORCID: <span className="font-medium">{orcidValue}</span>
                    </span>
                </div>
            </Label>
        </div>
    );
}

export default OrcidSuggestionsModal;
