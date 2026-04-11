import { Skeleton } from '@/components/ui/skeleton';

interface FormSkeletonProps {
    /** Number of form field placeholders */
    fields?: number;
}

/**
 * Form skeleton loader mimicking labeled input fields.
 */
function FormSkeleton({ fields = 4 }: FormSkeletonProps) {
    return (
        <div data-slot="form-skeleton" className="space-y-6">
            {Array.from({ length: fields }).map((_, i) => (
                <div key={`field-${i}`} className="space-y-2">
                    <Skeleton className="h-4 w-24" />
                    <Skeleton className="h-9 w-full" />
                </div>
            ))}
            {/* Submit button area */}
            <Skeleton className="h-9 w-28" />
        </div>
    );
}

export { FormSkeleton, type FormSkeletonProps };
