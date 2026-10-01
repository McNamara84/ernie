import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';

export function DeclineReasonDialog({ open, onClose, onConfirm }: { open: boolean; onClose: () => void; onConfirm: (reason: string) => void }) {
    const [reason, setReason] = useState('');
    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (!value) {
                    setReason('');
                    onClose();
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Keep current subject tagging</DialogTitle>
                    <DialogDescription>
                        Explain why the broader term should be retained. This unchanged case will not be suggested again.
                    </DialogDescription>
                </DialogHeader>
                <Textarea
                    aria-label="Reason for retaining broader terms"
                    maxLength={255}
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                />
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => {
                            setReason('');
                            onClose();
                        }}
                    >
                        Cancel
                    </Button>
                    <Button
                        disabled={!reason.trim()}
                        onClick={() => {
                            onConfirm(reason.trim());
                            setReason('');
                        }}
                    >
                        Keep with reason
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
