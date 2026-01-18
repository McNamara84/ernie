import { AlertTriangle } from 'lucide-react';

import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * Represents a single validation error from the JSON Schema validator.
 */
export interface ValidationError {
    /** The JSON path where the error occurred */
    path: string;
    /** Human-readable error message with technical details */
    message: string;
    /** The JSON Schema keyword that triggered the error */
    keyword: string;
    /** Additional context information */
    context: {
        raw_message: string;
    };
}

interface ValidationErrorModalProps {
    /** Whether the modal is open */
    open: boolean;
    /** Callback when open state changes */
    onOpenChange: (open: boolean) => void;
    /** Array of validation errors to display */
    errors: ValidationError[];
    /** Type of resource being exported */
    resourceType: 'Resource' | 'IGSN';
    /** The schema version used for validation */
    schemaVersion?: string;
}

/**
 * Modal dialog that displays JSON Schema validation errors.
 *
 * Used when a Resource or IGSN export fails validation against the DataCite schema.
 * Shows user-friendly error messages with expandable technical details.
 */
export function ValidationErrorModal({
    open,
    onOpenChange,
    errors,
    resourceType,
    schemaVersion = '4.6',
}: ValidationErrorModalProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[80vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 text-destructive">
                        <AlertTriangle className="size-5" />
                        JSON Export Failed
                    </DialogTitle>
                    <DialogDescription>
                        The {resourceType} export could not be created because the data does not conform to the DataCite
                        Schema {schemaVersion}. Please correct the following errors:
                    </DialogDescription>
                </DialogHeader>

                <div className="mt-4 space-y-2">
                    <Accordion type="single" collapsible className="w-full">
                        {errors.map((error, index) => (
                            <AccordionItem
                                key={`error-${index}`}
                                value={`error-${index}`}
                                className="rounded-lg border px-4"
                            >
                                <AccordionTrigger className="hover:no-underline">
                                    <span className="text-left font-medium text-destructive">{error.message}</span>
                                </AccordionTrigger>
                                <AccordionContent>
                                    <div className="space-y-2 text-sm text-muted-foreground">
                                        <p>
                                            <span className="font-medium">JSON Path:</span>{' '}
                                            <code className="rounded bg-muted px-1">{error.path}</code>
                                        </p>
                                        <p>
                                            <span className="font-medium">Validation Keyword:</span>{' '}
                                            <code className="rounded bg-muted px-1">{error.keyword}</code>
                                        </p>
                                        {error.context?.raw_message && (
                                            <p>
                                                <span className="font-medium">Technical Details:</span>{' '}
                                                <span className="font-mono text-xs">{error.context.raw_message}</span>
                                            </p>
                                        )}
                                    </div>
                                </AccordionContent>
                            </AccordionItem>
                        ))}
                    </Accordion>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
