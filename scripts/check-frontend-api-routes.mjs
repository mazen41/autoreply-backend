import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const backendRoot = process.cwd();
const frontendRoot = path.resolve(process.argv[2] ?? path.join(backendRoot, '..', 'autoreply-frontend'));
const sourceExtensions = new Set(['.js', '.jsx', '.ts', '.tsx', '.mjs', '.cjs']);
// Contract fixtures often include deliberate endpoint examples that are not
// runtime requests. Scan shipped frontend source, not test descriptions.
const excludedDirectories = new Set(['.git', '.next', 'node_modules', 'out', 'build', 'dist', 'tests', '__tests__']);

function sourceFiles(directory) {
  const files = [];
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const fullPath = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      if (!excludedDirectories.has(entry.name)) files.push(...sourceFiles(fullPath));
    } else if (sourceExtensions.has(path.extname(entry.name))) {
      files.push(fullPath);
    }
  }
  return files;
}

function normalizeRoute(route) {
  const withoutQuery = route.split(/[?#]/, 1)[0];
  const segments = withoutQuery.replace(/^\/+|\/+$/g, '').split('/').filter(Boolean);
  return segments.map((segment) => {
    if (segment.startsWith('${') || segment.includes('${') || /^\{.*\??\}$/.test(segment)) return '*';
    return segment;
  }).join('/');
}

function frontendRouteFromMatch(rawMatch) {
  // Query expressions and fragments do not contribute to the endpoint path.
  let route = rawMatch.split(/[?#]/, 1)[0];

  // A template interpolation embedded directly in the final static path
  // segment is commonly a query expression (for example /api/posts${condition
  // ? '?category=...' : ''}). Keep the static endpoint prefix in that case.
  // Interpolations in their own path segment remain dynamic route segments.
  const interpolation = route.indexOf('${');
  if (interpolation >= 0 && route[interpolation - 1] !== '/') {
    route = route.slice(0, interpolation);
  }

  return normalizeRoute(route);
}

function routeMatches(frontendPath, backendPath) {
  const expected = frontendPath.split('/');
  const actual = backendPath.split('/').map((segment) => /^\{.*\??\}$/.test(segment) ? '*' : segment);
  return expected.length === actual.length
    && expected.every((segment, index) => segment === '*' || segment === actual[index]);
}

if (!frontendRoot || !sourceFiles(frontendRoot).length) {
  console.error(`Frontend source directory not found or empty: ${frontendRoot}`);
  process.exit(2);
}

let backendRoutes;
try {
  const output = execFileSync('php', ['artisan', 'route:list', '--json'], {
    cwd: backendRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  backendRoutes = JSON.parse(output)
    .map((route) => normalizeRoute(route.uri))
    .filter((route) => route.startsWith('api/'));
} catch (error) {
  console.error('Could not read Laravel route:list --json. Run this from the backend root after installing Composer dependencies.');
  console.error(error instanceof Error ? error.message : String(error));
  process.exit(2);
}

const routeSet = new Set(backendRoutes);
const frontendCalls = new Map();
const apiPathPattern = /\/api(?:\/[^\s"'`<>]*)?/g;

for (const file of sourceFiles(frontendRoot)) {
  const contents = readFileSync(file, 'utf8');
  for (const match of contents.matchAll(apiPathPattern)) {
    const frontendPath = frontendRouteFromMatch(match[0]);
    if (frontendPath === 'api') continue;
    let sourcePaths = frontendCalls.get(frontendPath);
    if (!sourcePaths) {
      sourcePaths = new Set();
      frontendCalls.set(frontendPath, sourcePaths);
    }
    sourcePaths.add(path.relative(frontendRoot, file));
  }
}

const missing = [...frontendCalls.entries()]
  .filter(([frontendPath]) => ![...routeSet].some((backendPath) => routeMatches(frontendPath, backendPath)))
  .sort(([left], [right]) => left.localeCompare(right));

if (missing.length) {
  console.error('Frontend API paths with no matching Laravel route:');
  for (const [frontendPath, files] of missing) {
    console.error(`  /${frontendPath}  (${[...files].sort().join(', ')})`);
  }
  process.exit(1);
}

console.log(`Frontend API route contract passed: ${frontendCalls.size} unique paths match Laravel routes.`);
