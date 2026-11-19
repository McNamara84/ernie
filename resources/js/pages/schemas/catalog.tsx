import '../../../css/igsn-core-docs.css';
import '../../../css/schema-documentation.css';

import { Head } from '@inertiajs/react';
import { BookOpen, Braces, CodeXml, Network } from 'lucide-react';

import { AppFooter } from '@/components/app-footer';
import type { SchemaCatalog } from '@/types/schema-catalog';

export default function SchemaCatalogPage({ catalog }: { catalog: SchemaCatalog }) {
    const { copy: c } = catalog;
    return (
        <div className="igsn-docs schema-catalog" lang={catalog.locale}>
            <Head title={`${c.title} — ${c.brand}`}>
                <meta name="description" content={c.lead} />
            </Head>
            <header className="igsn-doc-header">
                <div className="igsn-doc-header-inner">
                    <a href="/" className="igsn-doc-brand">
                        <span className="igsn-gfz-mark">GFZ</span>
                        <span>
                            <strong>{c.brand}</strong>
                            <small>{c.standards}</small>
                        </span>
                    </a>
                </div>
            </header>
            <div className="igsn-doc-hero">
                <div className="igsn-doc-hero-inner">
                    <div className="igsn-doc-eyebrow">{c.eyebrow}</div>
                    <h1>
                        {c.title}
                        <span>{c.tagline}</span>
                    </h1>
                    <p>{c.lead}</p>
                </div>
            </div>
            <main className="schema-doc-home">
                <h2>{c.heading}</h2>
                <p>{c.description}</p>
                <div className="schema-catalog-cards">
                    {catalog.entries.map((entry) => {
                        const Icon = entry.format === 'XML' ? CodeXml : entry.format === 'JSON' ? Braces : Network;
                        return (
                            <article className="schema-catalog-card" key={entry.id} aria-labelledby={`catalog-${entry.id}`}>
                                <div className="schema-catalog-card-top">
                                    <Icon size={28} aria-hidden="true" />
                                    <span className="igsn-version-pill">{entry.version}</span>
                                </div>
                                <h3 id={`catalog-${entry.id}`}>{entry.title}</h3>
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
                <section className="igsn-doc-guidance" aria-label={c.standards}>
                    {catalog.guidance.map((item) => (
                        <div key={item.title}>
                            <h2>{item.title}</h2>
                            <p>{item.description}</p>
                        </div>
                    ))}
                </section>
                <section className="schema-catalog-supporting" aria-labelledby="supporting-schemas">
                    <h2 id="supporting-schemas">{c.supportingHeading}</h2>
                    <h3>{catalog.supporting.title}</h3>
                    <p className="schema-catalog-kind">{catalog.supporting.kind}</p>
                    <p>{catalog.supporting.description}</p>
                    <div className="schema-doc-home-links">
                        <a href={catalog.supporting.artifactUrl}>{c.supportingFile}</a>
                        <a href={catalog.supporting.documentationUrl}>{c.supportingDocs}</a>
                    </div>
                </section>
            </main>
            <AppFooter />
        </div>
    );
}
