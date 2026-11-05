/**
 * External dependencies
 */
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
    </ThemeProvider>
);

export default App;
