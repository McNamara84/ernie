import type React from 'react';

interface DocsSectionProps {
    id: string;
    title: string;
    icon: React.ComponentType<{ className?: string }>;
    children: React.ReactNode;
}

export function DocsSection({ id, title, icon: Icon, children }: DocsSectionProps) {
    return (
        <section id={id} className="scroll-mt-20 space-y-4">
            <div className="flex items-center gap-3 border-b pb-3">
                <div className="flex size-10 items-center justify-center rounded-lg bg-primary/10">
                    <Icon className="size-5 text-primary" />
                </div>
                <h2 className="text-2xl font-bold tracking-tight">{title}</h2>
            </div>
            <div className="prose dark:prose-invert max-w-none">{children}</div>
        </section>
    );
}
