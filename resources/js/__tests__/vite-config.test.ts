import { assetsBuildDirectory, laravelPluginOptions, viteBasePath } from '../config/vite';

describe('vite configuration', () => {
    it('exposes the assets build directory constant', () => {
        expect(assetsBuildDirectory).toBe('assets');
    });

    it('passes the build directory to the laravel plugin', () => {
        expect(laravelPluginOptions.buildDirectory).toBe(assetsBuildDirectory);
    });

    it('retains the production base path', () => {
        expect(viteBasePath).toBe('/ernie/');
    });
});
