import '../../../css/igsn-core-docs.css';

import { Head } from '@inertiajs/react';
import {
    ArrowDownToLine,
    ArrowRight,
    BookOpen,
    Check,
    ChevronDown,
    ChevronRight,
    CodeXml,
    Copy,
    ExternalLink,
    Link2,
    Menu,
    Search,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { AppFooter } from '@/components/app-footer';
import type { IgsnSchemaDocumentation, IgsnSchemaField } from '@/types/igsn-core-docs';

type View = 'overview' | 'reference' | 'examples';
type Filter = 'all' | 'required' | 'optional';

function CopyButton({ text, copy, link = false }: { text: string; copy: Record<string, string>; link?: boolean }) {
    const [status, setStatus] = useState<'idle' | 'copied' | 'failed'>('idle');
    const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    useEffect(() => () => clearTimeout(timer.current), []);
    const handleCopy = async () => {
        try {
            await navigator.clipboard.writeText(text);
            setStatus('copied');
            clearTimeout(timer.current);
            timer.current = setTimeout(() => setStatus('idle'), 2200);
        } catch {
            setStatus('failed');
        }
    };
    const Icon = status === 'copied' ? Check : link ? Link2 : Copy;
    return (
        <span className="igsn-copy-control">
            <button type="button" onClick={() => void handleCopy()} aria-label={status === 'copied' ? copy.copied : link ? copy.copyLink : copy.copy}>
                <Icon size={15} aria-hidden="true" />
                {!link && <span>{status === 'copied' ? copy.copied : copy.copy}</span>}
            </button>
            {status === 'failed' && (
                <span role="status" className="igsn-copy-error">
                    {copy.copyFailed}
                </span>
            )}
        </span>
    );
}

function XmlCode({ xml }: { xml: string }) {
    return (
        <pre className="igsn-xml" tabIndex={0}>
            <code>
                {xml.split(/(<!--[\s\S]*?-->|<[^>]+>)/g).map((part, index) =>
                    part.startsWith('<') ? (
                        <span className={part.startsWith('<!--') ? 'igsn-xml-comment' : 'igsn-xml-tag'} key={index}>
                            {part.split(/("[^"]*"|'[^']*')/g).map((token, position) => (
                                <span className={token.startsWith('"') || token.startsWith("'") ? 'igsn-xml-value' : undefined} key={position}>
                                    {token}
                                </span>
                            ))}
                        </span>
                    ) : (
                        <span key={index}>{part}</span>
                    ),
                )}
            </code>
        </pre>
    );
}

function Occurrence({ field }: { field: IgsnSchemaField }) {
    return <code>{field.min === field.max ? field.min : `${field.min}..${field.max === 'unbounded' ? 'n' : field.max}`}</code>;
}

function TreeItem({
    field,
    fields,
    selected,
    select,
    copy,
}: {
    field: IgsnSchemaField;
    fields: Map<string, IgsnSchemaField>;
    selected: string;
    select: (id: string) => void;
    copy: Record<string, string>;
}) {
    const activeDescendant = selected.startsWith(`${field.id}-`);
    const [expanded, setExpanded] = useState(activeDescendant);
    useEffect(() => {
        if (activeDescendant) setExpanded(true);
    }, [activeDescendant]);
    return (
        <li className="igsn-tree-item">
            <div className={`igsn-tree-row ${selected === field.id ? 'is-selected' : ''}`}>
                {field.children.length > 0 ? (
                    <button
                        type="button"
                        className="igsn-tree-toggle"
                        aria-expanded={expanded}
                        aria-label={`${expanded ? copy.collapse : copy.expand} ${field.label}`}
                        onClick={() => setExpanded(!expanded)}
                    >
                        {expanded ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
                    </button>
                ) : (
                    <span className="igsn-tree-dot" aria-hidden="true" />
                )}
                <a
                    href={`#${field.id}`}
                    onClick={(event) => {
                        event.preventDefault();
                        select(field.id);
                    }}
                    aria-current={selected === field.id ? 'location' : undefined}
                >
                    <span>{field.label}</span>
                    <code>{field.name}</code>
                </a>
            </div>
            {expanded && field.children.length > 0 && (
                <ul>
                    {field.children
                        .map((id) => fields.get(id))
                        .filter((child): child is IgsnSchemaField => Boolean(child))
                        .map((child) => (
                            <TreeItem key={child.id} field={child} fields={fields} selected={selected} select={select} copy={copy} />
                        ))}
                </ul>
            )}
        </li>
    );
}

export default function IgsnCoreDocs({ documentation: doc }: { documentation: IgsnSchemaDocumentation }) {
    const c = doc.copy;
    const [view, setView] = useState<View>('overview');
    const [selected, setSelected] = useState(doc.fields[0].id);
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState<Filter>('all');
    const [menuOpen, setMenuOpen] = useState(false);
    const [example, setExample] = useState<'minimal' | 'full'>('minimal');
    const input = useRef<HTMLInputElement>(null);
    const fields = useMemo(() => new Map(doc.fields.map((field) => [field.id, field])), [doc.fields]);
    const root = doc.fields[0];
    const sections = root.children.map((id) => fields.get(id)).filter((field): field is IgsnSchemaField => Boolean(field));
    const field = fields.get(selected) ?? root;
    const parent = field.parentId ? fields.get(field.parentId) : null;
    const filtered = useMemo(() => {
        const words = query.toLocaleLowerCase(doc.locale).trim().split(/\s+/).filter(Boolean);
        return doc.fields.filter(
            (item) =>
                item.parentId !== null &&
                (filter === 'all' || item.required === (filter === 'required')) &&
                words.every((word) =>
                    `${item.name} ${item.label} ${item.description} ${item.path} ${item.notes.join(' ')} ${item.attributes.map((attribute) => `${attribute.qname} ${attribute.label} ${attribute.description} ${attribute.notes.join(' ')} ${attribute.values.join(' ')}`).join(' ')}`
                        .toLocaleLowerCase(doc.locale)
                        .includes(word),
                ),
        );
    }, [doc.fields, doc.locale, query, filter]);
    useEffect(() => {
        const fromHash = () => {
            let hash: string;
            try {
                hash = decodeURIComponent(window.location.hash.slice(1));
            } catch {
                return;
            }
            if (fields.has(hash)) {
                setSelected(hash);
                setView('reference');
            } else if (hash === 'examples') setView('examples');
        };
        fromHash();
        window.addEventListener('hashchange', fromHash);
        const shortcut = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                input.current?.focus();
            }
        };
        window.addEventListener('keydown', shortcut);
        return () => {
            window.removeEventListener('hashchange', fromHash);
            window.removeEventListener('keydown', shortcut);
        };
    }, [fields]);
    const select = (id: string) => {
        setSelected(id);
        setView('reference');
        setMenuOpen(false);
        window.history.replaceState(window.history.state, '', `#${id}`);
        document.getElementById('igsn-doc-main')?.scrollIntoView({ block: 'start', behavior: 'instant' });
    };
    const changeView = (next: View) => {
        setView(next);
        setMenuOpen(false);
        window.history.replaceState(
            window.history.state,
            '',
            next === 'examples' ? '#examples' : next === 'reference' ? `#${selected}` : window.location.pathname,
        );
    };
    const referenceUrl = doc.schemaUrl.replace(/core\.xsd$/, 'docs');
    const currentXml = view === 'reference' && field.example ? field.example : doc.examples[example].xml;
    return (
        <div className="igsn-docs" lang={doc.locale}>
            <Head title={`${c.title} ${doc.version} — ${c.standards}`}>
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
                    <div className="igsn-doc-header-links">
                        <a href={doc.sourceUrl} target="_blank" rel="noreferrer">
                            {c.schemaSource}
                            <ExternalLink size={13} />
                        </a>
                        <a href={doc.schemaUrl} className="igsn-doc-download">
                            <ArrowDownToLine size={15} />
                            {c.downloadSchema}
                        </a>
                    </div>
                </div>
            </header>
            <div className="igsn-doc-hero">
                <div className="igsn-doc-hero-inner">
                    <div className="igsn-doc-eyebrow">
                        <span className="igsn-status-dot" />
                        FAIR AIMS <span>/</span> {c.standards} <span className="igsn-version-pill">{doc.version}</span>
                    </div>
                    <h1>
                        {c.title}
                        <span>{c.tagline}</span>
                    </h1>
                    <p>{c.lead}</p>
                    <div className="igsn-doc-controls">
                        <label className="igsn-doc-search">
                            <Search size={19} aria-hidden="true" />
                            <span className="sr-only">{c.search}</span>
                            <input
                                ref={input}
                                type="search"
                                aria-label={c.search}
                                value={query}
                                placeholder={c.searchPlaceholder}
                                onChange={(event) => {
                                    setQuery(event.target.value);
                                    setMenuOpen(true);
                                }}
                            />
                            {query ? (
                                <button type="button" onClick={() => setQuery('')} aria-label={c.clearSearch}>
                                    <X size={16} />
                                </button>
                            ) : (
                                <kbd>Ctrl K</kbd>
                            )}
                        </label>
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
            <div className="igsn-doc-shell">
                <button
                    type="button"
                    className="igsn-mobile-menu"
                    aria-expanded={menuOpen}
                    aria-controls="igsn-doc-sidebar"
                    onClick={() => setMenuOpen(!menuOpen)}
                >
                    <Menu size={17} />
                    {menuOpen ? c.closeNavigation : c.openNavigation}
                    <ChevronDown size={15} />
                </button>
                <aside id="igsn-doc-sidebar" className={`igsn-doc-sidebar ${menuOpen ? 'is-open' : ''}`}>
                    <nav className="igsn-doc-primary-nav" aria-label={c.standards}>
                        {(
                            [
                                ['overview', BookOpen],
                                ['reference', CodeXml],
                                ['examples', Copy],
                            ] as const
                        ).map(([key, Icon]) => (
                            <button
                                type="button"
                                key={key}
                                className={view === key ? 'is-active' : ''}
                                aria-current={view === key ? 'page' : undefined}
                                onClick={() => changeView(key)}
                            >
                                <Icon size={16} />
                                {c[key]}
                            </button>
                        ))}
                    </nav>
                    <div className="igsn-doc-nav-heading">{c.contents}</div>
                    <div className="igsn-doc-filters" role="group" aria-label={c.filterLabel}>
                        {(['all', 'required', 'optional'] as const).map((key) => (
                            <button type="button" key={key} aria-pressed={filter === key} onClick={() => setFilter(key)}>
                                {c[key === 'all' ? 'allFields' : `${key}Fields`]}
                            </button>
                        ))}
                    </div>
                    <nav aria-label={c.contents}>
                        {query.trim() || filter !== 'all' ? (
                            <>
                                <p className="igsn-search-count" role="status">
                                    {filtered.length} {c.results}
                                </p>
                                {filtered.length ? (
                                    <ul className="igsn-search-results">
                                        {filtered.map((item) => (
                                            <li key={item.id}>
                                                <a
                                                    href={`#${item.id}`}
                                                    onClick={(event) => {
                                                        event.preventDefault();
                                                        select(item.id);
                                                    }}
                                                >
                                                    <strong>{item.label}</strong>
                                                    <code>{item.qname}</code>
                                                    <small>{fields.get(item.groupId)?.label}</small>
                                                </a>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <div className="igsn-empty-results">
                                        <strong>{c.noResults}</strong>
                                        <p>{c.noResultsHint}</p>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setQuery('');
                                                setFilter('all');
                                            }}
                                        >
                                            {c.allFields}
                                        </button>
                                    </div>
                                )}
                            </>
                        ) : (
                            <ul className="igsn-doc-tree">
                                {sections.map((item) => (
                                    <TreeItem
                                        key={item.id}
                                        field={item}
                                        fields={fields}
                                        selected={view === 'reference' ? selected : ''}
                                        select={select}
                                        copy={c}
                                    />
                                ))}
                            </ul>
                        )}
                    </nav>
                    <a className="igsn-doc-sidebar-source" href={doc.publicationUrl} target="_blank" rel="noreferrer">
                        {c.publication}
                        <ExternalLink size={13} />
                    </a>
                </aside>
                <main id="igsn-doc-main" className="igsn-doc-main">
                    {view === 'overview' && (
                        <>
                            <div className="igsn-doc-section-heading">
                                <span className="igsn-doc-section-number">01 / {c.overview}</span>
                                <h2>{doc.introduction.title}</h2>
                                <p>{doc.introduction.description}</p>
                            </div>
                            <div className="igsn-doc-steps">
                                {doc.introduction.steps.map((step, index) => (
                                    <div key={step.title}>
                                        <span>{String(index + 1).padStart(2, '0')}</span>
                                        <h3>{step.title}</h3>
                                        <p>{step.description}</p>
                                    </div>
                                ))}
                            </div>
                            <button type="button" className="igsn-doc-primary-button" onClick={() => select(sections[0].id)}>
                                {c.openReference}
                                <ArrowRight size={16} />
                            </button>
                            <section className="igsn-doc-topic-section">
                                <h3>{c.sectionsLabel}</h3>
                                <div className="igsn-doc-topics">
                                    {sections.map((item, index) => (
                                        <a
                                            href={`#${item.id}`}
                                            key={item.id}
                                            onClick={(event) => {
                                                event.preventDefault();
                                                select(item.id);
                                            }}
                                        >
                                            <span className="igsn-doc-topic-number">{String(index + 1).padStart(2, '0')}</span>
                                            <span>
                                                <strong>{item.label}</strong>
                                                <code>&lt;{item.name}&gt;</code>
                                            </span>
                                            <ArrowRight size={15} />
                                        </a>
                                    ))}
                                </div>
                            </section>
                            <section className="igsn-doc-guidance">
                                <h3>{c.rulesTitle}</h3>
                                <p>{c.requiredExplanation}</p>
                                <p>{c.occurrenceExplanation}</p>
                                {doc.introduction.rules.map((rule) => (
                                    <div key={rule.title}>
                                        <h4>{rule.title}</h4>
                                        <p>{rule.description}</p>
                                    </div>
                                ))}
                            </section>
                            <SourceNotes doc={doc} />
                        </>
                    )}
                    {view === 'reference' && (
                        <article id={field.id} className="igsn-doc-field">
                            <div className="igsn-doc-breadcrumb">
                                <button type="button" onClick={() => changeView('overview')}>
                                    {c.title}
                                </button>
                                <ChevronRight size={12} />
                                {parent && parent.id !== root.id && (
                                    <>
                                        <button type="button" onClick={() => select(parent.id)}>
                                            {parent.label}
                                        </button>
                                        <ChevronRight size={12} />
                                    </>
                                )}
                                <span>{field.label}</span>
                            </div>
                            <div className="igsn-doc-field-heading">
                                <div>
                                    <span className="igsn-doc-section-number">{c.reference}</span>
                                    <h2>{field.label}</h2>
                                </div>
                                <CopyButton key={field.id} text={`${referenceUrl}#${field.id}`} copy={c} link />
                            </div>
                            <code className="igsn-doc-element-name">&lt;{field.qname}&gt;</code>
                            <p className="igsn-doc-field-description">{field.description}</p>
                            <div className="igsn-doc-facts">
                                <span className={`igsn-obligation ${field.required ? 'is-required' : ''}`}>
                                    {field.required ? c.required : c.optional}
                                </span>
                                <span>
                                    {c.occurs}
                                    <Occurrence field={field} />
                                </span>
                                <span>
                                    {c.type}
                                    <code>{field.type}</code>
                                </span>
                            </div>
                            {field.conditional && <p className="igsn-doc-conditional">{c.conditional}</p>}
                            {field.notes.length > 0 && (
                                <div className="igsn-doc-field-notes">
                                    <h3>{c.fieldNotes}</h3>
                                    {field.notes.map((note) => (
                                        <p key={note}>{note}</p>
                                    ))}
                                </div>
                            )}
                            {field.values.length > 0 && (
                                <section className="igsn-doc-values">
                                    <h3>{c.allowedValues}</h3>
                                    <div>
                                        {field.values.map((value) => (
                                            <code key={value}>{value}</code>
                                        ))}
                                    </div>
                                </section>
                            )}
                            {field.constraints.length > 0 && (
                                <section className="igsn-doc-values">
                                    <h3>{c.constraints}</h3>
                                    <dl>
                                        {field.constraints.map((constraint) => (
                                            <div key={`${constraint.name}-${constraint.value}`}>
                                                <dt>{c[constraint.name] ?? constraint.name}</dt>
                                                <dd>
                                                    <code>{constraint.value}</code>
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                </section>
                            )}
                            {field.attributes.length > 0 && (
                                <section className="igsn-doc-attributes">
                                    <h3>{c.attributes}</h3>
                                    <div className="igsn-doc-table-scroll">
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>{c.attributeName}</th>
                                                    <th>{c.use}</th>
                                                    <th>{c.type}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {field.attributes.map((attribute) => (
                                                    <tr key={attribute.qname}>
                                                        <td>
                                                            <code>{attribute.qname}</code>
                                                            <p>{attribute.description}</p>
                                                            {attribute.notes.map((note) => (
                                                                <p className="igsn-doc-attribute-note" key={note}>
                                                                    {note}
                                                                </p>
                                                            ))}
                                                            {attribute.fixed !== null && (
                                                                <small>
                                                                    {c.fixedValue}: <code>{attribute.fixed}</code>
                                                                </small>
                                                            )}
                                                            {attribute.default !== null && (
                                                                <small>
                                                                    {c.defaultValue}: <code>{attribute.default}</code>
                                                                </small>
                                                            )}
                                                            {attribute.values.length > 0 && (
                                                                <details>
                                                                    <summary>
                                                                        {c.allowedValues} ({attribute.values.length})
                                                                    </summary>
                                                                    <div className="igsn-doc-enum-list">
                                                                        {attribute.values.map((value) => (
                                                                            <code key={value}>{value}</code>
                                                                        ))}
                                                                    </div>
                                                                </details>
                                                            )}
                                                        </td>
                                                        <td>
                                                            <span className={`igsn-obligation ${attribute.required ? 'is-required' : ''}`}>
                                                                {attribute.required ? c.required : c.optional}
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <code>{attribute.type}</code>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </section>
                            )}
                            {field.children.length > 0 && (
                                <section className="igsn-doc-children">
                                    <h3>{c.childElements}</h3>
                                    {field.children
                                        .map((id) => fields.get(id))
                                        .filter((item): item is IgsnSchemaField => Boolean(item))
                                        .map((item) => (
                                            <a
                                                href={`#${item.id}`}
                                                key={item.id}
                                                onClick={(event) => {
                                                    event.preventDefault();
                                                    select(item.id);
                                                }}
                                            >
                                                <div>
                                                    <strong>{item.label}</strong>
                                                    <code>&lt;{item.qname}&gt;</code>
                                                    <p>{item.description}</p>
                                                </div>
                                                <span>
                                                    <Occurrence field={item} />
                                                    <ArrowRight size={16} />
                                                </span>
                                            </a>
                                        ))}
                                </section>
                            )}
                            <div className="igsn-doc-path">
                                <span>{c.xmlPath}</span>
                                <code>{field.path}</code>
                            </div>
                            {parent && (
                                <button type="button" className="igsn-doc-parent-link" onClick={() => select(parent.id)}>
                                    {c.parent}: {parent.label}
                                    <ArrowRight size={14} />
                                </button>
                            )}
                        </article>
                    )}
                    {view === 'examples' && (
                        <>
                            <div className="igsn-doc-section-heading">
                                <span className="igsn-doc-section-number">{c.examples}</span>
                                <h2>{c[example]}</h2>
                                <p>{c[`${example}Description`]}</p>
                            </div>
                            <div className="igsn-doc-example-choice">
                                {(['minimal', 'full'] as const).map((key) => (
                                    <button key={key} type="button" aria-pressed={example === key} onClick={() => setExample(key)}>
                                        {c[key]}
                                    </button>
                                ))}
                            </div>
                            <div className="igsn-doc-example-actions">
                                <a href={doc.examples[example].url} className="igsn-doc-primary-button">
                                    <ArrowDownToLine size={16} />
                                    {c.downloadXml}
                                </a>
                                <CopyButton text={doc.examples[example].xml} copy={c} />
                            </div>
                            <div className="igsn-doc-full-example">
                                <XmlCode xml={doc.examples[example].xml} />
                            </div>
                            <SourceNotes doc={doc} />
                        </>
                    )}
                </main>
                {view !== 'examples' && (
                    <aside className="igsn-doc-example-panel" aria-label={c.example}>
                        <div className="igsn-doc-code-header">
                            <span>
                                <CodeXml size={16} />
                                {view === 'reference' && field.example ? c.fragment : c.minimal}
                            </span>
                            <CopyButton key={currentXml} text={currentXml} copy={c} />
                        </div>
                        <div className="igsn-doc-code-file">
                            {view === 'reference' && field.example ? `${field.name}.xml` : 'minimal.xml'}
                            <span>XML</span>
                        </div>
                        <XmlCode xml={currentXml} />
                        <div className="igsn-doc-code-footer">
                            <p>{view === 'reference' && field.example ? c.fragmentNote : c.minimalDescription}</p>
                            <button
                                type="button"
                                onClick={() => {
                                    setExample('full');
                                    changeView('examples');
                                }}
                            >
                                {c.viewFullExample}
                                <ArrowRight size={14} />
                            </button>
                        </div>
                        <div className="igsn-doc-namespace">
                            <span>{c.namespace}</span>
                            <code>{doc.namespace}</code>
                        </div>
                    </aside>
                )}
            </div>
            <AppFooter />
        </div>
    );
}

function SourceNotes({ doc }: { doc: IgsnSchemaDocumentation }) {
    const c = doc.copy;
    return (
        <section className="igsn-doc-sources">
            <details>
                <summary>
                    {c.notesTitle}
                    <ChevronDown size={16} />
                </summary>
                <div>
                    {doc.notes.map((note) => (
                        <div key={note.title}>
                            <h4>{note.title}</h4>
                            <p>{note.description}</p>
                        </div>
                    ))}
                </div>
            </details>
            <div className="igsn-doc-source-links">
                <a href={doc.sourceUrl} target="_blank" rel="noreferrer">
                    {c.schemaSource}
                    <ExternalLink size={12} />
                </a>
                <a href={doc.publicationUrl} target="_blank" rel="noreferrer">
                    {c.publication}
                    <ExternalLink size={12} />
                </a>
                <a href={doc.manifestUrl}>
                    {c.manifest}
                    <ArrowDownToLine size={12} />
                </a>
            </div>
            <p>
                {c.revision}: <code>{doc.revision}</code> · IGSN Core {doc.version}
            </p>
        </section>
    );
}
