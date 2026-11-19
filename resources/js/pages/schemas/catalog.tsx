import '../../../css/igsn-core-docs.css';
import '../../../css/schema-documentation.css';

import { Head } from '@inertiajs/react';
import { BookOpen, Braces, CodeXml, Network } from 'lucide-react';

import { AppFooter } from '@/components/app-footer';
import { PortalHeader } from '@/components/portal/PortalHeader';
import type { SchemaCatalog } from '@/types/schema-catalog';

export default function SchemaCatalogPage({ catalog }: { catalog: SchemaCatalog }) {
    const { copy: c } = catalog;
    return (
        <div className="igsn-docs schema-catalog" lang={catalog.locale}>
            <Head title={`${c.title} — ${c.brand}`}>
                <meta name="description" content={c.lead} />
            </Head>
            <PortalHeader portalKind="schemas" />
            <main className="schema-doc-home">
                <h1 className="sr-only">{c.title}</h1>
                <div className="schema-catalog-cards">
                    {catalog.entries.map((entry) => {
                        const Icon = entry.format === 'XML' ? CodeXml : entry.format === 'JSON' ? Braces : Network;
                        return (
                            <article className="schema-catalog-card" key={entry.id} aria-labelledby={`catalog-${entry.id}`}>
                                <div className="schema-catalog-card-top">
                                    <Icon size={28} aria-hidden="true" />
                                    <span className="igsn-version-pill">{entry.version}</span>
                                </div>
                                <h2 id={`catalog-${entry.id}`}>{entry.title}</h2>
                                <p className="schema-catalog-kind">{entry.kind}</p>
                                <p className="schema-catalog-description">{entry.description}</p>
                                <a
                                    href={entry.documentationUrl}
                                    className="igsn-doc-primary-button"
                                    aria-label={c.documentationLabel.replace('{name}', entry.title)}
                                >
                                    <BookOpen size={16} aria-hidden="true" />
                                    {c.documentation}
                                </a>
                                <dl className="schema-catalog-files">
                                    <div>
                                        <dt>{c.currentFile}</dt>
                                        <dd>
                                            <a href={entry.artifactUrl}>
                                                <code>{entry.artifactPath}</code>
                                            </a>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>{c.revision}</dt>
                                        <dd>
                                            <a href={entry.fixedArtifactUrl}>{c.fixedFile}</a> <code>{entry.revision}</code>
                                        </dd>
                                    </div>
                                </dl>
                                <a className="schema-catalog-manifest" href={entry.manifestUrl}>
                                    {c.checksums}
                                </a>
                            </article>
                        );
                    })}
                </div>
            </main>
            <AppFooter />
        </div>
    );
}
