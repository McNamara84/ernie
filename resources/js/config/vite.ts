export const assetsBuildDirectory = 'assets';

export const laravelPluginOptions = {
    input: ['resources/css/app.css', 'resources/js/app.tsx', 'resources/js/swagger.tsx'],
    ssr: 'resources/js/ssr.tsx',
    refresh: true,
    buildDirectory: assetsBuildDirectory,
};

export const viteBasePath = '/ernie/';
