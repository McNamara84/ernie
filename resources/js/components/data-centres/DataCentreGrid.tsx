import { Link } from '@inertiajs/react';

import { buildDataCentreSearchUrl } from '@/lib/portal-filter-url';
import type { DataCentre } from '@/types/data-centres';

export function DataCentreGrid({ dataCentres }: { dataCentres: DataCentre[] }) {
    return (
        <ul className="data-centre-grid" aria-label="Data centres">
            {dataCentres.map((centre) => (
                <li key={centre.slug} className="data-centre-cell">
                    <Link
                        href={buildDataCentreSearchUrl(centre.datacenterName)}
                        className="data-centre-link"
                        aria-label={`View publications: ${centre.shortName} — ${centre.displayName}`}
                    >
                        <span className="data-centre-hexagon" aria-hidden="true">
                            {centre.logo ? (
                                <img
                                    src={centre.logo.src}
                                    width={centre.logo.width}
                                    height={centre.logo.height}
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                />
                            ) : (
                                <span className="data-centre-monogram">{centre.shortName}</span>
                            )}
                        </span>
                        <span className="data-centre-label">{centre.shortName}</span>
                    </Link>
                    <Link
                        href={`/data-centres/description#${centre.slug}`}
                        className="data-centre-description-link"
                        aria-label={`About this data centre: ${centre.displayName}`}
                    >
                        About this data centre
                    </Link>
                </li>
            ))}
        </ul>
    );
}
