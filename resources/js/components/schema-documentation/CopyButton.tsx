import { Check, Copy, Link2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export default function CopyButton({ text, copy, link = false }: { text: string; copy: Record<string, string>; link?: boolean }) {
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
