import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';
import { toast } from 'sonner';

import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import type { SharedData } from '@/types';

export function ReleaseDataEmbargoButton({ resourceId, onReleased }: { resourceId: number; onReleased?: (synchronized: boolean) => void }) {
    const {
        props: { canReleaseData },
    } = usePage<SharedData>();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const release = async () => {
        setBusy(true);
        try {
            const { data } = await axios.post<{ message: string; synchronized: boolean }>(`/resources/${resourceId}/release-data-embargo`);
            if (data.synchronized) toast.success(data.message);
            else toast.warning(data.message);
            if (onReleased) onReleased(data.synchronized);
            else router.reload();
        } catch (error) {
            toast.error(
                axios.isAxiosError(error) ? (error.response?.data?.message ?? 'The data could not be released.') : 'The data could not be released.',
            );
        } finally {
            setBusy(false);
        }
    };
    if (canReleaseData === false) return null;
    return (
        <>
            <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => setOpen(true)}>
                {busy ? 'Releasing data…' : 'Release data'}
            </Button>
            <AlertDialog open={open} onOpenChange={setOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Release this resource&apos;s data?</AlertDialogTitle>
                        <AlertDialogDescription>
                            The data embargo will end. Downloads will become available according to this resource&apos;s download settings.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={() => void release()}>Release data</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
