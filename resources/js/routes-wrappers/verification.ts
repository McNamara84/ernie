import * as generatedRoutes from '../routes/verification/index';
import { applyBasePathToRoutes } from '../lib/base-path';

type RouteModule = Record<string, unknown>;

applyBasePathToRoutes(generatedRoutes as RouteModule);

export * from '../routes/verification/index';
