import type { ComponentType, SVGProps } from 'react';
import { toast } from 'sonner';

export interface EndpointCopyButtonProps {
    getComponent: (name: 'CopyIcon') => ComponentType<SVGProps<SVGSVGElement>>;
    textToCopy: string;
}

interface EndpointCopyFeedbackPlugin {
    wrapComponents: {
        CopyToClipboardBtn: () => typeof EndpointCopyButton;
    };
}

export function EndpointCopyButton({ getComponent, textToCopy }: EndpointCopyButtonProps) {
    const CopyIcon = getComponent('CopyIcon');

    const copyEndpointPath = async () => {
        if (typeof navigator === 'undefined' || !navigator.clipboard?.writeText) {
            toast.error('Could not copy endpoint to clipboard');

            return;
        }

        try {
            await navigator.clipboard.writeText(textToCopy);
            toast.success('Copied to clipboard');
        } catch {
            toast.error('Could not copy endpoint to clipboard');
        }
    };

    return (
        <div className="view-line-link copy-to-clipboard" title="Copy to clipboard">
            <button type="button" aria-label="Copy endpoint path to clipboard" onClick={copyEndpointPath}>
                <CopyIcon />
            </button>
        </div>
    );
}

export function endpointCopyFeedbackPlugin(): EndpointCopyFeedbackPlugin {
    return {
        wrapComponents: {
            CopyToClipboardBtn: () => EndpointCopyButton,
        },
    };
}
