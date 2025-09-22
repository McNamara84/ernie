import * as generatedRoutes from '../routes/login/index';
import { applyBasePathToRoutes } from '../lib/base-path';

type RouteModule = Record<string, unknown>;

applyBasePathToRoutes(generatedRoutes as RouteModule);

export * from '../routes/login/index';
