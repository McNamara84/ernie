export const resolveBasePath = (command: string): string =>
    command === 'build' ? '/build/' : '/';
