/* eslint-disable @typescript-eslint/no-explicit-any */
import { usePage } from '@inertiajs/react';

import { withBasePath } from '@/lib/base-path';

export default function DefaultGfzTemplate() {
    const { resource, isPreview } = usePage().props as any;

    return (
        <div className="min-h-screen" style={{ backgroundColor: '#0C2A63' }}>
            {isPreview && (
                <div className="bg-yellow-400 px-4 py-2 text-center text-sm font-medium text-gray-900">
                     Preview Mode
                </div>
            )}
            
            {/* Zentrierter Container für Header, Content und Footer */}
            <div className="mx-auto max-w-7xl rounded bg-white">
                {/* Header */}
                <header className="border-b border-gray-200 px-4 py-4">
                    <div className="flex items-center justify-between">
                        {/* Centered Logo */}
                        <div className="flex-1"></div>
                        <div className="flex justify-center">
                            <img
                                src={withBasePath('/images/gfz-ds-logo.png')}
                                alt="GFZ Data Services"
                                className="h-12"
                            />
                        </div>
                        {/* Legal Notice Link */}
                        <div className="flex flex-1 justify-end">
                            <a
                                href={withBasePath('/legal-notice')}
                                className="text-xs text-gray-600 hover:text-gray-900 hover:underline"
                            >
                                Legal Notice
                            </a>
                        </div>
                    </div>
                </header>
                
                {/* Content */}
                <div className="px-4 py-8">
                    <div className="rounded border border-gray-300 bg-gray-50 p-8 text-center">
                        <p className="text-lg font-medium text-gray-500">Content coming soon...</p>
                        <p className="mt-2 text-sm text-gray-400">Resource ID: {resource.id}</p>
                    </div>
                </div>
                
                {/* Footer */}
                <footer className="border-t border-gray-300 px-4 py-6">
                    <div className="flex items-center justify-between">
                        <a href="https://www.gfz.de" target="_blank" rel="noopener noreferrer">
                            <img src={withBasePath('/images/gfz-logo-en.gif')} alt="GFZ" className="h-12" />
                        </a>
                        <a href="https://www.helmholtz.de" target="_blank" rel="noopener noreferrer">
                            <img src={withBasePath('/images/helmholtz-logo-blue.png')} alt="Helmholtz" className="h-12" />
                        </a>
                    </div>
                </footer>
            </div>
        </div>
    );
}
