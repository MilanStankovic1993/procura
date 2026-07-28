import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const outputDirectory = join(repositoryRoot, 'public', 'spa');
const indexPath = join(outputDirectory, 'index.html');
const nginxPath = join(repositoryRoot, 'deploy', 'nginx', 'procura.conf.example');
const failures = [];

const requireCondition = (condition, message) => {
  if (!condition) {
    failures.push(message);
  }
};

requireCondition(existsSync(indexPath), 'Angular production index is missing at public/spa/index.html.');
requireCondition(existsSync(nginxPath), 'The nginx production configuration is missing.');

if (existsSync(indexPath)) {
  const index = readFileSync(indexPath, 'utf8');
  const assetReferences = [
    ...index.matchAll(/(?:href|src)="(\/spa\/[^"]+\.(?:css|js))"/g),
  ].map((match) => match[1]);

  requireCondition(index.includes('<base href="/">'), 'Angular must retain a root base href.');
  requireCondition(!index.includes('localhost'), 'Production index must not contain localhost URLs.');
  requireCondition(assetReferences.length >= 2, 'Production index must reference hashed SPA assets.');

  for (const reference of assetReferences) {
    const relativePath = reference.replace(/^\/spa\//, '');
    requireCondition(
      existsSync(join(outputDirectory, relativePath)),
      `Referenced production asset is missing: ${reference}`,
    );
  }

  const outputFiles = readdirSync(outputDirectory).filter((file) =>
    statSync(join(outputDirectory, file)).isFile(),
  );
  const bundleFiles = outputFiles.filter((file) => /\.(?:css|js)$/.test(file));

  requireCondition(bundleFiles.length > 0, 'No production JavaScript or CSS bundles were emitted.');
  requireCondition(
    bundleFiles.every((file) => /-[A-Za-z0-9_-]{8,}\.(?:css|js)$/.test(file)),
    'Every production JavaScript and CSS bundle must use a content-hashed filename.',
  );
  requireCondition(
    outputFiles.every((file) => !file.endsWith('.map')),
    'Production source maps must not be publicly emitted.',
  );
  requireCondition(existsSync(join(outputDirectory, 'favicon.svg')), 'Production favicon is missing.');
}

if (existsSync(nginxPath)) {
  const nginx = readFileSync(nginxPath, 'utf8');
  const laravelPrefixes = ['api', 'sanctum', 'admin', 'filament', 'flux', 'storage'];

  requireCondition(nginx.includes('location @laravel'), 'Named Laravel front controller is missing.');
  requireCondition(nginx.includes('fastcgi_pass'), 'PHP-FPM upstream is missing.');
  requireCondition(nginx.includes('fastcgi_param HTTPS on;'), 'PHP-FPM must receive HTTPS state.');
  requireCondition(nginx.includes('location = /up'), 'Laravel health route is not isolated.');
  requireCondition(
    nginx.includes('try_files /__procura_backend_route__ @laravel;'),
    'Dynamic backend locations must always redirect to the Laravel front controller.',
  );
  requireCondition(nginx.includes('livewire-'), 'Dynamic Livewire prefixes are not isolated.');
  requireCondition(
    laravelPrefixes.every((prefix) => nginx.includes(prefix)),
    'A required Laravel route prefix is missing from the nginx boundary.',
  );
  requireCondition(
    nginx.includes('try_files $uri $uri/ /spa/index.html;'),
    'Angular client-route fallback is missing.',
  );
  requireCondition(
    nginx.includes('limit_except GET HEAD'),
    'The SPA fallback must reject non-read browser methods.',
  );
  requireCondition(
    nginx.includes('max-age=31536000, immutable'),
    'Hashed Angular assets must use immutable caching.',
  );
  requireCondition(
    nginx.includes('no-cache, no-store, must-revalidate'),
    'Angular index must disable persistent caching.',
  );
  requireCondition(
    nginx.includes('location = /spa/index.html') && nginx.includes('internal;'),
    'Angular index must be available only through the internal SPA fallback.',
  );
  requireCondition(
    nginx.indexOf('location ~ /\\.') < nginx.indexOf('# These prefixes always belong to Laravel'),
    'Hidden-file denial must be evaluated before dynamic Laravel prefix locations.',
  );
  requireCondition(
    nginx.includes('location ~ \\.php$') && nginx.includes('return 404;'),
    'Direct PHP script execution must be disabled.',
  );
  requireCondition(
    nginx.includes('location = /favicon.svg') && nginx.includes('/spa/favicon.svg'),
    'Production favicon routing is incomplete.',
  );
}

if (failures.length > 0) {
  for (const failure of failures) {
    console.error(`FAIL: ${failure}`);
  }

  process.exitCode = 1;
} else {
  console.log('Production Angular output and nginx serving contract verified.');
}
