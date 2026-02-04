import { type HTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

/**
 * FormError component for displaying form validation errors.
 * 
 * This component is designed to work with Inertia.js forms where errors come from
 * server-side validation. It provides the same styling as shadcn/ui's FormMessage
 * but without requiring react-hook-form context.
 * 
 * For forms using react-hook-form, prefer using FormMessage from @/components/ui/form.
 * 
 * @example
 * // With Inertia Form
 * <Form {...AuthController.store.post()}>
 *     {({ errors }) => (
 *         <>
 *             <Input name="email" />
 *             <FormError message={errors.email} />
 *         </>
 *     )}
 * </Form>
 */
export function FormError({ message, className = '', ...props }: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    if (!message) {
        return null;
    }

    return (
        <p {...props} className={cn('text-[0.8rem] font-medium text-destructive', className)}>
            {message}
        </p>
    );
}

/**
 * @deprecated Use FormError instead. This alias is kept for backwards compatibility.
 */
export default function InputError(props: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    return <FormError {...props} />;
}
