import { readdirSync, readFileSync } from 'node:fs';
import { extname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const frontendRoot = fileURLToPath(new URL('..', import.meta.url));
const appRoot = join(frontendRoot, 'src', 'app');
const failures = [];
const translationFreeComponents = new Set(['src\\app\\app.ts', 'src/app/app.ts']);

function filesUnder(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);

    return entry.isDirectory() ? filesUnder(path) : [path];
  });
}

function report(path, line, message) {
  failures.push(`${relative(frontendRoot, path)}:${line} ${message}`);
}

function lineAt(source, offset) {
  return source.slice(0, offset).split('\n').length;
}

for (const path of filesUnder(appRoot)) {
  const extension = extname(path);
  const source = readFileSync(path, 'utf8');
  const projectPath = relative(frontendRoot, path);

  if (
    extension === '.ts' &&
    /src[\\/]app[\\/]core[\\/]i18n[\\/]locales[\\/]/.test(projectPath)
  ) {
    for (const match of source.matchAll(/(?<!\{)\{[A-Za-z_]\w*\}(?!\})/g)) {
      report(
        path,
        lineAt(source, match.index),
        'translation interpolation must use double braces',
      );
    }
  }

  if (extension === '.ts' && source.includes('@Component(')) {
    if (
      !source.includes('TranslatePipe') &&
      !translationFreeComponents.has(projectPath)
    ) {
      report(path, 1, 'application component must import TranslatePipe');
    }

    for (const match of source.matchAll(
      /(?:error|success)\.set\(\s*(['"`])([A-Z][^'"`\n]+)\1/g,
    )) {
      report(path, lineAt(source, match.index), 'user message must use I18nService');
    }
  }

  if (extension !== '.html') {
    continue;
  }

  const withoutComments = source.replace(/<!--[\s\S]*?-->/g, '');

  for (const match of withoutComments.matchAll(/>([^<]+)</g)) {
    const text = match[1].replace(/\s+/g, ' ').trim();

    if (
      text === '' ||
      text === 'P' ||
      text === 'Procura' ||
      text.includes('{{') ||
      text.includes('@') ||
      !/[A-Za-zÀ-ž]/u.test(text)
    ) {
      continue;
    }

    report(
      path,
      lineAt(withoutComments, match.index),
      `hard-coded template text: ${JSON.stringify(text)}`,
    );
  }

  for (const match of withoutComments.matchAll(
    /(?<!\[)(placeholder|title|aria-label|alt)="([^"]+)"/g,
  )) {
    const value = match[2].trim();

    if (
      value === '' ||
      value.includes('{{') ||
      /^(?:https?:\/\/|[0-9.#/+-]+$)/.test(value)
    ) {
      continue;
    }

    report(
      path,
      lineAt(withoutComments, match.index),
      `static ${match[1]} must use a translation binding`,
    );
  }
}

if (failures.length > 0) {
  console.error('Localization contract failed:\n');
  console.error(failures.map((failure) => `- ${failure}`).join('\n'));
  process.exitCode = 1;
} else {
  console.log('Localization contract passed.');
}
