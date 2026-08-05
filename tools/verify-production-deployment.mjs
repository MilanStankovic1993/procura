import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const nginxPath = join(repositoryRoot, 'deploy', 'nginx', 'procura.conf.example');
const supervisorPath = join(repositoryRoot, 'deploy', 'supervisor', 'procura.conf.example');
const environmentPath = join(repositoryRoot, 'deploy', 'env', 'procura.production.env.example');
const schedulePath = join(repositoryRoot, 'routes', 'console.php');
const failures = [];

const requireCondition = (condition, message) => {
  if (!condition) {
    failures.push(message);
  }
};

const readRequired = (path, label) => {
  requireCondition(existsSync(path), `${label} is missing.`);

  return existsSync(path) ? readFileSync(path, 'utf8') : '';
};

const parseEnvironment = (source) => {
  const values = new Map();

  for (const rawLine of source.split(/\r?\n/)) {
    const line = rawLine.trim();

    if (line === '' || line.startsWith('#')) {
      continue;
    }

    const separator = line.indexOf('=');
    requireCondition(separator > 0, `Invalid production environment line: ${line}`);

    if (separator <= 0) {
      continue;
    }

    const key = line.slice(0, separator);
    requireCondition(/^[A-Z][A-Z0-9_]*$/.test(key), `Invalid production environment key: ${key}`);
    requireCondition(!values.has(key), `Duplicate production environment key: ${key}`);
    values.set(key, line.slice(separator + 1));
  }

  return values;
};

const parseSupervisorPrograms = (source) => {
  const programs = new Map();
  let current = null;

  for (const rawLine of source.split(/\r?\n/)) {
    const line = rawLine.trim();
    const heading = line.match(/^\[program:([a-z0-9-]+)]$/);

    if (heading) {
      current = new Map();
      programs.set(heading[1], current);
      continue;
    }

    if (current === null || line === '' || line.startsWith('#')) {
      continue;
    }

    const separator = line.indexOf('=');

    if (separator > 0) {
      current.set(line.slice(0, separator), line.slice(separator + 1));
    }
  }

  return programs;
};

const commandOption = (command, option) => {
  const match = command.match(new RegExp(`(?:^|\\s)--${option}=([^\\s]+)`));

  return match?.[1] ?? null;
};

const nginx = readRequired(nginxPath, 'The nginx production configuration');
const supervisor = readRequired(supervisorPath, 'The Supervisor production configuration');
const environment = parseEnvironment(
  readRequired(environmentPath, 'The production environment template'),
);
const schedule = readRequired(schedulePath, 'The Laravel scheduler definition');

for (const header of [
  'Strict-Transport-Security',
  'X-Content-Type-Options',
  'Referrer-Policy',
  'X-Frame-Options',
  'Permissions-Policy',
]) {
  requireCondition(
    new RegExp(`add_header\\s+${header}\\s+"[^"]+"\\s+always;`).test(nginx),
    `The nginx production boundary must emit ${header} on every response.`,
  );
}

requireCondition(
  nginx.includes('ssl_protocols TLSv1.2 TLSv1.3;'),
  'The nginx production boundary must allow only TLS 1.2 and TLS 1.3.',
);
requireCondition(
  nginx.includes('server_tokens off;'),
  'The nginx production boundary must hide its version.',
);
requireCondition(
  nginx.includes('fastcgi_param HTTP_PROXY "";'),
  'The nginx production boundary must clear the HTTP_PROXY request value.',
);

const expectedPrograms = new Map([
  ['procura-analysis-worker', { queues: 'analyses,default', timeout: 60, tries: 3 }],
  ['procura-connector-worker', { queues: 'connectors', timeout: 900, tries: 4 }],
  ['procura-notification-worker', { queues: 'notifications', timeout: 30, tries: 4 }],
]);
const programs = parseSupervisorPrograms(supervisor);

requireCondition(
  programs.size === expectedPrograms.size,
  'Supervisor must define exactly the reviewed Procura worker pools.',
);

for (const [name, expected] of expectedPrograms) {
  const program = programs.get(name);
  requireCondition(program !== undefined, `Supervisor program ${name} is missing.`);

  if (program === undefined) {
    continue;
  }

  const command = program.get('command') ?? '';
  const timeout = Number(commandOption(command, 'timeout'));
  const stopWaitSeconds = Number(program.get('stopwaitsecs'));

  requireCondition(command.includes('artisan queue:work'), `${name} must run Laravel queue:work.`);
  requireCondition(commandOption(command, 'queue') === expected.queues, `${name} owns unexpected queues.`);
  requireCondition(timeout === expected.timeout, `${name} has an unexpected worker timeout.`);
  requireCondition(Number(commandOption(command, 'tries')) === expected.tries, `${name} has unexpected attempts.`);
  requireCondition(Number(commandOption(command, 'max-time')) === 3600, `${name} must recycle hourly.`);
  requireCondition(stopWaitSeconds > timeout, `${name} must allow active jobs to stop cleanly.`);
  requireCondition(Number(program.get('numprocs')) >= 1, `${name} must start at least one process.`);
  requireCondition(program.get('autostart') === 'true', `${name} must start automatically.`);
  requireCondition(program.get('autorestart') === 'true', `${name} must restart automatically.`);
  requireCondition(program.get('stopasgroup') === 'true', `${name} must stop its process group.`);
  requireCondition(program.get('killasgroup') === 'true', `${name} must kill its process group.`);
}

const retryAfter = Number(environment.get('REDIS_QUEUE_RETRY_AFTER'));
const longestWorkerTimeout = Math.max(
  ...[...expectedPrograms.values()].map(({ timeout }) => timeout),
);
requireCondition(
  Number.isInteger(retryAfter) && retryAfter > longestWorkerTimeout,
  'REDIS_QUEUE_RETRY_AFTER must exceed the longest production worker timeout.',
);
requireCondition(
  environment.get('OPERATIONS_QUEUE_HEARTBEAT_QUEUES') === 'analyses,connectors,notifications,default',
  'The production heartbeat queues must exactly cover every worker queue.',
);
requireCondition(
  environment.get('ANALYSIS_SUBMISSION_ENABLED') === 'false',
  'The production template must fail closed for analysis submission.',
);
requireCondition(
  environment.get('APP_DEBUG') === 'false' && environment.get('APP_ENV') === 'production',
  'The production template must disable debug rendering in production.',
);
requireCondition(
  environment.get('DB_TIMEZONE') === '+00:00',
  'The production database session must use UTC.',
);
requireCondition(
  environment.get('AWS_THROW') === 'true',
  'Production object storage must fail loudly.',
);

for (const command of [
  'analyses:dispatch-pending',
  'marketplace-imports:dispatch-pending',
  'notifications:recover-email-deliveries',
  'notifications:recover-telegram-deliveries',
  'notifications:expire-telegram-connections',
  'operations:dispatch-queue-heartbeats',
  'broker-reports:purge-expired',
]) {
  requireCondition(schedule.includes(`Schedule::command('${command}`), `Scheduled recovery is missing: ${command}.`);
}

requireCondition(
  (schedule.match(/->withoutOverlapping\(\)/g) ?? []).length >= 7,
  'Every production recovery schedule must prevent overlapping execution.',
);
requireCondition(
  (schedule.match(/->onOneServer\(\)/g) ?? []).length >= 2,
  'Cluster-wide heartbeat and retention schedules must run on one server.',
);

if (failures.length > 0) {
  for (const failure of failures) {
    console.error(`FAIL: ${failure}`);
  }

  process.exitCode = 1;
} else {
  console.log('Production environment, nginx, Supervisor, queue, and scheduler contracts verified.');
}
