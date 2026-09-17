import type { homepageNews } from '@/data/homepage';

export function HomeNews({ news }: { news: typeof homepageNews }) {
    return (
        <article aria-labelledby="home-news-title" className="rounded-xl border border-primary/15 bg-background p-5 shadow-sm sm:p-6">
            <p className="mb-2 text-xs font-semibold tracking-widest text-muted-foreground uppercase">Latest news</p>
            <h3 id="home-news-title" className="mb-2 text-lg font-semibold">
                {news.title}
            </h3>
            <p className="text-sm leading-7 text-muted-foreground">
                {news.introduction}
                <a href={news.href} className="font-medium text-foreground underline underline-offset-4">
                    {news.linkLabel}
                </a>
                {news.body}
            </p>
        </article>
    );
}
