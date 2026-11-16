import { afterEach, beforeEach } from 'vitest';

/** Model the token Laravel supplies on an ordinary application page. */
export function installCsrfFixture() {
    let meta: HTMLMetaElement;
    beforeEach(() => {
        meta = document.createElement('meta');
        meta.name = 'csrf-token';
        meta.content = 'vitest-csrf-token';
        document.head.append(meta);
    });
    afterEach(() => meta.remove());
}
