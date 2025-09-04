import { ImgHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export default function AppLogoIcon({ className, ...props }: ImgHTMLAttributes<HTMLImageElement>) {
    return <img src="/favicon.svg" alt="App logo" className={cn('dark:invert', className)} {...props} />;
}
