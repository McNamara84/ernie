import { applyBasePathToRoutes } from '../lib/base-path';
import * as generatedRoutes from '../routes/verification/index';

type RouteModule = Record<string, unknown>;

applyBasePathToRoutes(generatedRoutes as RouteModule);

export * from '../routes/verification/index';
