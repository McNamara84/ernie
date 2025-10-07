import { applyBasePathToRoutes } from '../lib/base-path';
import * as generatedRoutes from '../routes/login/index';

type RouteModule = Record<string, unknown>;

applyBasePathToRoutes(generatedRoutes as RouteModule);

export * from '../routes/login/index';
