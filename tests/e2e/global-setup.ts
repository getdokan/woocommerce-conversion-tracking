import { mkdirSync, writeFileSync } from 'fs';
import { join } from 'path';
import { BASE_URL } from './utils/site';

export default async function globalSetup(): Promise<void> {
    // Empty the mapped debug.log so it only holds this run's PHP notices.
    const logDir = join(__dirname, 'wp-data');

    mkdirSync(logDir, { recursive: true });
    writeFileSync(join(logDir, 'debug.log'), '');

    const response = await fetch(BASE_URL).catch(() => null);

    if (!response?.ok) {
        throw new Error(`The e2e site is not up at ${BASE_URL}. Run "npm run env:start" first.`);
    }
}
