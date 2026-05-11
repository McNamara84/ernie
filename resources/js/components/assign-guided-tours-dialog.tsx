import { router } from '@inertiajs/react';
import { GraduationCap } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { LoadingButton } from '@/components/ui/loading-button';

type GuidedTourAssignmentSummary = {
    guided_tour_id: number;
    status: string;
    assignment_source: string;
    assigned_at: string | null;
    completed_at: string | null;
};

type AssignableGuidedTour = {
    id: number;
    key: string;
    version: number;
    name: string;
    description: string;
    start_route: string;
    target_roles: string[];
};

type AssignGuidedToursUser = {
    id: number;
    name: string;
    role: string;
    guided_tour_assignments?: GuidedTourAssignmentSummary[];
};

interface AssignGuidedToursDialogProps {
    user: AssignGuidedToursUser;
    tours: AssignableGuidedTour[];
    disabled?: boolean;
}

function formatAssignmentStatus(status: string | undefined): string {
    return status
        ? status
              .split('_')
              .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
              .join(' ')
        : 'Not Assigned';
}

export function AssignGuidedToursDialog({ user, tours, disabled = false }: AssignGuidedToursDialogProps) {
    const [open, setOpen] = useState(false);
    const [selectedTourIds, setSelectedTourIds] = useState<number[]>([]);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const eligibleTours = useMemo(
        () => tours.filter((tour) => tour.target_roles.includes(user.role)),
        [tours, user.role],
    );

    const assignmentByTourId = useMemo(
        () =>
            new Map(
                (user.guided_tour_assignments ?? []).map((assignment) => [assignment.guided_tour_id, assignment]),
            ),
        [user.guided_tour_assignments],
    );

    if (eligibleTours.length === 0) {
        return null;
    }

    const toggleSelection = (tourId: number, checked: boolean) => {
        setSelectedTourIds((currentSelection) => {
            if (checked) {
                return currentSelection.includes(tourId) ? currentSelection : [...currentSelection, tourId];
            }

            return currentSelection.filter((currentTourId) => currentTourId !== tourId);
        });
    };

    const handleSubmit = () => {
        if (selectedTourIds.length === 0) {
            return;
        }

        setIsSubmitting(true);

        router.post(
            `/users/${user.id}/guided-tours`,
            { tour_ids: selectedTourIds },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Guided tours assigned successfully');
                    setOpen(false);
                    setSelectedTourIds([]);
                    setIsSubmitting(false);
                },
                onError: (errors) => {
                    const firstErrorValue: unknown = Object.values(errors)[0];
                    const errorMessage = typeof firstErrorValue === 'string' && firstErrorValue.length > 0 ? firstErrorValue : 'Failed to assign guided tours';
                    toast.error(errorMessage);
                    setIsSubmitting(false);
                },
                onFinish: () => {
                    setIsSubmitting(false);
                },
            },
        );
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(nextOpen) => {
                setOpen(nextOpen);
                if (!nextOpen) {
                    setSelectedTourIds([]);
                }
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline" size="sm" disabled={disabled} aria-label={`Assign tours to ${user.name}`}>
                    <GraduationCap className="mr-1 h-4 w-4" />
                    Assign Tours
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-[560px]">
                <DialogHeader>
                    <DialogTitle>Assign Guided Tours</DialogTitle>
                    <DialogDescription>
                        Select one or more tours to replay for {user.name}. The selected tours will start again on the next login.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-3 py-2">
                    {eligibleTours.map((tour) => {
                        const assignment = assignmentByTourId.get(tour.id);
                        const isChecked = selectedTourIds.includes(tour.id);

                        return (
                            <label
                                key={tour.id}
                                className="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/40"
                            >
                                <Checkbox
                                    aria-label={tour.name}
                                    checked={isChecked}
                                    onCheckedChange={(checked) => toggleSelection(tour.id, checked === true)}
                                    className="mt-1"
                                />
                                <div className="min-w-0 flex-1 space-y-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium text-foreground">{tour.name}</span>
                                        <Badge variant="outline">v{tour.version}</Badge>
                                        <Badge variant={assignment?.status === 'completed' ? 'secondary' : 'outline'}>
                                            {formatAssignmentStatus(assignment?.status)}
                                        </Badge>
                                    </div>
                                    <p className="text-sm text-muted-foreground">{tour.description}</p>
                                </div>
                            </label>
                        );
                    })}
                </div>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={isSubmitting}>
                        Cancel
                    </Button>
                    <LoadingButton type="button" onClick={handleSubmit} loading={isSubmitting} disabled={selectedTourIds.length === 0}>
                        Assign Selected Tours
                    </LoadingButton>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}