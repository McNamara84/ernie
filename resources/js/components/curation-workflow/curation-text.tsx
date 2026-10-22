export function CurationText({ text }: { text: string }) {
    return (
        <span className="break-words whitespace-pre-wrap">
            {text.split(/(https?:\/\/[^\s<>]+)/g).map((part, index) =>
                /^https?:\/\//.test(part) ? (
                    <a key={index} href={part} target="_blank" rel="noopener noreferrer" className="underline">
                        {part}
                    </a>
                ) : (
                    part
                ),
            )}
        </span>
    );
}

export function locationHref(value: string): string | undefined {
    try {
        const url = new URL(value);
        return ['https:', 'http:', 'sftp:'].includes(url.protocol) ? url.href : undefined;
    } catch {
        return undefined;
    }
}

export function dateLabel(value: string | null): string {
    if (!value) return '—';
    return /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : new Date(value).toLocaleString();
}
