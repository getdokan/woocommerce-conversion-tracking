<?php

namespace StorePulse\ConversionTracking\REST;

/**
 * Registers the wcct/v1 controllers.
 */
class Routes {

    /**
     * Hook the registration.
     *
     * @return void
     */
    public function register() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register every controller's routes.
     *
     * @return void
     */
    public function register_routes() {
        /**
         * Filter the wcct/v1 controllers; each must extend the base Controller.
         *
         * @since 3.0.0
         *
         * @param string[] $controllers Class names.
         */
        $controllers = apply_filters(
            'wcct_rest_controllers',
            array(
                SettingsController::class,
                OnboardingController::class,
                BackupController::class,
                CatalogController::class,
                AccessController::class,
                DoctorController::class,
            )
        );

        foreach ( (array) $controllers as $controller ) {
            // The base class holds the permission check, so nothing else is registered.
            if ( is_string( $controller ) && is_subclass_of( $controller, Controller::class ) ) {
                ( new $controller() )->register_routes();
            }
        }
    }
}
