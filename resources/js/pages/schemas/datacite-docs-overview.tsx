import '../../../css/igsn-core-docs.css';
import '../../../css/schema-documentation.css';

import { Head } from '@inertiajs/react';
import { ArrowRight, Braces, ExternalLink, FileJson } from 'lucide-react';

import { AppFooter } from '@/components/app-footer';
import type { DataCiteDocsOverview } from '@/types/datacite-docs';

export default function DataCiteDocsOverviewPage({ documentation: doc }: { documentation: DataCiteDocsOverview }) {
    const { home, copy: c } = doc;
    return (
        <div className="igsn-docs" lang={doc.locale}>
            <Head title={`${home.title} ${doc.version} — ${c.standards}`}>
                <meta name="description" content={home.lead} />
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
                    <div className="igsn-doc-header-links">
                        <a href={doc.sourceUrl} target="_blank" rel="noreferrer">
                            {c.schemaSource}
                            <ExternalLink size={13} />
                        </a>
                    </div>
                </div>
            </header>
            <div className="igsn-doc-hero">
                <div className="igsn-doc-hero-inner">
                    <div className="igsn-doc-eyebrow">
                        <span className="igsn-status-dot" />
                        {c.eyebrow} <span>/</span>
                        {c.standards}
                        <span className="igsn-version-pill">{doc.version}</span>
                    </div>
                    <h1>
                        {home.title}
                        <span>{home.tagline}</span>
                    </h1>
                    <p>{home.lead}</p>
                    <div className="igsn-doc-controls">
                        <label className="igsn-doc-version">
                            <span className="sr-only">{c.selectVersion}</span>
                            <select
                                aria-label={c.selectVersion}
                                value={doc.revision}
                                onChange={(event) => {
                                    const next = doc.versions.find((version) => version.revision === event.target.value);
                                    if (next) window.location.assign(next.url);
                                }}
                            >
                                {doc.versions.map((version) => (
                                    <option key={version.revision} value={version.revision}>
                                        {version.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                </div>
            </div>
            <main className="schema-doc-home">
                <h2>{home.heading}</h2>
                <p>{home.description}</p>
                <div className="schema-doc-format-cards">
                    {home.cards.map((card) => {
                        const Icon = card.key === 'json' ? FileJson : Braces;
                        return (
                            <article className="schema-doc-format-card" key={card.key}>
                                <Icon size={28} aria-hidden="true" />
                                <h3>{card.title}</h3>
                                <p>{card.description}</p>
                                <a href={doc.formats.find((format) => format.key === card.key)?.url} className="igsn-doc-primary-button">
                                    {card.action}
                                    <ArrowRight size={16} />
                                </a>
                            </article>
                        );
                    })}
                </div>
                <section className="igsn-doc-guidance">
                    <h2>{home.differencesTitle}</h2>
                    {home.differences.map((item) => (
                        <div key={item.title}>
                            <h3>{item.title}</h3>
                            <p>{item.description}</p>
                        </div>
                    ))}
                </section>
                <div className="schema-doc-home-links">
                    <a href={doc.schemaUrl}>{c.downloadSchema}</a>
                    <a href={doc.contextUrl}>{c.contextDownload}</a>
                    <a href={doc.sourceUrl} target="_blank" rel="noreferrer">
                        {home.source}
                    </a>
                    <a href={doc.manifestUrl}>{c.manifest}</a>
                    <a href={doc.igsnUrl}>{home.igsn}</a>
                </div>
                <p className="schema-doc-rule-source">
                    {home.revision}: <code>{doc.revision}</code>
                </p>
            </main>
            <AppFooter />
        </div>
    );
}
