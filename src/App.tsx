/**
 * External dependencies
 */
import { Toaster } from '@wedevs/plugin-ui';
import { ThemeProvider } from '@wcct/components';

/**
 * Internal dependencies
 */
import { AppDataProvider } from './data/AppData';
import { Shell } from './shell/Shell';

/**
 * The Conversion Tracking admin app.
 */
const App = () => (
    <ThemeProvider>
        <AppDataProvider>
            <Shell />
        </AppDataProvider>
        <Toaster position="bottom-center" />
    </ThemeProvider>
);

export default App;
