import { ImgHTMLAttributes } from 'react';
import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

interface SharedProps {
    assetUrl: string;
    [key: string]: unknown;
}

export default function AppLogoIcon({ className, ...props }: ImgHTMLAttributes<HTMLImageElement>) {
    const { assetUrl } = usePage<SharedProps>().props;
    
    // Generate correct asset URL with prefix
    const logoUrl = new URL('/favicon.svg', assetUrl || window.location.origin).href;
    
    return <img src={logoUrl} alt="App logo" className={cn('dark:invert', className)} {...props} />;
}