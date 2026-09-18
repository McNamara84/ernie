import { Link } from '@inertiajs/react';
import type { CSSProperties } from 'react';

import type { ScienceTopic } from '@/data/homepage';

function topicLayout(count: number, maximumColumns: number) {
    const rows = Math.ceil(count / maximumColumns);
    const shortRow = rows > 0 ? Math.floor(count / rows) : 0;
    const longerRows = rows > 0 ? count % rows : 0;
    const columns = Math.max(1, shortRow + (longerRows > 0 ? 1 : 0));
    const positions: { column: number; row: number }[] = [];

    for (let row = 0; row < rows; row++) {
        const rowSize = shortRow + (row < longerRows ? 1 : 0);
        // A narrower row shifts horizontally by half a column. Leave half a
        // hexagon of extra height at that transition so the shapes cannot overlap.
        const transition = longerRows > 0 && row >= longerRows ? 1 : 0;
        for (let column = 0; column < rowSize; column++) {
            positions.push({
                column: columns - rowSize + column * 2 + 1,
                row: row * 2 + transition + (column % 2 === 0 ? 1 : 0) + 1,
            });
        }
    }

    return { columns, positions };
}

export function ScienceTopicGrid({ topics }: { topics: ScienceTopic[] }) {
    const small = topicLayout(topics.length, 2);
    const medium = topicLayout(topics.length, 5);
    const large = topicLayout(topics.length, 6);

    return (
        <div className="science-topics">
            <ul
                className="science-topic-grid"
                aria-label="Science topics"
                style={
                    {
                        '--small-columns': small.columns,
                        '--medium-columns': medium.columns,
                        '--large-columns': large.columns,
                    } as CSSProperties
                }
            >
                {topics.map((topic, index) => (
                    <li
                        key={topic.slug}
                        className="science-topic-cell"
                        style={
                            {
                                '--small-column': small.positions[index].column,
                                '--small-row': small.positions[index].row,
                                '--medium-column': medium.positions[index].column,
                                '--medium-row': medium.positions[index].row,
                                '--large-column': large.positions[index].column,
                                '--large-row': large.positions[index].row,
                            } as CSSProperties
                        }
                    >
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
