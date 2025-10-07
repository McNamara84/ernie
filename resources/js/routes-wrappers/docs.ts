import { applyBasePathToRoutes } from '../lib/base-path';
import * as generatedRoutes from '../routes/docs/index';

type RouteModule = Record<string, unknown>;

applyBasePathToRoutes(generatedRoutes as RouteModule);

export * from '../routes/docs/index';
