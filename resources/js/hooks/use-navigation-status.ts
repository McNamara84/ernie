import { router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

export function useNavigationStatus(currentContext: string) {
    const [isNavigating, setIsNavigating] = useState(false);

    useEffect(() => {
        const removeStart = router.on('start', (event) => {
            if (!event.detail.visit.showProgress) {
                return;
            }

            setIsNavigating(true);
        });

        const removeFinish = router.on('finish', (event) => {
            if (!event.detail.visit.showProgress) {
                return;
            }

            setIsNavigating(false);
        });

        return () => {
            removeStart();
            removeFinish();
        };
    }, []);

    const statusText = useMemo(() => {
        return isNavigating ? `Opening ${currentContext}...` : 'Ready';
    }, [currentContext, isNavigating]);

    return {
        isNavigating,
        statusText,
    };
}