import type { DocsAction } from '@/data/docs-actions';

interface DocsActionReferenceProps {
    actions: DocsAction[];
    mode: 'test' | 'production';
}

export function DocsActionReference({ actions, mode }: DocsActionReferenceProps) {
    return (
        <div className="space-y-8">
            <p>
                Actions shown here follow your current permissions. A button may still be unavailable for a particular selection or record. DataCite
                requests use the <strong>{mode === 'test' ? 'test' : 'production'} environment</strong> for your account.
            </p>
            {actions.map((action) => (
                <article key={action.id} className="rounded-lg border bg-card p-4 sm:p-6" aria-labelledby={action.id}>
                    <h3 id={action.id} tabIndex={-1} className="scroll-mt-24 text-xl font-semibold focus-visible:outline focus-visible:outline-2">
                        {action.label}
                    </h3>
                    <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-[minmax(9rem,12rem)_minmax(0,1fr)]">
                        <dt className="font-semibold">When available</dt>
                        <dd>{action.requirements}</dd>
                        <dt className="font-semibold">On click</dt>
                        <dd>{action.onClick}</dd>
                        <dt className="font-semibold">After confirmation</dt>
                        <dd>{action.afterConfirmation}</dd>
                        <dt className="font-semibold">In ERNIE</dt>
                        <dd>{action.localEffect}</dd>
                        <dt className="font-semibold">Outside ERNIE</dt>
                        <dd>{action.externalEffect}</dd>
                        <dt className="font-semibold">Public result</dt>
                        <dd>{action.publicEffect}</dd>
                        <dt className="font-semibold">If it fails</dt>
                        <dd>{action.failure}</dd>
                    </dl>
                </article>
            ))}
        </div>
    );
}
