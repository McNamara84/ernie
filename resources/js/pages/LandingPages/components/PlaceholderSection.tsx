interface PlaceholderSectionProps {
    title?: string;
    className?: string;
}

export function PlaceholderSection({ title, className = '' }: PlaceholderSectionProps) {
    return (
        <div className={`rounded-lg border border-gray-200 bg-white p-6 shadow-sm ${className}`}>
            {title && <h3 className="mb-4 text-lg font-semibold text-gray-900">{title}</h3>}
            <div className="flex items-center justify-center rounded bg-gray-50 p-8">
                <p className="text-sm text-gray-400">PLACEHOLDER</p>
            </div>
        </div>
    );
}
