import { Sparkles } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { PendingOrcidData, SelectedPendingData } from '@/hooks/use-orcid-autofill';

import { OrcidSuggestionsModal } from '../modals/orcid-suggestions-modal';

interface OrcidSuggestionsButtonProps {
    /** The pending ORCID data to review */
    pendingData: PendingOrcidData;
    /** Callback when user accepts selected data */
    onAccept: (selected: SelectedPendingData) => void;
    /** Callback when user discards all pending data */
    onDiscard: () => void;
}

/**
 * Button with count badge shown next to the Affiliations field when
 * ORCID data is pending review. Opens the OrcidSuggestionsModal on click.
 */
export function OrcidSuggestionsButton({ pendingData, onAccept, onDiscard }: OrcidSuggestionsButtonProps) {
    const [modalOpen, setModalOpen] = useState(false);

    const count =
        pendingData.affiliations.length +
        (pendingData.firstNameDiff ? 1 : 0) +
        (pendingData.lastNameDiff ? 1 : 0) +
        (pendingData.emailSuggestion ? 1 : 0);

    if (count === 0) return null;

    return (
        <>
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button type="button" variant="outline" size="sm" className="relative gap-1.5" onClick={() => setModalOpen(true)}>
                        <Sparkles className="h-4 w-4 text-primary" aria-hidden="true" />
                        <span>ORCID Suggestions</span>
                        <Badge variant="default" className="ml-1 h-5 min-w-5 rounded-full px-1.5 text-[10px]">
                            {count}
                        </Badge>
                    </Button>
                </TooltipTrigger>
                <TooltipContent side="top">Review additional data from the ORCID profile</TooltipContent>
            </Tooltip>

            <OrcidSuggestionsModal
                open={modalOpen}
                onOpenChange={setModalOpen}
                pendingData={pendingData}
                onAccept={onAccept}
                onDiscard={onDiscard}
            />
        </>
    );
}
