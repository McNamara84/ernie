import { useId } from 'react';

import type { PublicNews } from '@/types/news';

export function HomeNews({ news }: { news: PublicNews }) {
    const titleId = useId();

    return (
        <article aria-labelledby={titleId} className="rounded-xl border border-primary/15 bg-background p-5 shadow-sm sm:p-6">
            <p className="mb-2 text-xs font-semibold tracking-widest text-muted-foreground uppercase">Latest news</p>
            <h3 id={titleId} className="mb-2 text-lg font-semibold">
                {news.title}
            </h3>
            <div
                className="min-w-0 text-sm leading-7 break-words text-muted-foreground [&_a]:font-medium [&_a]:text-foreground [&_a]:underline [&_a]:underline-offset-4 [&_blockquote]:border-l-2 [&_blockquote]:pl-4 [&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_h4]:mt-4 [&_h4]:font-semibold [&_h5]:mt-3 [&_h5]:font-semibold [&_h6]:mt-3 [&_h6]:font-semibold [&_img]:h-auto [&_img]:max-w-full [&_li]:my-1 [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:my-2 [&_pre]:overflow-x-auto [&_pre]:rounded [&_pre]:bg-muted [&_pre]:p-3 [&_ul]:list-disc [&_ul]:pl-6"
                dangerouslySetInnerHTML={{ __html: news.html }}
            />
        </article>
    );
}
