import { ImgHTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

export default function AppLogoIcon({ className, ...props }: ImgHTMLAttributes<HTMLImageElement>) {
    // Get base path from meta tag for production deployment with path prefix
    const getBasePath = () => {
        if (typeof document !== 'undefined') {
            const metaTag = document.querySelector('meta[name="app-base-path"]') as HTMLMetaElement;
            return metaTag?.content || '';
        }
        return '';
    };
    
    const basePath = getBasePath();
    const logoPath = `${basePath}/favicon.svg`;
    
    return <img src={logoPath} alt="App logo" className={cn('dark:invert', className)} {...props} />;
}