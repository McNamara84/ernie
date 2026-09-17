import { Link } from '@inertiajs/react';

import type { ScienceTopic } from '@/data/homepage';

export function ScienceTopicGrid({ topics }: { topics: ScienceTopic[] }) {
    return (
        <div className="science-topics">
            <ul className="science-topic-grid" aria-label="Science topics">
                {topics.map((topic) => (
                    <li key={topic.slug} className="science-topic-cell">
                        <Link href={topic.href} className="science-topic-link" aria-label={topic.label}>
                            <span className="science-topic-hexagon">
                                <img src={topic.image} alt="" width={200} height={173} loading="lazy" decoding="async" />
                                <span className="science-topic-caption" aria-hidden="true">
                                    {topic.label}
                                </span>
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </div>
    );
}
