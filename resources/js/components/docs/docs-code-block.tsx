import { Check, Copy } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface DocsCodeBlockProps {
    code: string;
    language?: string;
    className?: string;
}

export function DocsCodeBlock({ code, language = 'bash', className }: DocsCodeBlockProps) {
    const [copied, setCopied] = useState(false);

    const handleCopy = async () => {
        await navigator.clipboard.writeText(code);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <div className={cn('group relative', className)}>
            <div className="absolute right-2 top-2 opacity-0 transition-opacity group-hover:opacity-100">
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8 bg-background/80 backdrop-blur-sm"
                    onClick={handleCopy}
                    aria-label={copied ? 'Copied' : 'Copy code'}
                >
                    {copied ? <Check className="size-4 text-green-500" /> : <Copy className="size-4" />}
                </Button>
            </div>
            <pre className="overflow-x-auto rounded-lg bg-muted p-4">
                <code className={`language-${language} text-sm`}>{code}</code>
            </pre>
        </div>
    );
}
